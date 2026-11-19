<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use function array_filter;
use function array_is_list;
use function array_map;
use function array_values;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * 一个中转节点的多个入口(custom_config.entries = [{label, server, port?}, ...])。
 *
 * 订阅里每个入口展开成一条:server 换成该入口,offset_port_user 换成该入口端口
 * (缺省保留原值),名字加「 · 线路<label>」。展开项是内存里的 clone,不会写回数据库;
 * 没有 entries、或 entries 里没有一项可用的节点原样输出。
 */
final class NodeEntries
{
    public static function expand(Collection $nodes): Collection
    {
        return $nodes->flatMap(static fn ($node): array => self::expandNode($node))->values();
    }

    private static function expandNode($node): array
    {
        $config = json_decode((string) $node->custom_config, true);
        $entries = self::entriesOf($config);

        if ($entries === null) {
            return [$node];
        }

        unset($config['entries']);
        $usable = array_values(array_filter($entries, static fn ($entry): bool => self::isUsable($entry)));
        $expanded = array_map(static fn (array $entry) => self::cloneFor($node, $config, $entry), $usable);

        return $expanded === [] ? [$node] : $expanded;
    }

    private static function entriesOf(mixed $config): ?array
    {
        $entries = is_array($config) ? ($config['entries'] ?? null) : null;

        return is_array($entries) && array_is_list($entries) ? $entries : null;
    }

    private static function isUsable(mixed $entry): bool
    {
        return is_array($entry)
            && self::isFilled($entry['label'] ?? null)
            && self::isFilled($entry['server'] ?? null);
    }

    private static function isFilled(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private static function cloneFor($node, array $config, array $entry)
    {
        $port = $entry['port'] ?? null;

        if (self::isFilled($port) || is_int($port)) {
            $config['offset_port_user'] = (string) $port;
        }

        $clone = clone $node;
        $clone->name = $node->name . ' · 线路' . $entry['label'];
        $clone->server = $entry['server'];
        $clone->custom_config = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $clone;
    }
}
