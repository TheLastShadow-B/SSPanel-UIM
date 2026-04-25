<?php

declare(strict_types=1);

namespace App\Services\Subscribe;

use App\Services\Subscribe;
use App\Utils\Tools;
use function implode;
use function in_array;
use function json_decode;
use function mb_strpos;

final class Surge extends Base
{
    /**
     * Surge 5 supported Shadowsocks ciphers.
     * Nodes with methods outside this list are skipped to prevent Surge rejecting the whole config.
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
     * Region keyword priority order — first match wins when a node name hits multiple regions.
     * Each entry: region code => array of case-sensitive keywords.
     */
    private const REGION_KEYWORDS = [
        'HK' => ['HK', '香港', '🇭🇰'],
        'JP' => ['JP', '日本', '🇯🇵'],
        'US' => ['US', '美国', '🇺🇸'],
        'TW' => ['TW', '台湾', '🇹🇼'],
    ];

    /**
     * Apple → DIRECT (Apple has CN CDN; proxying is unnecessary).
     */
    private const APPLE_DIRECT_RULE_SETS = [
        'DOMAIN-SET,https://rule.sets.zero.ac.cn/9d5d81968c7b4b7ee09dab52051c37d7deb4e10a7015eb8e4140c820bee025f6/surge_apple_cdn_set,DIRECT,extended-matching',
        'RULE-SET,https://rule.sets.zero.ac.cn/9d5d81968c7b4b7ee09dab52051c37d7deb4e10a7015eb8e4140c820bee025f6/surge_apple_services,DIRECT,extended-matching',
    ];

    /**
     * Microsoft → Microsoft & Apple group (user-selectable proxy).
     */
    private const MICROSOFT_RULE_SETS = [
        'RULE-SET,https://rule.sets.zero.ac.cn/9d5d81968c7b4b7ee09dab52051c37d7deb4e10a7015eb8e4140c820bee025f6/surge_microsoft_services,Microsoft & Apple,extended-matching',
    ];

    /**
     * Foreign streaming / entertainment → Stream group.
     */
    private const STREAM_RULE_SETS = [
        'RULE-SET,https://cdn.jsdelivr.net/gh/blackmatrix7/ios_rule_script@master/rule/Surge/GlobalMedia/GlobalMedia.list,Stream,extended-matching',
    ];

    /**
     * Steam download CDN → Steam Download group.
     */
    private const STEAM_DOWNLOAD_RULE_SETS = [
        'RULE-SET,https://cdn.jsdelivr.net/gh/blackmatrix7/ios_rule_script@master/rule/Surge/SteamCN/SteamCN.list,Steam Download,extended-matching',
    ];

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

        return implode(', ', [
            'ss',
            $node_raw->server,
            (string) $port,
            'encrypt-method=' . $method,
            'password=' . $password,
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

        return implode(', ', $parts);
    }

    /**
     * Classify node names into regional buckets. Priority: HK > JP > US > TW, first-match-wins.
     *
     * @param list<string> $names
     * @return array<string, list<string>>
     */
    private function classifyNodesByRegion(array $names): array
    {
        $regions = ['HK' => [], 'JP' => [], 'US' => [], 'TW' => []];

        foreach ($names as $name) {
            foreach (self::REGION_KEYWORDS as $region => $keywords) {
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
     * Build [Proxy Group] lines with region grouping, priority fallbacks, and empty-group handling.
     *
     * @param array<string, list<string>> $regions
     * @return list<string>
     */
    private function buildProxyGroups(array $regions): array
    {
        $lines = [];

        // Compute regions that actually have nodes — used by Default Routing and Global.
        $global_members = [];
        foreach (['HK', 'JP', 'US', 'TW'] as $region) {
            if ($regions[$region] !== []) {
                $global_members[] = $region;
            }
        }
        if ($global_members === []) {
            $global_members = ['DIRECT'];
        }

        // 1. Default Routing — top-level toggle, first in client UI.
        $lines[] = 'Default Routing = select, Global, ' . implode(', ', $global_members) . ', DIRECT';

        // 2. Global — pick any region directly.
        $lines[] = 'Global = select, ' . implode(', ', $global_members);

        // 3. Regional groups — url-test for auto latency selection.
        // Empty region falls back to a select-DIRECT placeholder so Surge never references an empty group.
        foreach (['HK', 'JP', 'US', 'TW'] as $region) {
            if ($regions[$region] === []) {
                $lines[] = $region . ' = select, DIRECT';
            } else {
                $lines[] = $region . ' = url-test, ' . implode(', ', $regions[$region])
                    . ', url=http://www.gstatic.com/generate_204, interval=300, tolerance=50';
            }
        }

        // 4. Microsoft & Apple — Apple goes DIRECT via rule-set; this group only catches Microsoft now.
        $lines[] = 'Microsoft & Apple = select, Default Routing, ' . implode(', ', $global_members) . ', DIRECT';

        // 5. Stream — foreign streaming services.
        $lines[] = 'Stream = select, Default Routing, ' . implode(', ', $global_members) . ', DIRECT';

        // 6. Steam Download — large CDN downloads, DIRECT often fastest in CN.
        $lines[] = 'Steam Download = select, DIRECT, Default Routing, ' . implode(', ', $global_members);

        // 7. AI Services — US + JP only, with empty-side fallback.
        $ai_members = [];
        if ($regions['US'] !== []) {
            $ai_members[] = 'US';
        }
        if ($regions['JP'] !== []) {
            $ai_members[] = 'JP';
        }
        if ($ai_members === []) {
            $ai_members = ['DIRECT'];
        }
        $lines[] = 'AI Services = select, ' . implode(', ', $ai_members);

        return $lines;
    }

    /**
     * Build [Rule] lines. Uses Surge-native RULE-SET / DOMAIN-SET remote subscriptions.
     *
     * @return list<string>
     */
    private function buildRules(): array
    {
        $lines = [];

        // Specific overrides first.
        $lines[] = 'DOMAIN-SUFFIX,wifiman.com,Default Routing';

        // Apple → DIRECT (Apple's CN CDN works without proxy).
        foreach (self::APPLE_DIRECT_RULE_SETS as $rule) {
            $lines[] = $rule;
        }

        // Microsoft → Microsoft & Apple group.
        foreach (self::MICROSOFT_RULE_SETS as $rule) {
            $lines[] = $rule;
        }

        // Foreign streaming → Stream group.
        foreach (self::STREAM_RULE_SETS as $rule) {
            $lines[] = $rule;
        }

        // Steam download CDN → Steam Download group.
        foreach (self::STEAM_DOWNLOAD_RULE_SETS as $rule) {
            $lines[] = $rule;
        }

        // AI Services RULE-SET URL is pending user confirmation — placeholder comment.
        $lines[] = '# AI Services RULE-SET URL pending — routes AI traffic via AI Services group when added';

        // Reverse DNS infrastructure (PTR queries) — direct.
        $lines[] = 'DOMAIN-SUFFIX,arpa,DIRECT';

        // Private / loopback / link-local IPs (Surge built-in LAN ruleset).
        $lines[] = 'RULE-SET,LAN,DIRECT';

        // CN IP fallback.
        $lines[] = 'GEOIP,CN,DIRECT,no-resolve';

        // Catch-all; dns-failed catches unresolvable domains and proxies them.
        $lines[] = 'FINAL,Default Routing,dns-failed';

        return $lines;
    }

    /**
     * Minimal [General] defaults. Extracted into its own method so admin-side customization
     * (e.g., reading $_ENV['Surge_Config']) can be added later without touching other sections.
     *
     * @return list<string>
     */
    private function buildGeneral(): array
    {
        return [
            // DNS
            'dns-server = system, 223.5.5.5, 119.29.29.29',
            'encrypted-dns-server = https://1.1.1.1/dns-query',
            'hijack-dns = 8.8.8.8:53, 8.8.4.4:53',

            // Domains that must resolve to real IPs (gaming / STUN / captive portal).
            'always-real-ip = *.lan, *.local, *.msftncsi.com, *.msftconnecttest.com, *.srv.nintendo.net, *.stun.playstation.net, *.xboxlive.com, *.battle.net, *.battlenet.com, *.battlenet.com.cn, *.blzstatic.cn, stun.cloudflare.com, stun.miwifi.com, turn.cloudflare.com, xbox.*.microsoft.com',

            // System-level bypass (Surge does not see this traffic).
            'skip-proxy = 127.0.0.1, 192.168.0.0/16, 10.0.0.0/8, 172.16.0.0/12, 100.64.0.0/10, 169.254.0.0/16, 224.0.0.0/4, localhost, *.local',
            'exclude-simple-hostnames = true',

            // Connectivity tests.
            'internet-test-url = http://www.apple.com/library/test/success.html',
            'proxy-test-url = http://cp.cloudflare.com/generate_204',
            'proxy-test-udp = apple.com@172.64.36.1',
            'test-timeout = 5',

            // Network features.
            'udp-priority = true',
            'ipv6 = true',
            'ipv6-vif = auto',
            'auto-suspend = false',

            // iOS Surge 5 specific.
            'compatibility-mode = 5',
            'hybrid = true',

            // Misc.
            'allow-wifi-access = false',
            'loglevel = notify',
        ];
    }
}
