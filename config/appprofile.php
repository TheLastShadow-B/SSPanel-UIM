<?php

declare(strict_types=1);

require __DIR__ . '/clash-verge.php';

$_ENV['Stash_Config'] = [
    'mode' => 'rule',
    'log-level' => 'info',
    'dns' => [
        // 仅用于解析 DNS 服务器自身的域名。下面 nameserver 全是 IP，实际不会用到。
        'default-nameserver' => [
            '223.5.5.5',
            '114.114.114.114',
            'system',
        ],
        // effective-stash：DoH/DoT/DoQ 比传统 UDP 查询更消耗系统资源，延迟通常也更高。
        // 走代理的域名由 Fake IP 接管、域名直接透传给节点，根本不经过这里；真正需要
        // 本地查询的只有直连域名，用国内 UDP DNS 即可，配置国外 DNS 不会带来实际收益。
        'nameserver' => [
            '223.5.5.5',
            '119.29.29.29',
        ],
        // iOS 3.6+ / macOS 4.3+。节点 server 为域名时走独立解析链路，避免递归查询。
        'proxy-server-nameserver' => [
            '223.5.5.5',
            '119.29.29.29',
        ],
        'follow-rule' => false,
    ],
];

// MRS 规则集合由 zstd 压缩的紧凑字典树构成，Stash iOS 3.1.1+ 支持。官方《规则集合》
// 文档指出 domain / ipcidr 类型「针对大量数据进行了专门压缩优化，当规则条目较多时
// 建议优先选用」，故 Stash 侧的大体量规则一律走 MRS。
$stash_mrs = static fn (string $behavior, string $path): array => [
    'type' => 'http',
    'behavior' => $behavior,
    'format' => 'mrs',
    'url' => 'https://fastly.jsdelivr.net/gh/MetaCubeX/meta-rules-dat@meta/geo/' . $path,
    'interval' => 86400,
];

$_ENV['Stash_Group_Indexes'] = [];
$_ENV['Stash_Group_Config'] = [
    'proxy-groups' => [
        [
            'name' => 'Default Proxy',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'proxies' => [
                'Global',
                'DIRECT',
                'REJECT',
            ],
        ],
        [
            'name' => 'Global',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'proxies' => [
                'HK',
                'US',
                'JP',
                'TW',
            ],
        ],
        [
            'name' => 'JP',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'include-all' => true,
            'filter' => 'JP',
        ],
        [
            'name' => 'HK',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'include-all' => true,
            'filter' => 'HK',
        ],
        [
            'name' => 'US',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'include-all' => true,
            'filter' => 'US',
        ],
        [
            'name' => 'TW',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'include-all' => true,
            'filter' => 'TW',
        ],
        [
            'name' => 'AI Services',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'proxies' => [
                'JP',
                'US',
            ],
        ],
        [
            'name' => 'Microsoft & Apple',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'proxies' => [
                'Default Proxy',
                'HK',
                'JP',
                'US',
                'TW',
            ],
        ],
        [
            'name' => 'Stream',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'proxies' => [
                'Default Proxy',
                'HK',
                'JP',
                'US',
                'TW',
            ],
        ],
        [
            'name' => 'Securities',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'proxies' => [
                'Default Proxy',
                'HK',
                'US',
                'JP',
                'TW',
                'DIRECT',
            ],
        ],
        [
            'name' => 'Final Match',
            'type' => 'select',
            // 策略组未被使用时跳过 600s 的自动延迟测试，节省资源
            'lazy' => true,
            'proxies' => [
                'Default Proxy',
                'HK',
                'JP',
                'US',
                'TW',
                'DIRECT',
            ],
        ],
    ],
    'rule-providers' => [
        // ad-reject 原为 Loyalsoldier reject.txt：5,365,794 字节 / 186,607 条 yaml。
        // Stash 要先把 5.4 MB 文本读进内存、解析成 18 万个字符串再建树，瞬时峰值是
        // 文件本身的数倍，而 iOS 15+ 给 Network Extension 的预算只有 50 MB
        //（iOS 14 仅 15 MB）。换 MRS 后压缩态 8,135 字节、解压态 12,159 字节。
        'ad-reject' => $stash_mrs('domain', 'geosite/category-ads-all.mrs'),
        'lan-cidr' => $stash_mrs('ipcidr', 'geoip/private.mrs'),
        // 以下各项原先是内置 GEOSITE / GEOIP 规则。GEOSITE 依赖的
        // domain-list-community 数据不随 Stash 分发，首次使用时需从 github.com
        // 按需拉取——对国内用户是鸡生蛋：代理尚未建立时恰好拉不到，规则静默失效，
        // 流量直接落到 MATCH。改走 jsDelivr 上的 MRS 一并解决内存与可达性。
        'geosite-google' => $stash_mrs('domain', 'geosite/google.mrs'),
        'geosite-apple-cn' => $stash_mrs('domain', 'geosite/apple@cn.mrs'),
        'geosite-microsoft-cn' => $stash_mrs('domain', 'geosite/microsoft@cn.mrs'),
        'geosite-apple' => $stash_mrs('domain', 'geosite/apple.mrs'),
        'geosite-microsoft' => $stash_mrs('domain', 'geosite/microsoft.mrs'),
        'geosite-entertainment' => $stash_mrs('domain', 'geosite/category-entertainment.mrs'),
        'geosite-futu' => $stash_mrs('domain', 'geosite/futu.mrs'),
        'geosite-itiger' => $stash_mrs('domain', 'geosite/itiger.mrs'),
        'geosite-ibkr' => $stash_mrs('domain', 'geosite/ibkr.mrs'),
        // 不用 geosite/cn：111,030 条里有 104,976 条来自 felixonmars
        // accelerated-domains.china，入选标准是权威 DNS 在国内，IP 也几乎都在国内，
        // 下方 geoip-cn（未加 no-resolve）本就能兜住，只多一次国内 UDP 查询。
        // geolocation-cn + tld-cn 合计约 5,400 条，覆盖主流国内服务。
        'geosite-cn' => $stash_mrs('domain', 'geosite/geolocation-cn.mrs'),
        'geosite-tld-cn' => $stash_mrs('domain', 'geosite/tld-cn.mrs'),
        'geoip-cn' => $stash_mrs('ipcidr', 'geoip/cn.mrs'),
    ],
    'rules' => [
        'DOMAIN-SUFFIX,local,DIRECT',
        'DOMAIN-SUFFIX,arpa,DIRECT',
        'RULE-SET,lan-cidr,DIRECT,no-resolve',
        'RULE-SET,ad-reject,REJECT',
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
        'DOMAIN-SUFFIX,wifiman.com,Default Proxy',
        'RULE-SET,geosite-google,Default Proxy',
        // apple/microsoft 里混有中国区 CDN 与服务域名(mzstatic.com、apple.com.cn、
        // azchcdn*.com 等)，先直连，免得被下面的策略组送去所选地区节点。
        'RULE-SET,geosite-apple-cn,DIRECT',
        'RULE-SET,geosite-microsoft-cn,DIRECT',
        'RULE-SET,geosite-apple,Microsoft & Apple',
        'RULE-SET,geosite-microsoft,Microsoft & Apple',
        'RULE-SET,geosite-entertainment,Stream',
        'RULE-SET,geosite-futu,Securities',
        'RULE-SET,geosite-itiger,Securities',
        'RULE-SET,geosite-ibkr,Securities',
        'RULE-SET,geosite-cn,DIRECT',
        'RULE-SET,geosite-tld-cn,DIRECT',
        // 不加 no-resolve：Fake IP 下必须真实解析才能判定国别，加了会让这条
        // 永远不命中，未被 geosite-cn 兜住的国内 IP 会被误送去代理。
        'RULE-SET,geoip-cn,DIRECT',
        // effective-stash 建议禁用经代理转发的 QUIC（UDP 转发效率低）。置于
        // MATCH 之前，只作用于未被上面任何规则命中的流量，不改变已有分流结果。
        'AND,((NETWORK,udp),(DST-PORT,443)),REJECT,no-track',
        'MATCH,Final Match',
    ],
];

unset($stash_mrs);

// ===== Surge =====
// Surge has no geosite/yaml support, so it keeps its own template blocks (like
// Clash/Stash keep theirs). App\Services\Subscribe\Surge reads these; the node
// serialization stays in code. Proxy-group members support two placeholders the
// generator expands from the user's actual nodes:
//   'REGION:HK'      -> nodes classified as HK (or DIRECT if none)
//   'REGIONS'        -> region names that have nodes, order HK,US,JP,TW
//   'REGIONS:US,JP'  -> same, limited to the listed regions/order

// [General] section lines, emitted verbatim.
$_ENV['Surge_General'] = [
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

// Region keyword map — first-match-wins, priority by array order. Node names are
// matched case-sensitively; mirrors the region filters in the Clash profile.
$_ENV['Surge_Region_Keywords'] = [
    'HK' => ['HK', '香港', '🇭🇰'],
    'JP' => ['JP', '日本', '🇯🇵'],
    'US' => ['US', '美国', '🇺🇸'],
    'TW' => ['TW', '台湾', '🇹🇼'],
];

// [Proxy Group] definitions, emitted in this order. type defaults to 'select'.
$_ENV['Surge_Group_Config'] = [
    ['name' => 'Default Routing', 'proxies' => ['Global', 'DIRECT', 'REJECT']],
    ['name' => 'Global', 'proxies' => ['REGIONS']],
    ['name' => 'HK', 'proxies' => ['REGION:HK']],
    ['name' => 'JP', 'proxies' => ['REGION:JP']],
    ['name' => 'US', 'proxies' => ['REGION:US']],
    ['name' => 'TW', 'proxies' => ['REGION:TW']],
    ['name' => 'Apple & MS', 'proxies' => ['Default Routing', 'Global', 'DIRECT']],
    ['name' => 'AI Services', 'proxies' => ['REGIONS:US,JP']],
    ['name' => 'Securities', 'proxies' => ['Default Routing', 'HK', 'US', 'JP', 'TW', 'DIRECT']],
];

// [Rule] section lines, emitted verbatim in order. Mirrors the Clash rule order,
// with GEOSITE categories mapped to Loyalsoldier/surge-rules sets or inlined
// domains (Surge has no geosite).
$_ENV['Surge_Rules'] = [
    // LAN & system traffic.
    'RULE-SET,SYSTEM,DIRECT',
    'RULE-SET,LAN,DIRECT',

    // Ad blocking.
    'DOMAIN-SET,https://fastly.jsdelivr.net/gh/Loyalsoldier/surge-rules@release/reject.txt,REJECT',

    // AI services (must precede the Apple sets so gateway.icloud.com etc. hit AI first).
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

    'DOMAIN-SUFFIX,wifiman.com,Default Routing',

    // Google (CN-reachable subset; the rest falls through to FINAL=Default Routing).
    'DOMAIN-SET,https://fastly.jsdelivr.net/gh/Loyalsoldier/surge-rules@release/google.txt,Default Routing',

    // Apple & Microsoft (self-hosted full lists).
    'DOMAIN-SET,https://nmslcf2.pages.dev/Rules/Clash/surge_apple_cdn_set,Apple & MS,extended-matching',
    'RULE-SET,https://nmslcf2.pages.dev/Rules/Clash/surge_apple_services,Apple & MS,extended-matching',
    'RULE-SET,https://nmslcf2.pages.dev/Rules/Clash/surge_microsoft_services,Apple & MS,extended-matching',

    // Securities brokers (futu / itiger / ibkr, inlined from v2fly/domain-list-community).
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
    'DOMAIN-SUFFIX,itiger.com,Securities',
    'DOMAIN-SUFFIX,itigergrowth.com,Securities',
    'DOMAIN-SUFFIX,itigergrowtha.com,Securities',
    'DOMAIN-SUFFIX,itigerup.com,Securities',
    'DOMAIN-SUFFIX,laohu8.com,Securities',
    'DOMAIN-SUFFIX,skytigris.cn,Securities',
    'DOMAIN-SUFFIX,tigerbbs.cn,Securities',
    'DOMAIN-SUFFIX,tigerbbs.com,Securities',
    'DOMAIN-SUFFIX,xiaohu8.com,Securities',
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

    // CN direct, then GEOIP safety net, then final.
    'DOMAIN-SET,https://fastly.jsdelivr.net/gh/Loyalsoldier/surge-rules@release/direct.txt,DIRECT',
    'GEOIP,CN,DIRECT',
    'FINAL,Default Routing,dns-failed',
];
