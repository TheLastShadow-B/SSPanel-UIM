<?php

declare(strict_types=1);

namespace App\Services\Subscribe;

use function ctype_digit;
use function is_array;
use function is_int;
use function is_string;
use function trim;

/**
 * One item of a node's custom_config.entries: a relay in front of the node.
 * See NodeEntries for what a subscription does with it.
 */
final class NodeEntry
{
    private const MAX_PORT = 65535;

    private function __construct(
        public readonly string $label,
        public readonly string $server,
        public readonly int|string|null $port,
    ) {
    }

    /**
     * Reads {"label": ..., "server": ..., "port": ...}. The port is optional and
     * may be a number or a string of digits; null means the node's own port.
     *
     * Returns null for anything else. A port that is filled in but wrong makes
     * the whole entry unusable: falling back to the node's own port would
     * publish a line that points at the wrong service.
     */
    public static function parse(mixed $item): ?self
    {
        $item = is_array($item) ? $item : [];
        $label = self::text($item['label'] ?? null);
        $server = self::text($item['server'] ?? null);
        $port = self::port($item['port'] ?? '');

        if ($label === '' || $server === '' || $port === false) {
            return null;
        }

        return new self($label, $server, $port);
    }

    /**
     * Null for a port left out or empty, false for one that is wrong.
     */
    private static function port(mixed $value): int|string|false|null
    {
        if ($value === '') {
            return null;
        }

        return self::isPort($value) ? $value : false;
    }

    private static function text(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim($value) : '';
    }

    private static function isPort(mixed $value): bool
    {
        $number = is_string($value) && ctype_digit($value) ? (int) $value : $value;

        return is_int($number) && $number >= 1 && $number <= self::MAX_PORT;
    }
}
