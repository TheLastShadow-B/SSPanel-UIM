<?php

declare(strict_types=1);

use App\Services\Subscribe\Surge;

function buildHy2Line(array $custom, string $passwd = 'pwd123', string $server = 'hy2.example.com'): ?string
{
    $surge = (new ReflectionClass(Surge::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(Surge::class, 'buildHysteria2Line');

    $user = new stdClass();
    $user->passwd = $passwd;

    $node_raw = new stdClass();
    $node_raw->server = $server;

    return $method->invoke($surge, $user, $node_raw, $custom);
}

describe('Surge buildHysteria2Line', function () {
    it('builds a minimal hysteria2 line with defaults', function () {
        $line = buildHy2Line([]);

        expect($line)->toBe('hysteria2, hy2.example.com, 443, password=pwd123, skip-cert-verify=false');
    });

    it('uses offset_port_user over offset_port_node', function () {
        $line = buildHy2Line([
            'offset_port_user' => 8443,
            'offset_port_node' => 9443,
        ]);

        expect($line)->toContain('hy2.example.com, 8443,');
    });

    it('falls back to offset_port_node', function () {
        $line = buildHy2Line(['offset_port_node' => 9443]);

        expect($line)->toContain('hy2.example.com, 9443,');
    });

    it('emits sni from host and honors allow_insecure', function () {
        $line = buildHy2Line([
            'host' => 'cdn.example.com',
            'allow_insecure' => true,
        ]);

        expect($line)
            ->toContain('sni=cdn.example.com')
            ->toContain('skip-cert-verify=true');
    });

    it('emits download-bandwidth when down_mbps is set', function () {
        $line = buildHy2Line(['Hy2Opts' => ['down_mbps' => 100]]);

        expect($line)->toContain('download-bandwidth=100');
    });

    it('omits download-bandwidth when down_mbps is zero', function () {
        $line = buildHy2Line(['Hy2Opts' => ['down_mbps' => 0]]);

        expect($line)->not->toContain('download-bandwidth');
    });

    it('emits salamander-password for salamander obfs', function () {
        $line = buildHy2Line([
            'Hy2Opts' => ['obfs' => 'salamander', 'obfs_password' => 'sala-pass'],
        ]);

        expect($line)->toContain('salamander-password=sala-pass');
    });

    it('skips nodes with unsupported obfs types', function () {
        $line = buildHy2Line([
            'Hy2Opts' => ['obfs' => 'gecko', 'obfs_password' => 'gecko-pass'],
        ]);

        expect($line)->toBeNull();
    });

    it('ignores obfs when password is empty', function () {
        $line = buildHy2Line([
            'Hy2Opts' => ['obfs' => 'salamander', 'obfs_password' => ''],
        ]);

        expect($line)
            ->not->toBeNull()
            ->not->toContain('salamander-password');
    });
});
