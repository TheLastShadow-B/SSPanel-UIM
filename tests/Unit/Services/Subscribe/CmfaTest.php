<?php

declare(strict_types=1);

use App\Services\Config\ClientConfig;
use App\Services\Subscribe;
use App\Services\Subscribe\Clash;
use App\Services\Subscribe\Cmfa;
use App\Services\Subscribe\Hako;

it('dispatches CMFA separately while retaining existing subscription formats', function () {
    expect(Subscribe::getClient('cmfa'))->toBeInstanceOf(Cmfa::class)
        ->and(Subscribe::getClient('clash'))->toBeInstanceOf(Clash::class)
        ->and(Subscribe::getClient('calsh-hako'))->toBeInstanceOf(Hako::class);
});

it('preserves supplied user nodes without sharing desktop template state', function () {
    $before = $_ENV;
    $nodes = [
        [
            'name' => 'JP-Vision',
            'type' => 'vless',
            'server' => 'jp.example.com',
            'port' => 443,
            'uuid' => 'a1b2c3d4-0000-4000-8000-000000000000',
            'tls' => true,
            'flow' => 'xtls-rprx-vision',
            'reality-opts' => ['public-key' => 'example-public-key', 'short-id' => '1234abcd'],
        ],
        ['name' => 'SG-Other', 'type' => 'trojan', 'password' => 'test-password'],
    ];
    try {
        $_ENV['Clash_Config'] = ['log-level' => 'debug', 'external-controller' => '127.0.0.1:9000'];
        $_ENV['Clash_Group_Config'] = ['rules' => ['MATCH,REJECT']];
        $env = $_ENV;
        $config = (new Cmfa())->buildConfig($nodes);

        expect($config['proxies'])->toBe($nodes)
            ->and($config)->not->toHaveKey('external-controller')->not->toHaveKey('secret')
            ->and($config)->not->toHaveKey('proxy-providers')
            ->and($config['rules'])->not->toBe(['MATCH,REJECT'])
            ->and($_ENV)->toBe($env)
            ->and((new Cmfa())->buildConfig([])['proxies'])->toBe([]);
    } finally {
        $_ENV = $before;
    }
});

it('resolves CMFA routing and DNS targets to declared groups and rule providers', function () {
    $config = (new Cmfa())->buildConfig([]);
    $groups = array_column($config['proxy-groups'], null, 'name');
    $targets = [...array_keys($groups), 'DIRECT', 'REJECT'];

    foreach ($groups as $group) {
        foreach ($group['proxies'] ?? [] as $target) {
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
    foreach ($config['dns']['nameserver'] as $resolver) {
        expect($targets)->toContain(explode('#', $resolver)[1]);
    }
});

it('advertises the encoded CMFA endpoint only for the Android client', function () {
    $result = ClientConfig::getClients('https://sub.example.com/sub/test-token', '测试订阅 & CMFA', false);
    $cmfa = array_values(array_filter(
        $result['clients']['Android'],
        static fn (array $client): bool => $client['name'] === 'CMFA'
    ))[0];
    parse_str(parse_url($cmfa['importUrl'], PHP_URL_QUERY), $query);

    expect($cmfa['format'])->toBe('cmfa')
        ->and($query['url'])->toBe('https://sub.example.com/sub/test-token/cmfa')
        ->and($query['name'])->toBe('测试订阅 & CMFA');

    $desktop = array_values(array_filter(
        $result['clients']['Windows'],
        static fn (array $client): bool => $client['name'] === 'Clash Verge Rev'
    ))[0];
    parse_str(parse_url($desktop['importUrl'], PHP_URL_QUERY), $query);
    expect($desktop['format'])->toBe('clash')
        ->and($query['url'])->toBe('https://sub.example.com/sub/test-token/clash');
});
