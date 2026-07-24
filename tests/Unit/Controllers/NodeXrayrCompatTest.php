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
