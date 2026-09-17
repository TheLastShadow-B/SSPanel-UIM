<?php

declare(strict_types=1);

namespace App\Services\Subscribe;

use function array_column;
use function array_filter;
use function array_merge;
use function array_values;
use function str_contains;
use function yaml_emit;

use const YAML_UTF8_ENCODING;

/** Clash for Apple (clash.md), with a separate memory-conscious profile. */
final class Hako extends Base
{
    public function getContent($user): string
    {
        // Reuse the existing node serializer and its per-user permission filtering.
        $nodes = (new Clash())->getConfig($user)['proxies'];

        return yaml_emit($this->buildConfig($nodes), YAML_UTF8_ENCODING);
    }

    public function buildConfig(array $nodes): array
    {
        $config = require BASE_PATH . '/config/hako.php';
        $config['proxies'] = $nodes;
        $names = array_column($nodes, 'name');

        foreach ($config['proxy-groups'] as &$group) {
            if (isset($group['filter'])) {
                $region = $group['filter'];
                $matches = array_values(array_filter(
                    $names,
                    static fn (string $name): bool => str_contains($name, $region)
                ));
                // Never silently turn an unavailable region into a direct exit.
                $group['proxies'] = $matches !== [] ? $matches : ['REJECT'];
                unset($group['filter']);
            } elseif ($group['name'] === 'Global') {
                // Nodes without a recognized region remain manually selectable.
                $group['proxies'] = array_merge($group['proxies'], $names);
            }
        }
        unset($group);

        return $config;
    }
}
