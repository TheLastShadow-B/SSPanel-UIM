<?php

declare(strict_types=1);

namespace App\Services\Subscribe;

use App\Services\Subscribe;
use App\Utils\Tools;
use function array_key_exists;
use function array_keys;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function mb_strpos;
use function str_starts_with;
use function substr;
use function trim;

final class Surge extends Base
{
    /**
     * Surge 5 supported Shadowsocks ciphers.
     * Nodes with methods outside this list are skipped to prevent Surge rejecting the whole config.
     * This is a Surge capability constraint, not routing policy, so it stays in code.
     */
    private const SS_CIPHER_WHITELIST = [
        'aes-128-gcm',
        'aes-192-gcm',
        'aes-256-gcm',
        'chacha20-ietf-poly1305',
        '2022-blake3-aes-128-gcm',
        '2022-blake3-aes-256-gcm',
    ];

    /**
     * Default region order used when a group cites the 'REGIONS' placeholder without an explicit list.
     */
    private const DEFAULT_REGION_ORDER = ['HK', 'US', 'JP', 'TW'];

    public function getContent($user): string
    {
        $nodes_raw = Subscribe::getUserNodes($user);

        [$proxy_lines, $node_names] = $this->buildProxies($user, $nodes_raw);
        $regions = $this->classifyNodesByRegion($node_names);
        $proxy_group_lines = $this->buildProxyGroups($regions);
        $rule_lines = $this->buildRules();
        $general_lines = $this->buildGeneral();

        $sections = [];
        $sections[] = '#!MANAGED-CONFIG ' . Subscribe::getUniversalSubLink($user) . '/surge interval=43200 strict=true';
        $sections[] = '';
        $sections[] = '[General]';
        $sections[] = implode("\n", $general_lines);
        $sections[] = '';
        $sections[] = '[Proxy]';
        $sections[] = implode("\n", $proxy_lines);
        $sections[] = '';
        $sections[] = '[Proxy Group]';
        $sections[] = implode("\n", $proxy_group_lines);
        $sections[] = '';
        $sections[] = '[Rule]';
        $sections[] = implode("\n", $rule_lines);
        $sections[] = '';

        return implode("\n", $sections);
    }

    /**
     * Build Surge [Proxy] section lines.
     *
     * @return array{0: list<string>, 1: list<string>} [proxy_lines, node_names_in_order]
     */
    private function buildProxies($user, $nodes_raw): array
    {
        $lines = [];
        $names = [];

        foreach ($nodes_raw as $node_raw) {
            $node_custom_config = json_decode((string) $node_raw->custom_config, true) ?? [];
            $line = null;

            switch ((int) $node_raw->sort) {
                case 0:
                    $line = $this->buildShadowsocksLine($user, $node_raw, $node_custom_config);
                    break;
                case 1:
                    $line = $this->buildShadowsocks2022Line($user, $node_raw, $node_custom_config);
                    break;
                case 11:
                    $line = $this->buildVmessLine($user, $node_raw, $node_custom_config);
                    break;
                case 14:
                    $line = $this->buildTrojanLine($user, $node_raw, $node_custom_config);
                    break;
                default:
                    // sort=2 (TUIC), sort=3 (WireGuard), and any other value — not supported by Surge
                    $line = null;
            }

            if ($line === null) {
                continue;
            }

            $lines[] = $node_raw->name . ' = ' . $line;
            $names[] = $node_raw->name;
        }

        return [$lines, $names];
    }

    private function buildShadowsocksLine($user, $node_raw, array $custom): ?string
    {
        if (! in_array($user->method, self::SS_CIPHER_WHITELIST, true)) {
            return null;
        }

        $parts = [
            'ss',
            $node_raw->server,
            (string) $user->port,
            'encrypt-method=' . $user->method,
            'password=' . $user->passwd,
        ];

        $plugin = $custom['plugin'] ?? '';
        $plugin_option = $custom['plugin_option'] ?? $custom['plugin-opts'] ?? null;

        if ($plugin === 'obfs' || $plugin === 'obfs-local' || $plugin === 'simple-obfs') {
            $obfs_mode = null;
            $obfs_host = null;

            if (is_array($plugin_option)) {
                $obfs_mode = $plugin_option['mode'] ?? null;
                $obfs_host = $plugin_option['host'] ?? null;
            } elseif (is_string($plugin_option) && $plugin_option !== '') {
                // parse "obfs=http;obfs-host=example.com" style string
                foreach (explode(';', $plugin_option) as $pair) {
                    [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                    if ($k === 'obfs') {
                        $obfs_mode = $v;
                    } elseif ($k === 'obfs-host') {
                        $obfs_host = $v;
                    }
                }
            }

            if ($obfs_mode !== null && ($obfs_mode === 'http' || $obfs_mode === 'tls')) {
                $parts[] = 'obfs=' . $obfs_mode;
                if ($obfs_host !== null && $obfs_host !== '') {
                    $parts[] = 'obfs-host=' . $obfs_host;
                }
            } else {
                // obfs is required but mode is unsupported — skip to avoid broken node
                return null;
            }
        }

        $udp = (bool) ($custom['udp'] ?? true);
        $parts[] = 'udp-relay=' . ($udp ? 'true' : 'false');

        return implode(', ', $parts);
    }

    private function buildShadowsocks2022Line($user, $node_raw, array $custom): ?string
    {
        $method = $custom['method'] ?? '2022-blake3-aes-128-gcm';

        if (! in_array($method, self::SS_CIPHER_WHITELIST, true)) {
            return null;
        }

        $user_pk = Tools::genSs2022UserPk($user->passwd, $method);
        if (! $user_pk) {
            return null;
        }

        $port = $custom['offset_port_user'] ?? $custom['offset_port_node'] ?? 443;
        $server_key = $custom['server_key'] ?? '';
        $password = $server_key === '' ? $user_pk : $server_key . ':' . $user_pk;
        $udp = (bool) ($custom['udp'] ?? true);

        return implode(', ', [
            'ss',
            $node_raw->server,
            (string) $port,
            'encrypt-method=' . $method,
            'password=' . $password,
            'udp-relay=' . ($udp ? 'true' : 'false'),
        ]);
    }

    private function buildVmessLine($user, $node_raw, array $custom): ?string
    {
        $network = $custom['network'] ?? 'tcp';

        // Surge vmess supports only tcp and ws; grpc/h2 are not native.
        if ($network === 'grpc' || $network === 'h2' || $network === 'http') {
            return null;
        }

        if ($network === 'httpupgrade') {
            $network = 'ws';
        }

        if ($network !== 'tcp' && $network !== 'ws') {
            return null;
        }

        $port = $custom['offset_port_user'] ?? $custom['offset_port_node'] ?? 443;
        $security = $custom['security'] ?? 'none';
        $tls = $security === 'tls';
        $allow_insecure = (bool) ($custom['allow_insecure'] ?? false);

        // ws-headers Host extraction — prefer nested ws-opts.headers.Host, fall back to root fields
        $ws_headers_host = '';
        $ws_opts = $custom['ws-opts'] ?? $custom['ws_opts'] ?? null;
        if (is_array($ws_opts) && isset($ws_opts['headers']['Host'])) {
            $ws_headers_host = (string) $ws_opts['headers']['Host'];
        } elseif (isset($custom['header']['request']['headers']['Host'][0])) {
            $ws_headers_host = (string) $custom['header']['request']['headers']['Host'][0];
        } elseif (isset($custom['host'])) {
            $ws_headers_host = (string) $custom['host'];
        }

        $ws_path = '';
        if (is_array($ws_opts) && isset($ws_opts['path'])) {
            $ws_path = (string) $ws_opts['path'];
        } elseif (isset($custom['path'])) {
            $ws_path = (string) $custom['path'];
        }

        $sni = $custom['sni'] ?? $ws_headers_host ?? '';

        $parts = [
            'vmess',
            $node_raw->server,
            (string) $port,
            'username=' . $user->uuid,
            'vmess-aead=true',
        ];

        if ($network === 'ws') {
            $parts[] = 'ws=true';
            if ($ws_path !== '') {
                $parts[] = 'ws-path=' . $ws_path;
            }
            if ($ws_headers_host !== '') {
                $parts[] = 'ws-headers=Host:' . $ws_headers_host;
            }
        } else {
            $parts[] = 'ws=false';
        }

        $parts[] = 'tls=' . ($tls ? 'true' : 'false');
        if ($tls) {
            if ($sni !== '') {
                $parts[] = 'sni=' . $sni;
            }
            $parts[] = 'skip-cert-verify=' . ($allow_insecure ? 'true' : 'false');
        }

        // Surge vmess does not support UDP relay — do not emit udp-relay here.
        return implode(', ', $parts);
    }

    private function buildTrojanLine($user, $node_raw, array $custom): ?string
    {
        $network = $custom['header']['type'] ?? $custom['network'] ?? 'tcp';

        if ($network === 'grpc' || $network === 'h2') {
            return null;
        }
        if ($network === 'httpupgrade') {
            $network = 'ws';
        }
        if ($network !== 'tcp' && $network !== 'ws') {
            return null;
        }

        $port = $custom['offset_port_user'] ?? $custom['offset_port_node'] ?? 443;
        $sni = $custom['host'] ?? $custom['sni'] ?? '';
        $allow_insecure = (bool) ($custom['allow_insecure'] ?? false);

        $parts = [
            'trojan',
            $node_raw->server,
            (string) $port,
            'password=' . $user->uuid,
        ];

        if ($sni !== '') {
            $parts[] = 'sni=' . $sni;
        }
        $parts[] = 'skip-cert-verify=' . ($allow_insecure ? 'true' : 'false');

        if ($network === 'ws') {
            $parts[] = 'ws=true';
            $ws_opts = $custom['ws-opts'] ?? $custom['ws_opts'] ?? null;
            if (is_array($ws_opts) && isset($ws_opts['path'])) {
                $parts[] = 'ws-path=' . $ws_opts['path'];
            }
            if (is_array($ws_opts) && isset($ws_opts['headers']['Host'])) {
                $parts[] = 'ws-headers=Host:' . $ws_opts['headers']['Host'];
            }
        }

        $udp = (bool) ($custom['udp'] ?? true);
        $parts[] = 'udp-relay=' . ($udp ? 'true' : 'false');

        return implode(', ', $parts);
    }

    /**
     * Classify node names into regional buckets, keyed and prioritised by the
     * $_ENV['Surge_Region_Keywords'] template map (first-match-wins).
     *
     * @param list<string> $names
     * @return array<string, list<string>>
     */
    private function classifyNodesByRegion(array $names): array
    {
        $keyword_map = $_ENV['Surge_Region_Keywords'] ?? [];

        $regions = [];
        foreach (array_keys($keyword_map) as $region) {
            $regions[$region] = [];
        }

        foreach ($names as $name) {
            foreach ($keyword_map as $region => $keywords) {
                foreach ($keywords as $keyword) {
                    if (mb_strpos($name, $keyword, 0, 'UTF-8') !== false) {
                        $regions[$region][] = $name;
                        continue 3;
                    }
                }
            }
        }

        return $regions;
    }

    /**
     * Build [Proxy Group] lines from the $_ENV['Surge_Group_Config'] template.
     * Member placeholders (REGION:X, REGIONS, REGIONS:a,b) are expanded from the
     * user's classified nodes; empty groups fall back to DIRECT so Surge never
     * references an empty group.
     *
     * @param array<string, list<string>> $regions
     * @return list<string>
     */
    private function buildProxyGroups(array $regions): array
    {
        $group_config = $_ENV['Surge_Group_Config'] ?? [];

        $lines = [];
        foreach ($group_config as $group) {
            $name = $group['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $type = $group['type'] ?? 'select';
            $members = $this->expandGroupMembers($group['proxies'] ?? [], $regions);
            if ($members === []) {
                $members = ['DIRECT'];
            }
            $lines[] = $name . ' = ' . $type . ', ' . implode(', ', $members);
        }

        return $lines;
    }

    /**
     * Expand region placeholders in a group's member list:
     *   'REGION:HK'     -> node names classified as HK, or ['DIRECT'] if none
     *   'REGIONS'       -> region names that have nodes, in DEFAULT_REGION_ORDER
     *   'REGIONS:US,JP' -> region names that have nodes, limited to/ordered by the list
     * Any other member is passed through verbatim.
     *
     * @param list<string> $members
     * @param array<string, list<string>> $regions
     * @return list<string>
     */
    private function expandGroupMembers(array $members, array $regions): array
    {
        $out = [];

        foreach ($members as $member) {
            if ($member === 'REGIONS' || str_starts_with($member, 'REGIONS:')) {
                $order = $member === 'REGIONS'
                    ? self::DEFAULT_REGION_ORDER
                    : explode(',', substr($member, 8));
                $with_nodes = [];
                foreach ($order as $region) {
                    $region = trim($region);
                    if (($regions[$region] ?? []) !== []) {
                        $with_nodes[] = $region;
                    }
                }
                foreach (($with_nodes === [] ? ['DIRECT'] : $with_nodes) as $entry) {
                    $out[] = $entry;
                }
            } elseif (str_starts_with($member, 'REGION:')) {
                $region = substr($member, 7);
                $nodes = array_key_exists($region, $regions) && $regions[$region] !== []
                    ? $regions[$region]
                    : ['DIRECT'];
                foreach ($nodes as $entry) {
                    $out[] = $entry;
                }
            } else {
                $out[] = $member;
            }
        }

        return $out;
    }

    /**
     * [Rule] lines, sourced verbatim from the $_ENV['Surge_Rules'] template.
     *
     * @return list<string>
     */
    private function buildRules(): array
    {
        return $_ENV['Surge_Rules'] ?? [];
    }

    /**
     * [General] lines, sourced verbatim from the $_ENV['Surge_General'] template.
     *
     * @return list<string>
     */
    private function buildGeneral(): array
    {
        return $_ENV['Surge_General'] ?? [];
    }
}
