<?php

declare(strict_types=1);

require __DIR__ . '/clash-verge.php';

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
        'GEOSITE,cn,DIRECT',
        'GEOIP,CN,DIRECT',
        'MATCH,Final Match',
    ],
];
