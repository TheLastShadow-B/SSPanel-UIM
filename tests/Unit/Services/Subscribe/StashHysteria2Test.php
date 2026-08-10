<?php

declare(strict_types=1);

use App\Services\Subscribe\Stash;

function buildStashHy2Node(array $custom, string $passwd = 'pwd123'): array
{
    $stash = (new ReflectionClass(Stash::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(Stash::class, 'buildHysteria2Node');

    $user = new stdClass();
    $user->passwd = $passwd;

    $node_raw = new stdClass();
    $node_raw->name = 'HK-HY2';
    $node_raw->server = 'hy2.example.com';

    return $method->invoke($stash, $user, $node_raw, $custom);
}

describe('Stash buildHysteria2Node', function () {
    it('builds a minimal hysteria2 node with defaults', function () {
        $node = buildStashHy2Node([]);

        expect($node)->toBe([
            'name' => 'HK-HY2',
            'type' => 'hysteria2',
            'server' => 'hy2.example.com',
            'port' => 443,
            'auth' => 'pwd123',
            'sni' => '',
            'skip-cert-verify' => false,
        ]);
    });

    // Stash 的 hysteria2 凭证字段是 auth;password 是未公开的兼容别名,
    // 文档从未列出,不能依赖。https://stash.wiki/proxy-protocols/proxy-types
    it('carries the credential in auth, not password', function () {
        $node = buildStashHy2Node([], 'secret-pass');

        expect($node)
            ->toHaveKey('auth', 'secret-pass')
            ->not->toHaveKey('password');
    });

    it('uses offset_port_user over offset_port_node', function () {
        $node = buildStashHy2Node([
            'offset_port_user' => 8443,
            'offset_port_node' => 9443,
        ]);

        expect($node['port'])->toBe(8443);
    });

    // Stash 的 hysteria2 带宽字段是 up-speed / down-speed(整数 Mbps),
    // 与 mihomo 的 up / down 不同名 —— 后者会被 Stash 静默忽略。
    // https://stash.wiki/proxy-protocols/proxy-types
    it('emits up-speed/down-speed in Stash naming, not mihomo up/down', function () {
        $node = buildStashHy2Node(['Hy2Opts' => ['up_mbps' => 50, 'down_mbps' => 200]]);

        expect($node)
            ->toHaveKey('up-speed', 50)
            ->toHaveKey('down-speed', 200)
            ->not->toHaveKey('up')
            ->not->toHaveKey('down');
    });

    it('casts string bandwidth values to int', function () {
        $node = buildStashHy2Node(['Hy2Opts' => ['up_mbps' => '50', 'down_mbps' => '200']]);

        expect($node['up-speed'])->toBe(50)
            ->and($node['down-speed'])->toBe(200);
    });

    it('omits bandwidth keys when unset or zero', function () {
        $node = buildStashHy2Node(['Hy2Opts' => ['up_mbps' => 0, 'down_mbps' => 0]]);

        expect($node)
            ->not->toHaveKey('up-speed')
            ->not->toHaveKey('down-speed');
    });

    it('emits obfs pair and sni', function () {
        $node = buildStashHy2Node([
            'host' => 'cdn.example.com',
            'Hy2Opts' => ['obfs' => 'salamander', 'obfs_password' => 'sala-pass'],
        ]);

        expect($node['sni'])->toBe('cdn.example.com')
            ->and($node['obfs'])->toBe('salamander')
            ->and($node['obfs-password'])->toBe('sala-pass');
    });

    it('emits gecko packet size bounds', function () {
        $node = buildStashHy2Node([
            'Hy2Opts' => ['obfs' => 'gecko', 'obfs_password' => 'gecko-pass'],
        ]);

        expect($node['obfs-min-packet-size'])->toBe(600)
            ->and($node['obfs-max-packet-size'])->toBe(1300);
    });

    it('emits port hopping with a 5s floor on the interval', function () {
        $node = buildStashHy2Node([
            'Hy2Opts' => ['hop_ports' => '61000-63000, 64000-65499', 'hop_interval' => 1],
        ]);

        expect($node['ports'])->toBe('61000-63000,64000-65499')
            ->and($node['hop-interval'])->toBe(5);
    });
});
