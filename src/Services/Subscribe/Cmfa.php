<?php

declare(strict_types=1);

namespace App\Services\Subscribe;

use function yaml_emit;

use const YAML_UTF8_ENCODING;

final class Cmfa extends Base
{
    public function getContent($user): string
    {
        // Reuse the node serializer and its existing per-user permission filtering.
        $nodes = (new Clash())->getConfig($user)['proxies'];

        return yaml_emit($this->buildConfig($nodes), YAML_UTF8_ENCODING);
    }

    public function buildConfig(array $nodes): array
    {
        $config = require BASE_PATH . '/config/cmfa.php';
        $config['proxies'] = $nodes;

        return $config;
    }
}
