<?php

declare(strict_types=1);

use App\Services\Subscribe\Stash;

function buildStashVlessNode(array $custom): array
{
    $stash = (new ReflectionClass(Stash::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(Stash::class, 'buildVlessNode');

    $user = new stdClass();
    $user->uuid = 'a1b2c3d4-0000-4000-8000-000000000000';

    $node_raw = new stdClass();
    $node_raw->name = 'HK-01';
    $node_raw->server = 'a.example.com';

    return $method->invoke($stash, $user, $node_raw, $custom);
}

function stashRealityCustom(array $overrides = [], array $reality_overrides = []): array
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

describe('Stash buildVlessNode', function () {
    it('builds a full REALITY + Vision node', function () {
        $node = buildStashVlessNode(stashRealityCustom());

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
        $node = buildStashVlessNode(stashRealityCustom(['network' => 'ws']));

        expect($node)
            ->not->toHaveKey('flow')
            ->and($node['network'])->toBe('ws')
            ->and($node['reality-opts']['public-key'])
                ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('maps httpupgrade to ws and drops flow along with it', function () {
        $node = buildStashVlessNode(stashRealityCustom(['network' => 'httpupgrade']));

        expect($node['network'])->toBe('ws');
        expect($node)->not->toHaveKey('flow');
    });

    it('casts a numeric short_id to a string', function () {
        $node = buildStashVlessNode(stashRealityCustom([], ['short_ids' => [1234]]));

        expect($node['reality-opts']['short-id'])->toBe('1234');
    });

    it('takes servername from the first server_names entry', function () {
        $node = buildStashVlessNode(stashRealityCustom(
            ['host' => 'ignored.example.com'],
            ['server_names' => ['first.example.com', 'second.example.com']],
        ));

        expect($node['servername'])->toBe('first.example.com');
    });

    it('falls back to host for servername when server_names is missing', function () {
        $custom = stashRealityCustom(['host' => 'fallback.example.com']);
        unset($custom['reality-opts']['server_names']);

        $node = buildStashVlessNode($custom);

        expect($node['servername'])->toBe('fallback.example.com');
    });

    it('takes short-id from the first short_ids entry', function () {
        $node = buildStashVlessNode(stashRealityCustom([], ['short_ids' => ['aaaa', 'bbbb']]));

        expect($node['reality-opts']['short-id'])->toBe('aaaa');
    });

    it('emits an empty short-id when short_ids is absent', function () {
        $custom = stashRealityCustom();
        unset($custom['reality-opts']['short_ids']);

        $node = buildStashVlessNode($custom);

        expect($node['reality-opts']['short-id'])->toBe('');
    });

    it('skips the node when the reality private key is missing', function () {
        $custom = stashRealityCustom();
        unset($custom['reality-opts']['private_key']);

        expect(buildStashVlessNode($custom))->toBe([]);
    });

    it('skips the node when the reality private key is malformed', function () {
        $node = buildStashVlessNode(stashRealityCustom([], ['private_key' => 'not!!base64']));

        expect($node)->toBe([]);
    });

    it('falls back to an explicit public_key when the private key cannot be derived', function () {
        $node = buildStashVlessNode(stashRealityCustom([], [
            'private_key' => '',
            'public_key' => 'gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0',
        ]));

        expect($node['reality-opts']['public-key'])
            ->toBe('gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0');
    });

    it('prefers the derived key over an explicit public_key', function () {
        $node = buildStashVlessNode(stashRealityCustom([], [
            'public_key' => 'staleStaleStaleStaleStaleStaleStaleStaleSta',
        ]));

        expect($node['reality-opts']['public-key'])
            ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('omits reality-opts and uses host when security is tls', function () {
        $node = buildStashVlessNode([
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
        $node = buildStashVlessNode([
            'offset_port_node' => '443',
            'network' => 'tcp',
            'security' => 'none',
        ]);

        expect($node['tls'])->toBeFalse();
    });

    it('sets tls true when security is xtls', function () {
        $node = buildStashVlessNode(['security' => 'xtls']);

        expect($node['tls'])->toBeTrue();
    });

    it('prefers offset_port_user over offset_port_node', function () {
        $node = buildStashVlessNode(stashRealityCustom([
            'offset_port_user' => 8443,
            'offset_port_node' => 9443,
        ]));

        expect($node['port'])->toBe(8443);
    });

    it('defaults the port to 443 when neither offset is set', function () {
        $custom = stashRealityCustom();
        unset($custom['offset_port_node']);

        expect(buildStashVlessNode($custom)['port'])->toBe(443);
    });

    it('defaults client-fingerprint to chrome and honors an override', function () {
        expect(buildStashVlessNode(stashRealityCustom())['client-fingerprint'])->toBe('chrome');
        expect(buildStashVlessNode(stashRealityCustom(['fingerprint' => 'safari']))['client-fingerprint'])
            ->toBe('safari');
    });

    it('honors allow_insecure', function () {
        $node = buildStashVlessNode(stashRealityCustom(['allow_insecure' => true]));

        expect($node['skip-cert-verify'])->toBeTrue();
    });

    it('passes through ws-opts', function () {
        $node = buildStashVlessNode([
            'network' => 'ws',
            'security' => 'tls',
            'ws-opts' => ['path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']],
        ]);

        expect($node['ws-opts'])->toBe(['path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']]);
    });

    it('defaults network to tcp when unset', function () {
        expect(buildStashVlessNode(['security' => 'none'])['network'])->toBe('tcp');
    });
});
