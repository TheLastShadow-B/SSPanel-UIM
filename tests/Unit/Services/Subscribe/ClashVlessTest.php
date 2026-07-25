<?php

declare(strict_types=1);

use App\Services\Subscribe\Clash;

function buildClashVlessNode(array $custom): array
{
    $clash = (new ReflectionClass(Clash::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(Clash::class, 'buildVlessNode');

    $user = new stdClass();
    $user->uuid = 'a1b2c3d4-0000-4000-8000-000000000000';

    $node_raw = new stdClass();
    $node_raw->name = 'HK-01';
    $node_raw->server = 'a.example.com';

    return $method->invoke($clash, $user, $node_raw, $custom);
}

function clashRealityCustom(array $overrides = [], array $reality_overrides = []): array
{
    return array_merge([
        'offset_port_node' => '443',
        'network' => 'tcp',
        'security' => 'reality',
        'flow' => 'xtls-rprx-vision',
        'reality-opts' => array_merge([
            'dest' => 'www.cloudflare.com:443',
            'server_names' => ['www.cloudflare.com'],
            'private_key' => 'dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo',
            'short_ids' => ['0123abcd'],
        ], $reality_overrides),
    ], $overrides);
}

describe('Clash buildVlessNode', function () {
    it('builds a full REALITY + Vision node', function () {
        $node = buildClashVlessNode(clashRealityCustom());

        expect($node)->toMatchArray([
            'name' => 'HK-01',
            'type' => 'vless',
            'server' => 'a.example.com',
            'port' => 443,
            'uuid' => 'a1b2c3d4-0000-4000-8000-000000000000',
            'udp' => true,
            'tls' => true,
            'skip-cert-verify' => false,
            'servername' => 'www.cloudflare.com',
            'network' => 'tcp',
            'client-fingerprint' => 'chrome',
            'flow' => 'xtls-rprx-vision',
        ]);

        expect($node['reality-opts'])->toBe([
            'public-key' => 'hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo',
            'short-id' => '0123abcd',
        ]);
    });

    it('drops flow when the transport is not raw tcp', function () {
        $node = buildClashVlessNode(clashRealityCustom(['network' => 'ws']));

        expect($node)
            ->not->toHaveKey('flow')
            ->and($node['network'])->toBe('ws')
            ->and($node['reality-opts']['public-key'])
                ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('maps httpupgrade to ws and drops flow along with it', function () {
        $node = buildClashVlessNode(clashRealityCustom(['network' => 'httpupgrade']));

        expect($node['network'])->toBe('ws');
        expect($node)->not->toHaveKey('flow');
    });

    it('casts a numeric short_id to a string', function () {
        $node = buildClashVlessNode(clashRealityCustom([], ['short_ids' => [1234]]));

        expect($node['reality-opts']['short-id'])->toBe('1234');
    });

    it('takes servername from the first server_names entry', function () {
        $node = buildClashVlessNode(clashRealityCustom(
            ['host' => 'ignored.example.com'],
            ['server_names' => ['first.example.com', 'second.example.com']],
        ));

        expect($node['servername'])->toBe('first.example.com');
    });

    it('falls back to host for servername when server_names is missing', function () {
        $custom = clashRealityCustom(['host' => 'fallback.example.com']);
        unset($custom['reality-opts']['server_names']);

        $node = buildClashVlessNode($custom);

        expect($node['servername'])->toBe('fallback.example.com');
    });

    it('takes short-id from the first short_ids entry', function () {
        $node = buildClashVlessNode(clashRealityCustom([], ['short_ids' => ['aaaa', 'bbbb']]));

        expect($node['reality-opts']['short-id'])->toBe('aaaa');
    });

    it('emits an empty short-id when short_ids is absent', function () {
        $custom = clashRealityCustom();
        unset($custom['reality-opts']['short_ids']);

        $node = buildClashVlessNode($custom);

        expect($node['reality-opts']['short-id'])->toBe('');
    });

    it('skips the node when the reality private key is missing', function () {
        $custom = clashRealityCustom();
        unset($custom['reality-opts']['private_key']);

        expect(buildClashVlessNode($custom))->toBe([]);
    });

    it('skips the node when the reality private key is malformed', function () {
        $node = buildClashVlessNode(clashRealityCustom([], ['private_key' => 'not!!base64']));

        expect($node)->toBe([]);
    });

    it('falls back to an explicit public_key when the private key cannot be derived', function () {
        $node = buildClashVlessNode(clashRealityCustom([], [
            'private_key' => '',
            'public_key' => 'gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0',
        ]));

        expect($node['reality-opts']['public-key'])
            ->toBe('gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0');
    });

    it('prefers the derived key over an explicit public_key', function () {
        $node = buildClashVlessNode(clashRealityCustom([], [
            'public_key' => 'staleStaleStaleStaleStaleStaleStaleStaleSta',
        ]));

        expect($node['reality-opts']['public-key'])
            ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('omits reality-opts and uses host when security is tls', function () {
        $node = buildClashVlessNode([
            'offset_port_node' => '443',
            'network' => 'ws',
            'security' => 'tls',
            'host' => 'cdn.example.com',
        ]);

        expect($node)
            ->not->toHaveKey('reality-opts')
            ->and($node['tls'])->toBeTrue()
            ->and($node['servername'])->toBe('cdn.example.com');
    });

    it('sets tls false when security is none', function () {
        $node = buildClashVlessNode([
            'offset_port_node' => '443',
            'network' => 'tcp',
            'security' => 'none',
        ]);

        expect($node['tls'])->toBeFalse();
    });

    it('sets tls true when security is xtls', function () {
        $node = buildClashVlessNode(['security' => 'xtls']);

        expect($node['tls'])->toBeTrue();
    });

    it('prefers offset_port_user over offset_port_node', function () {
        $node = buildClashVlessNode(clashRealityCustom([
            'offset_port_user' => 8443,
            'offset_port_node' => 9443,
        ]));

        expect($node['port'])->toBe(8443);
    });

    it('defaults the port to 443 when neither offset is set', function () {
        $custom = clashRealityCustom();
        unset($custom['offset_port_node']);

        expect(buildClashVlessNode($custom)['port'])->toBe(443);
    });

    it('defaults client-fingerprint to chrome and honors an override', function () {
        expect(buildClashVlessNode(clashRealityCustom())['client-fingerprint'])->toBe('chrome');
        expect(buildClashVlessNode(clashRealityCustom(['fingerprint' => 'safari']))['client-fingerprint'])
            ->toBe('safari');
    });

    it('honors allow_insecure', function () {
        $node = buildClashVlessNode(clashRealityCustom(['allow_insecure' => true]));

        expect($node['skip-cert-verify'])->toBeTrue();
    });

    it('passes through ws-opts', function () {
        $node = buildClashVlessNode([
            'network' => 'ws',
            'security' => 'tls',
            'ws-opts' => ['path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']],
        ]);

        expect($node['ws-opts'])->toBe(['path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']]);
    });

    it('defaults network to tcp when unset', function () {
        expect(buildClashVlessNode(['security' => 'none'])['network'])->toBe('tcp');
    });
});
