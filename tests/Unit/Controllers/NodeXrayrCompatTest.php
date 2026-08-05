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

        // min_client_ver 是注入项(见下方 describe),其余 reality-opts 键必须原样保留。
        expect($out['reality-opts'])->toMatchArray($in['reality-opts'])
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
 * 2026-08-06 实地排查:节点 25(VLESS+Vision+REALITY)自建成起成功建连 0 次 /
 * REALITY 失败 531 次,全部 Clash 用户 100% 连不上。根因不在面板,而在后端 ——
 * XrayR fork 的 go.mod 钉了 xray-core 的 master 预发布快照(Xray 26.7.11),
 * 其中 commit af7eb680 给 REALITY 服务端加了默认值:
 *
 *     } else { config.MinClientVer = []byte{26, 3, 27} }   // infra/conf/transport_security.go
 *
 * 而 mihomo 的 REALITY 客户端在 ClientHello 的 sessionId 里硬编码上报版本
 * 1.8.2(component/tls/reality.go:hello.SessionId[0..2] = 1,8,2),小于该下限,
 * 被判为 "authentication failed or validation criteria not met" 而拒绝。
 * 原生 xray 客户端上报真实版本 26.x,不受影响 —— 这正是「xray 客户端能连、
 * Clash 用户全连不上」的原因。
 *
 * 修复是纯配置:在 reality-opts 显式下发一个 min_client_ver 覆盖该默认值。
 * XrayR 的 REALITYConfig 结构体读的正是 `min_client_ver`(api/sspanel/model.go),
 * 经 inboundbuilder.go 的 `MinClientVer: r.MinClientVer` 透传给 xray-core。
 * 由面板注入而非要求 admin 每建一个 REALITY 节点手写一次,避免重复踩坑。
 *
 * 取舍:该默认值来自 Xray PR #6181(uTLS ModernFingerprints 更新),用意是挡掉
 * 指纹陈旧的老客户端以抗主动探测。下发 "0.0.0" 等于放开这道闸门 —— 但只要
 * 用户群使用 Clash/mihomo 就别无选择。admin 显式指定时尊重其值。
 * ---------------------------------------------------------------------------
 */
describe('NodeController::applyXrayrCompat REALITY min_client_ver 注入', function () {
    it('injects min_client_ver into reality-opts so mihomo clients are not rejected', function () {
        $out = applyXrayrCompat([
            'security' => 'reality',
            'reality-opts' => ['private_key' => 'k', 'short_ids' => ['ab']],
        ], 12);

        expect($out['reality-opts']['min_client_ver'])->toBe('0.0.0');
    });

    it('respects an admin-authored min_client_ver instead of overwriting it', function () {
        $out = applyXrayrCompat([
            'security' => 'reality',
            'reality-opts' => ['private_key' => 'k', 'min_client_ver' => '25.1.1'],
        ], 12);

        expect($out['reality-opts']['min_client_ver'])->toBe('25.1.1');
    });

    it('does not fabricate reality-opts when the admin omitted it', function () {
        // reality-opts 缺失时 XrayR 收到 REALITYConfig=nil,会整段跳过 REALITY;
        // 若在此凭空造一个只含 min_client_ver 的块,反而会让 xray-core 因缺
        // privateKey 而构建入站失败 —— 属行为倒退,故不注入。
        $out = applyXrayrCompat(['security' => 'reality'], 12);

        expect($out)->not->toHaveKey('reality-opts');
    });

    it('leaves a non-array reality-opts alone', function () {
        $out = applyXrayrCompat(['security' => 'reality', 'reality-opts' => 'nonsense'], 12);

        expect($out['reality-opts'])->toBe('nonsense');
    });

    it('does not touch reality-opts when security is not reality', function () {
        $out = applyXrayrCompat([
            'security' => 'tls',
            'reality-opts' => ['private_key' => 'k'],
        ], 12);

        expect($out['reality-opts'])->toBe(['private_key' => 'k']);
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
