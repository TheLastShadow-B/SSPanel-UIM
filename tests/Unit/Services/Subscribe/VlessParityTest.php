<?php

declare(strict_types=1);

use App\Services\Subscribe\Clash;
use App\Services\Subscribe\Stash;

/*
 * ---------------------------------------------------------------------------
 * PINNING test, not an invariant (final-review Finding 5, 2026-07-25).
 *
 * Clash::buildVlessNode() and Stash::buildVlessNode() are intentionally
 * near-verbatim duplicates. This was raised with the human during final
 * review, who ruled to keep both copies: the six pre-existing protocol
 * branches in these two files are already duplicated the same way, and the
 * two clients' key names may deliberately diverge later. Do NOT deduplicate
 * them in response to this test.
 *
 * ClashVlessTest.php and StashVlessTest.php each carry ~21 near-identical
 * assertions, which catches REGRESSIVE drift (a change that breaks one
 * file's existing behaviour) but not ADDITIVE drift: add a key to Clash.php
 * only, and all of those tests still pass while the two clients silently
 * receive different configs. This file closes that gap by reflecting both
 * methods with the same input and asserting the outputs are identical.
 *
 * If you are deliberately diverging the two — e.g. one client gains a key
 * the other format doesn't support — update the affected case below and
 * record WHY here. This test pins today's fact that they match; it is not a
 * rule that must hold forever.
 * ---------------------------------------------------------------------------
 */

function vlessParityUser(): stdClass
{
    $user = new stdClass();
    $user->uuid = 'a1b2c3d4-0000-4000-8000-000000000000';

    return $user;
}

function vlessParityNodeRaw(): stdClass
{
    $node_raw = new stdClass();
    $node_raw->name = 'HK-01';
    $node_raw->server = 'a.example.com';

    return $node_raw;
}

function vlessParityBuildClash(array $custom): array
{
    $clash = (new ReflectionClass(Clash::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(Clash::class, 'buildVlessNode');

    return $method->invoke($clash, vlessParityUser(), vlessParityNodeRaw(), $custom);
}

function vlessParityBuildStash(array $custom): array
{
    $stash = (new ReflectionClass(Stash::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(Stash::class, 'buildVlessNode');

    return $method->invoke($stash, vlessParityUser(), vlessParityNodeRaw(), $custom);
}

function vlessParityRealityCustom(array $overrides = [], array $reality_overrides = []): array
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

describe('Clash/Stash buildVlessNode output parity (pinning, not an invariant)', function () {
    it('produces identical output for a full REALITY + Vision node', function () {
        $custom = vlessParityRealityCustom();

        expect(vlessParityBuildClash($custom))->toBe(vlessParityBuildStash($custom));
    });

    it('stays identical when the transport drops flow (network=ws)', function () {
        $custom = vlessParityRealityCustom(['network' => 'ws']);

        expect(vlessParityBuildClash($custom))->toBe(vlessParityBuildStash($custom));
    });

    it('stays identical for the httpupgrade -> ws mapping', function () {
        $custom = vlessParityRealityCustom(['network' => 'httpupgrade']);

        expect(vlessParityBuildClash($custom))->toBe(vlessParityBuildStash($custom));
    });

    it('stays identical when security is tls (no reality-opts, servername falls back to host)', function () {
        $custom = [
            'offset_port_node' => '443',
            'network' => 'ws',
            'security' => 'tls',
            'host' => 'cdn.example.com',
        ];

        expect(vlessParityBuildClash($custom))->toBe(vlessParityBuildStash($custom));
    });

    it('stays identical when security is none', function () {
        $custom = [
            'offset_port_node' => '443',
            'network' => 'tcp',
            'security' => 'none',
        ];

        expect(vlessParityBuildClash($custom))->toBe(vlessParityBuildStash($custom));
    });

    it('stays identical (both []) when the reality private key is missing', function () {
        $custom = vlessParityRealityCustom();
        unset($custom['reality-opts']['private_key']);

        $clash = vlessParityBuildClash($custom);
        $stash = vlessParityBuildStash($custom);

        expect($clash)->toBe($stash)
            ->and($clash)->toBe([]);
    });

    it('stays identical when falling back to an explicit public_key', function () {
        $custom = vlessParityRealityCustom([], [
            'private_key' => '',
            'public_key' => 'gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0',
        ]);

        expect(vlessParityBuildClash($custom))->toBe(vlessParityBuildStash($custom));
    });

    it('stays identical with ws-opts passed through untouched', function () {
        $custom = [
            'network' => 'ws',
            'security' => 'tls',
            'ws-opts' => ['path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']],
        ];

        expect(vlessParityBuildClash($custom))->toBe(vlessParityBuildStash($custom));
    });
});
