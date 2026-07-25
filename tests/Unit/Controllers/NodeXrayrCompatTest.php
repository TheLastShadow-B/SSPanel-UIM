<?php

declare(strict_types=1);

use App\Controllers\WebAPI\NodeController;

function applyXrayrCompat(array $custom_config, int $sort): array
{
    $controller = (new ReflectionClass(NodeController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(NodeController::class, 'applyXrayrCompat');

    return $method->invoke($controller, $custom_config, $sort);
}

describe('NodeController::applyXrayrCompat', function () {
    it('injects enable_vless as the string "1" for sort=12', function () {
        $out = applyXrayrCompat(['offset_port_node' => '443'], 12);

        expect($out['enable_vless'])->toBe('1');
    });

    it('injects enable_reality as a real boolean when security is reality', function () {
        $out = applyXrayrCompat(['security' => 'reality'], 12);

        expect($out['enable_reality'])->toBeTrue();
    });

    it('omits enable_reality when security is not reality', function () {
        $out = applyXrayrCompat(['security' => 'tls'], 12);

        expect($out)
            ->toHaveKey('enable_vless')
            ->not->toHaveKey('enable_reality');
    });

    it('omits enable_reality when security is absent', function () {
        $out = applyXrayrCompat([], 12);

        expect($out)->not->toHaveKey('enable_reality');
    });

    it('preserves the admin-authored keys untouched', function () {
        $in = [
            'offset_port_node' => '443',
            'network' => 'tcp',
            'security' => 'reality',
            'flow' => 'xtls-rprx-vision',
            'reality-opts' => [
                'dest' => 'www.cloudflare.com:443',
                'server_names' => ['www.cloudflare.com'],
                'private_key' => 'dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo',
                'short_ids' => ['0123abcd'],
            ],
        ];

        $out = applyXrayrCompat($in, 12);

        expect($out['reality-opts'])->toBe($in['reality-opts'])
            ->and($out['flow'])->toBe('xtls-rprx-vision')
            ->and($out['network'])->toBe('tcp');
    });

    it('leaves other node types untouched', function () {
        foreach ([0, 1, 2, 3, 11, 14, 15] as $sort) {
            expect(applyXrayrCompat(['security' => 'reality'], $sort))
                ->toBe(['security' => 'reality']);
        }
    });
});

/*
 * ---------------------------------------------------------------------------
 * Final-review Finding 4 (2026-07-25): json_decode('123', true) returns int(123)
 * — non-null, so the old `?? []` guard on the getInfo() call site did not catch
 * it, and the scalar was passed straight into applyXrayrCompat()'s typed
 * `array $custom_config` parameter, throwing a TypeError. applyXrayrCompat() runs
 * for EVERY node type (not just sort=12), so a stray top-level JSON scalar in
 * custom_config — valid per the DB's `CHECK (json_valid(custom_config))`, which
 * JSON_VALID('123') satisfies, and accepted by the admin JSON editor's code mode
 * — would 500 `/mod_mu/nodes/{id}/info` for existing Shadowsocks/VMess/Trojan/
 * Hysteria2 nodes too.
 *
 * The fix factors the decode+guard out of getInfo() into its own private
 * decodeCustomConfig(), mirroring why applyXrayrCompat() itself is a separate
 * private method: getInfo() calls Node::find() and reads the VERSION constant
 * (only defined via app/predefine.php, not the test bootstrap) before ever
 * reaching this logic, so there is no DB/env-free seam onto it without pulling
 * the guard out on its own — same rationale as applyXrayrCompat() above.
 * ---------------------------------------------------------------------------
 */
function decodeCustomConfig(string $raw): array
{
    $controller = (new ReflectionClass(NodeController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(NodeController::class, 'decodeCustomConfig');

    return $method->invoke($controller, $raw);
}

describe('NodeController::decodeCustomConfig', function () {
    it('degrades a top-level JSON scalar to [] instead of leaving a value applyXrayrCompat() would reject', function () {
        expect(decodeCustomConfig('123'))->toBe([]);
        expect(decodeCustomConfig('"just a string"'))->toBe([]);
        expect(decodeCustomConfig('true'))->toBe([]);
    });

    it('still degrades invalid JSON and an empty document to []', function () {
        expect(decodeCustomConfig('not valid json'))->toBe([]);
        expect(decodeCustomConfig(''))->toBe([]);
    });

    it('still decodes a normal JSON object to its array form', function () {
        expect(decodeCustomConfig('{"offset_port_node":"443","security":"reality"}'))->toBe([
            'offset_port_node' => '443',
            'security' => 'reality',
        ]);
    });
});
