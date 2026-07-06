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
     * Rule-set URLs for the Apple & MS group (Surge pulls these directly).
     * Kept self-hosted: Loyalsoldier/surge-rules only ships CN-accessible Apple subsets,
     * no full Apple/Microsoft lists.
     */
    private const APPLE_MS_RULE_SETS = [
        'DOMAIN-SET,https://nmslcf2.pages.dev/Rules/Clash/surge_apple_cdn_set,Apple & MS,extended-matching',
        'RULE-SET,https://nmslcf2.pages.dev/Rules/Clash/surge_apple_services,Apple & MS,extended-matching',
        'RULE-SET,https://nmslcf2.pages.dev/Rules/Clash/surge_microsoft_services,Apple & MS,extended-matching',
    ];

    /**
     * Loyalsoldier/surge-rules base URL (root files are DOMAIN-SET format), via jsDelivr mirror.
     */
    private const LOYALSOLDIER_BASE = 'https://fastly.jsdelivr.net/gh/Loyalsoldier/surge-rules@release/';

    /**
     * AI service rules — mirrors the AI Services block in the Clash profile (config/appprofile.php).
     */
    private const AI_RULES = [
        'DOMAIN,ai-gateway.vercel.sh,AI Services',
        'DOMAIN,api.github.com,AI Services',
        'DOMAIN,apple-relay.apple.com,AI Services',
        'DOMAIN,apple-relay.cloudflare.com,AI Services',
        'DOMAIN,apple-relay.fastly-edge.com,AI Services',
        'DOMAIN,cp4.cloudflare.com,AI Services',
        'DOMAIN,gateway.ai.cloudflare.com,AI Services',
        'DOMAIN,gateway.icloud.com,AI Services',
        'DOMAIN,gspe1-ssl.ls.apple.com,AI Services',
        'DOMAIN,guzzoni.apple.com,AI Services',
        'DOMAIN-KEYWORD,openai,AI Services',
        'DOMAIN-SUFFIX,ai.com,AI Services',
        'DOMAIN-SUFFIX,anthropic.com,AI Services',
        'DOMAIN-SUFFIX,cerebras.ai,AI Services',
        'DOMAIN-SUFFIX,chat.com,AI Services',
        'DOMAIN-SUFFIX,chatgpt.com,AI Services',
        'DOMAIN-SUFFIX,claude.ai,AI Services',
        'DOMAIN-SUFFIX,claude.com,AI Services',
        'DOMAIN-SUFFIX,clipdrop.co,AI Services',
        'DOMAIN-SUFFIX,dify.ai,AI Services',
        'DOMAIN-SUFFIX,grok.com,AI Services',
        'DOMAIN-SUFFIX,groq.com,AI Services',
        'DOMAIN-SUFFIX,jasper.ai,AI Services',
        'DOMAIN-SUFFIX,meta.ai,AI Services',
        'DOMAIN-SUFFIX,oaistatic.com,AI Services',
        'DOMAIN-SUFFIX,oaiusercontent.com,AI Services',
        'DOMAIN-SUFFIX,openart.ai,AI Services',
        'DOMAIN-SUFFIX,perplexity.ai,AI Services',
        'DOMAIN-SUFFIX,poe.com,AI Services',
        'DOMAIN-SUFFIX,smoot.apple.com,AI Services',
        'DOMAIN-SUFFIX,sora.com,AI Services',
        'DOMAIN-SUFFIX,x.ai,AI Services',
    ];

    /**
     * Securities broker rules — mirrors GEOSITE,futu/itiger/ibkr in the Clash profile.
     * Surge has no geosite support, so the domains are inlined from
     * v2fly/domain-list-community (data/futu, data/itiger, data/ibkr).
     */
    private const SECURITIES_RULES = [
        // futu
        'DOMAIN-SUFFIX,futu.cn,Securities',
        'DOMAIN-SUFFIX,futu.link,Securities',
        'DOMAIN-SUFFIX,futu5.com,Securities',
        'DOMAIN-SUFFIX,futuau.com,Securities',
        'DOMAIN-SUFFIX,futuesop.com,Securities',
        'DOMAIN-SUFFIX,futufin.com,Securities',
        'DOMAIN-SUFFIX,futuhk.com,Securities',
        'DOMAIN-SUFFIX,futuhk1.com,Securities',
        'DOMAIN-SUFFIX,futuhk2.com,Securities',
        'DOMAIN-SUFFIX,futuhkapp.com,Securities',
        'DOMAIN-SUFFIX,futuhn.com,Securities',
        'DOMAIN-SUFFIX,futuholdings.com,Securities',
        'DOMAIN-SUFFIX,futuniuniu.com,Securities',
        'DOMAIN-SUFFIX,futunn.com,Securities',
        'DOMAIN-SUFFIX,futuoa.com,Securities',
        'DOMAIN-SUFFIX,futusg.com,Securities',
        'DOMAIN-SUFFIX,futustatic.com,Securities',
        'DOMAIN-SUFFIX,fututrade.com,Securities',
        'DOMAIN-SUFFIX,moomoo.com,Securities',
        'DOMAIN-SUFFIX,moomooequity.com,Securities',
        'DOMAIN-SUFFIX,moomootrustee.com,Securities',
        // itiger
        'DOMAIN-SUFFIX,itiger.com,Securities',
        'DOMAIN-SUFFIX,itigergrowth.com,Securities',
        'DOMAIN-SUFFIX,itigergrowtha.com,Securities',
        'DOMAIN-SUFFIX,itigerup.com,Securities',
        'DOMAIN-SUFFIX,laohu8.com,Securities',
        'DOMAIN-SUFFIX,skytigris.cn,Securities',
        'DOMAIN-SUFFIX,tigerbbs.cn,Securities',
        'DOMAIN-SUFFIX,tigerbbs.com,Securities',
        'DOMAIN-SUFFIX,xiaohu8.com,Securities',
        // ibkr
        'DOMAIN-SUFFIX,ibkr.ca,Securities',
        'DOMAIN-SUFFIX,ibkr.co.in,Securities',
        'DOMAIN-SUFFIX,ibkr.co.uk,Securities',
        'DOMAIN-SUFFIX,ibkr.com,Securities',
        'DOMAIN-SUFFIX,ibkr.com.au,Securities',
        'DOMAIN-SUFFIX,ibkr.com.hk,Securities',
        'DOMAIN-SUFFIX,ibkr.com.sg,Securities',
        'DOMAIN-SUFFIX,ibkr.eu,Securities',
        'DOMAIN-SUFFIX,ibkr.ie,Securities',
        'DOMAIN-SUFFIX,ibkrguides.com,Securities',
        'DOMAIN-SUFFIX,ibllc.com,Securities',
        'DOMAIN-SUFFIX,interactivebrokers.ca,Securities',
        'DOMAIN-SUFFIX,interactivebrokers.co.in,Securities',
        'DOMAIN-SUFFIX,interactivebrokers.co.jp,Securities',
        'DOMAIN-SUFFIX,interactivebrokers.co.uk,Securities',
        'DOMAIN-SUFFIX,interactivebrokers.com,Securities',
        'DOMAIN-SUFFIX,interactivebrokers.com.au,Securities',
        'DOMAIN-SUFFIX,interactivebrokers.com.hk,Securities',
        'DOMAIN-SUFFIX,interactivebrokers.com.sg,Securities',
        'DOMAIN-SUFFIX,interactivebrokers.eu,Securities',
        'DOMAIN-SUFFIX,interactivebrokers.ie,Securities',
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
        // Regional groups — empty region falls back to DIRECT so Surge never references an empty group.
        $region_lines = [];
        foreach (['HK', 'JP', 'US', 'TW'] as $region) {
            $members = $regions[$region] === [] ? ['DIRECT'] : $regions[$region];
            $region_lines[] = $region . ' = select, ' . implode(', ', $members);
        }

        // Global — dynamic membership, only includes regions with at least one real node.
        $global_members = [];
        foreach (['HK', 'US', 'JP', 'TW'] as $region) {
            if ($regions[$region] !== []) {
                $global_members[] = $region;
            }
        }
        if ($global_members === []) {
            $global_members = ['DIRECT'];
        }

        // AI Services — US + JP only, with empty-side fallback.
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

        // Emit order: Default Routing first, Global second, then regional groups,
        // Apple & MS, AI Services, and Securities. Surge resolves references across
        // the whole section, so citing Global / regions defined further down is fine.
        // Regional groups are always emitted (empty regions fall back to DIRECT),
        // so Securities can safely reference them.
        $lines = [];
        $lines[] = 'Default Routing = select, Global, DIRECT, REJECT';
        $lines[] = 'Global = select, ' . implode(', ', $global_members);
        foreach ($region_lines as $region_line) {
            $lines[] = $region_line;
        }
        $lines[] = 'Apple & MS = select, Default Routing, Global, DIRECT';
        $lines[] = 'AI Services = select, ' . implode(', ', $ai_members);
        $lines[] = 'Securities = select, Default Routing, HK, US, JP, TW, DIRECT';

        return $lines;
    }

    /**
     * Build [Rule] lines. Mirrors the rule order of the Clash profile (config/appprofile.php),
     * with GEOSITE categories mapped to Loyalsoldier/surge-rules domain sets or inlined domains.
     *
     * @return list<string>
     */
    private function buildRules(): array
    {
        return [
            // LAN & system traffic (mirrors the local/arpa/private DIRECT rules).
            'RULE-SET,SYSTEM,DIRECT',
            'RULE-SET,LAN,DIRECT',

            // Ad blocking (mirrors RULE-SET,ad-reject).
            'DOMAIN-SET,' . self::LOYALSOLDIER_BASE . 'reject.txt,REJECT',

            // AI services (mirrors the inline AI block; must precede the Apple sets so
            // gateway.icloud.com etc. hit AI Services first).
            ...self::AI_RULES,

            'DOMAIN-SUFFIX,wifiman.com,Default Routing',

            // google.txt only holds Google domains reachable from mainland China; the rest
            // of Google falls through to FINAL, which is also Default Routing — combined
            // effect matches GEOSITE,google,Default Proxy in the Clash profile.
            'DOMAIN-SET,' . self::LOYALSOLDIER_BASE . 'google.txt,Default Routing',

            // Apple & Microsoft (self-hosted full lists; see APPLE_MS_RULE_SETS).
            ...self::APPLE_MS_RULE_SETS,

            // Securities brokers (mirrors GEOSITE,futu/itiger/ibkr).
            ...self::SECURITIES_RULES,

            // CN domains direct (mirrors GEOSITE,cn), then GEOIP bottom safety net for
            // CN domains missing from direct.txt.
            'DOMAIN-SET,' . self::LOYALSOLDIER_BASE . 'direct.txt,DIRECT',
            'GEOIP,CN,DIRECT',
            'FINAL,Default Routing,dns-failed',
        ];
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
            'encrypted-dns-server = https://doh.pub/dns-query',
            'hijack-dns = 8.8.8.8:53, 8.8.4.4:53',

            // Domains that must resolve to real IPs (gaming / STUN / captive portal).
            'always-real-ip = *.lan, *.local, *.msftncsi.com, *.msftconnecttest.com, *.srv.nintendo.net, *.stun.playstation.net, *.xboxlive.com, *.battle.net, *.battlenet.com, *.battlenet.com.cn, *.blzstatic.cn, stun.cloudflare.com, stun.miwifi.com, turn.cloudflare.com, xbox.*.microsoft.com, time.*.com, ntp.*.com, *.pool.ntp.org, *.ntp.org.cn, *.time.edu.cn, time1.cloud.tencent.com',

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

            // Misc.
            'allow-wifi-access = false',
            'loglevel = notify',
        ];
    }
}
