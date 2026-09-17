<?php

declare(strict_types=1);

use App\Services\Config\ClientConfig;
use App\Services\Subscribe;
use App\Services\Subscribe\Clash;
use App\Services\Subscribe\Hako;

$hakoTestNodes = static function (): array {
    return [
        [
            'name' => 'JP-Vision',
            'type' => 'vless',
            'server' => 'jp.example.com',
            'port' => 443,
            'uuid' => 'a1b2c3d4-0000-4000-8000-000000000000',
            'tls' => true,
            'flow' => 'xtls-rprx-vision',
            'client-fingerprint' => 'chrome',
            'reality-opts' => ['public-key' => 'example-public-key', 'short-id' => '1234abcd'],
        ],
        ['name' => 'SG-Other', 'type' => 'trojan', 'skip-cert-verify' => true],
    ];
};

it('dispatches the exact requested format separately from existing Clash', function () {
    expect(Subscribe::getClient('calsh-hako'))->toBeInstanceOf(Hako::class)
        ->and(Subscribe::getClient('clash'))->toBeInstanceOf(Clash::class);
});

it('preserves node credentials and options without nesting subscriptions', function () use ($hakoTestNodes) {
    $nodes = $hakoTestNodes();
    $config = (new Hako())->buildConfig($nodes);

    expect($config['proxies'])->toBe($nodes)
        ->and($config)->not->toHaveKey('proxy-providers');
});

it('keeps empty regions blocked and unclassified nodes selectable', function () use ($hakoTestNodes) {
    $config = (new Hako())->buildConfig($hakoTestNodes());
    $groups = array_column($config['proxy-groups'], null, 'name');

    expect($groups['JP']['proxies'])->toBe(['JP-Vision'])
        ->and($groups['HK']['proxies'])->toBe(['REJECT'])
        ->and($groups['Global']['proxies'])->toContain('SG-Other');

    foreach ((new Hako())->buildConfig([])['proxy-groups'] as $group) {
        expect($group['proxies'])->not->toBeEmpty();
    }
});

it('resolves every rule and group target without cycles', function () use ($hakoTestNodes) {
    $config = (new Hako())->buildConfig($hakoTestNodes());
    $groups = array_column($config['proxy-groups'], null, 'name');
    $targets = array_merge(array_keys($groups), array_column($config['proxies'], 'name'), ['DIRECT', 'REJECT']);

    $visit = function (string $name, array $path = []) use (&$visit, $groups): void {
        expect($path)->not->toContain($name);
        foreach ($groups[$name]['proxies'] as $target) {
            if (isset($groups[$target])) {
                $visit($target, [...$path, $name]);
            }
        }
    };
    foreach ($groups as $name => $group) {
        $visit($name);
        foreach ($group['proxies'] as $target) {
            expect($targets)->toContain($target);
        }
    }
    foreach ($config['rules'] as $rule) {
        $parts = explode(',', $rule);
        expect($targets)->toContain($parts[$parts[0] === 'MATCH' ? 1 : 2]);
        if ($parts[0] === 'RULE-SET') {
            expect($config['rule-providers'])->toHaveKey($parts[1]);
        }
    }
    expect($config['rules'][array_key_last($config['rules'])])->toBe('MATCH,Final Match');
});

it('uses precompiled resources without reintroducing full geodata through DNS', function () use ($hakoTestNodes) {
    $config = (new Hako())->buildConfig($hakoTestNodes());
    $paths = [];
    foreach ($config['rule-providers'] as $provider) {
        expect($provider['format'])->toBe('mrs')
            ->and(['domain', 'ipcidr'])->toContain($provider['behavior'])
            ->and($provider['url'])->toEndWith('.mrs')
            ->and($paths)->not->toContain($provider['path']);
        $paths[] = $provider['path'];
    }
    foreach ($config['rules'] as $rule) {
        expect($rule)->not->toStartWith('GEOSITE,')->not->toStartWith('GEOIP,');
    }
    expect($config)->not->toHaveKey('geox-url')->not->toHaveKey('external-controller')
        ->and($config['dns'])->not->toHaveKey('fallback-filter')
        ->and($config['dns']['proxy-server-nameserver'])->not->toBeEmpty()
        ->and($config['dns']['nameserver-policy'])->toHaveKey('rule-set:site-cn')
        ->and($config['tun'])->toBe(['stack' => 'mixed']);
});

it('keeps its profile independent from the desktop Clash template', function () use ($hakoTestNodes) {
    $before = $_ENV;
    try {
        $_ENV['Clash_Config'] = ['log-level' => 'debug', 'mixed-port' => 10000];
        $_ENV['Clash_Group_Config'] = ['rules' => ['MATCH,DIRECT']];
        $config = (new Hako())->buildConfig($hakoTestNodes());
        expect($config['log-level'])->toBe('warning')
            ->and($config)->not->toHaveKey('mixed-port')
            ->and($_ENV['Clash_Config']['log-level'])->toBe('debug');
    } finally {
        $_ENV = $before;
    }
});

it('shows Clash with an encoded calsh-hako import URL on Apple platforms', function () {
    $result = ClientConfig::getClients('https://sub.example.com/sub/test-token', '测试订阅', false);
    foreach (['iOS', 'macOS'] as $platform) {
        $client = array_values(array_filter(
            $result['clients'][$platform],
            static fn (array $client): bool => $client['name'] === 'Clash'
        ))[0];
        parse_str(parse_url($client['importUrl'], PHP_URL_QUERY), $query);
        expect($client['format'])->toBe('calsh-hako')
            ->and($query['url'])->toBe('https://sub.example.com/sub/test-token/calsh-hako')
            ->and($query['name'])->toBe('测试订阅');
    }
});
