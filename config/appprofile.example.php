<?php

declare(strict_types=1);

$_ENV['Clash_Config'] = [
    'mixed-port' => 7890,
    'allow-lan' => false,
    'bind-address' => '*',
    'mode' => 'Rule',
    'unified-delay' => true,
    'ipv6' => true,
    'log-level' => 'info',
    'external-controller' => '127.0.0.1:9090',
    'secret' => 'Burst_XJJ#Clash',
    'profile' => [
        'store-selected' => true,
        'store-fake-ip' => true,
    ],
    'geox-url' => [
        'geoip' => 'https://fastly.jsdelivr.net/gh/MetaCubeX/meta-rules-dat@release/geoip.dat',
        'geosite' => 'https://fastly.jsdelivr.net/gh/MetaCubeX/meta-rules-dat@release/geosite.dat',
        'mmdb' => 'https://fastly.jsdelivr.net/gh/MetaCubeX/meta-rules-dat@release/country.mmdb',
    ],
    'tun' => [
      'enable' => false,
      'stack' => 'mixed',
      'auto-route' => true,
      'auto-redir' => true,
      'auto-detect-interface' => true,
      'endpoint-independent-nat' => true,
      'dns-hijack' => [
        'any:53',
        'tcp://any:53',
      ]
    ],
    'dns' => [
        'enable' => true,
        'ipv6' => true,
        'prefer-h3' => false,
        'enhanced-mode' => 'fake-ip',
        'fake-ip-range' => '198.18.0.1/16',
        'fake-ip-filter' => [
            '+.lan',
            '+.local',
            'localhost.ptlogin2.qq.com',
            '+.msftconnecttest.com',
            '+.msftncsi.com',
            'time.*.com',
            'ntp.*.com',
            '+.pool.ntp.org',
            '+.ntp.org.cn',
            '+.time.edu.cn',
            'time1.cloud.tencent.com',
        ],
        'nameserver' => [
            'https://223.5.5.5/dns-query',
            'https://doh.pub/dns-query',
            'https://dns.alidns.com/dns-query',
        ],
        'fallback' => [
            'tls://8.8.4.4',
            'tls://1.1.1.1',
        ],
        'default-nameserver' => [
            '223.5.5.5',
            '120.53.53.1',
        ],
        'proxy-server-nameserver' => [
            'https://doh.pub/dns-query',
            '223.5.5.5',
        ],
        'fallback-filter' => [
            'geoip' => true,
            'geoip-code' => 'CN',
            'geosite' => [
                'gfw',
            ],
            'ipcidr' => [
                '240.0.0.0/4',
            ],
        ],
    ],
    'sniffer' => [
            'enable' => true,
            'parse-pure-ip' => true,
            'sniff' => [
                'HTTP' => [
                    'ports' => [80],
                    'override-destination' => true,
                ],
                'TLS' => [
                    'ports' => [443, 8443],
                ],
                'QUIC' => [
                   'ports' => [443, 8443],
                ],
            ],
            'skip-domain' => [
               'Mijia Cloud',
        ],
    ],
];

$_ENV['Clash_Group_Indexes'] = [];
$_ENV['Clash_Group_Config'] = [
    'proxy-groups' => [
        [
            'name' => 'Default Proxy',
            'type' => 'select',
            'proxies' => [
                'Global',
                'DIRECT',
                'REJECT',
            ],
        ],
        [
            'name' => 'Global',
            'type' => 'select',
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
            'include-all' => true,
            'filter' => 'JP',
        ],
        [
            'name' => 'HK',
            'type' => 'select',
            'include-all' => true,
            'filter' => 'HK',
        ],
        [
            'name' => 'US',
            'type' => 'select',
            'include-all' => true,
            'filter' => 'US',
        ],
        [
            'name' => 'TW',
            'type' => 'select',
            'include-all' => true,
            'filter' => 'TW',
        ],
        [
            'name' => 'AI Services',
            'type' => 'select',
            'proxies' => [
                'JP',
                'US',
            ],
        ],
        [
            'name' => 'Microsoft & Apple',
            'type' => 'select',
            'proxies' => [
                'Default Proxy',
                'HK',
                'JP',
                'US',
                'TW',
                'DIRECT',
            ],
        ],
        [
            'name' => 'Stream',
            'type' => 'select',
            'proxies' => [
                'Default Proxy',
                'HK',
                'JP',
                'US',
                'TW',
            ],
        ],
        [
            'name' => 'Steam Download',
            'type' => 'select',
            'proxies' => [
                'Default Proxy',
                'HK',
                'JP',
                'US',
                'TW',
                'DIRECT',
            ],
        ],
        [
            'name' => 'Securities',
            'type' => 'select',
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
        'ad-reject' => [
            'type' => 'http',
            'behavior' => 'domain',
            'format' => 'yaml',
            'url' => 'https://fastly.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/reject.txt',
            'path' => './ruleset/loyalsoldier/reject.yaml',
            'interval' => 86400,
        ],
    ],
    'rules' => [
        'DOMAIN-SUFFIX,local,DIRECT',
        'DOMAIN-SUFFIX,arpa,DIRECT',
        'GEOIP,private,DIRECT,no-resolve',
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
        'GEOSITE,google,Default Proxy',
        'GEOSITE,apple,Microsoft & Apple',
        'GEOSITE,microsoft,Microsoft & Apple',
        'GEOSITE,category-entertainment,Stream',
        'GEOSITE,category-game-platforms-download,Steam Download',
        'GEOSITE,futu,Securities',
        'GEOSITE,itiger,Securities',
        'GEOSITE,ibkr,Securities',
        'GEOSITE,cn,DIRECT',
        'GEOIP,CN,DIRECT',
        'MATCH,Final Match',
    ],
];

$_ENV['Stash_Config'] = [
    'mode' => 'rule',
    'log-level' => 'info',
    'dns' => [
        'default-nameserver' => [
            '223.5.5.5',
            '114.114.114.114',
            'system',
        ],
        'nameserver' => [
            'https://doh.pub/dns-query',
            'https://dns.alidns.com/dns-query',
        ],
        'follow-rule' => false,
    ],
];

$_ENV['Stash_Group_Indexes'] = [];
$_ENV['Stash_Group_Config'] = [
    'proxy-groups' => [
        [
            'name' => 'Default Proxy',
            'type' => 'select',
            'proxies' => [
                'Global',
                'DIRECT',
                'REJECT',
            ],
        ],
        [
            'name' => 'Global',
            'type' => 'select',
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
            'include-all' => true,
            'filter' => 'JP',
        ],
        [
            'name' => 'HK',
            'type' => 'select',
            'include-all' => true,
            'filter' => 'HK',
        ],
        [
            'name' => 'US',
            'type' => 'select',
            'include-all' => true,
            'filter' => 'US',
        ],
        [
            'name' => 'TW',
            'type' => 'select',
            'include-all' => true,
            'filter' => 'TW',
        ],
        [
            'name' => 'AI Services',
            'type' => 'select',
            'proxies' => [
                'JP',
                'US',
            ],
        ],
        [
            'name' => 'Microsoft & Apple',
            'type' => 'select',
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
        'ad-reject' => [
            'type' => 'http',
            'behavior' => 'domain',
            'format' => 'yaml',
            'url' => 'https://fastly.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/reject.txt',
            'interval' => 86400,
        ],
        'lan-cidr' => [
            'type' => 'http',
            'behavior' => 'ipcidr',
            'format' => 'yaml',
            'url' => 'https://fastly.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/lancidr.txt',
            'interval' => 86400,
        ],
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
        'GEOSITE,google,Default Proxy',
        'GEOSITE,apple,Microsoft & Apple',
        'GEOSITE,microsoft,Microsoft & Apple',
        'GEOSITE,category-entertainment,Stream',
        'GEOSITE,futu,Securities',
        'GEOSITE,itiger,Securities',
        'GEOSITE,ibkr,Securities',
        'GEOSITE,cn,DIRECT',
        'GEOIP,CN,DIRECT',
        'MATCH,Final Match',
    ],
];

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
