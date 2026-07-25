<?php

declare(strict_types=1);

use App\Services\Subscribe\Surge;

/**
 * `custom_config` 的列定义是 `longtext NOT NULL DEFAULT '{}'` 加
 * `CHECK (json_valid(custom_config))`,但 `JSON_VALID('123')` 为 1 —— 该约束
 * 不挡顶层 JSON 标量。而 `json_decode('123', true)` 返回 `int(123)`,非 null,
 * 所以 `?? []` 也挡不住。Surge 的 build*Line() 方法形参均为 typed `array`,
 * 于是标量会在 strict_types 下抛出未捕获的 TypeError。
 *
 * 这些测试钉住:标量 `custom_config` 必须降级为空配置,而不是让整份 Surge
 * 订阅崩掉。
 */
function buildSurgeProxies(array $nodes, string $method = 'aes-128-gcm'): array
{
    $surge = (new ReflectionClass(Surge::class))->newInstanceWithoutConstructor();
    $reflected = new ReflectionMethod(Surge::class, 'buildProxies');

    $user = new stdClass();
    $user->method = $method;
    $user->port = 8388;
    $user->passwd = 'pwd123';
    $user->uuid = 'a1b2c3d4-0000-4000-8000-000000000000';

    return $reflected->invoke($surge, $user, $nodes);
}

function surgeNode(int $sort, string $custom_config, string $name = 'HK-01'): stdClass
{
    $node = new stdClass();
    $node->name = $name;
    $node->server = 'a.example.com';
    $node->sort = $sort;
    $node->custom_config = $custom_config;

    return $node;
}

describe('Surge buildProxies with a scalar custom_config', function () {
    it('does not throw on a numeric scalar and degrades to an empty config', function () {
        [$scalar_lines] = buildSurgeProxies([surgeNode(0, '123')]);
        [$empty_lines] = buildSurgeProxies([surgeNode(0, '{}')]);

        expect($scalar_lines)->toBe($empty_lines);
    });

    it('degrades a quoted-string scalar to an empty config', function () {
        [$scalar_lines] = buildSurgeProxies([surgeNode(0, '"cafe"')]);
        [$empty_lines] = buildSurgeProxies([surgeNode(0, '{}')]);

        expect($scalar_lines)->toBe($empty_lines);
    });

    it('degrades a boolean scalar to an empty config', function () {
        [$scalar_lines] = buildSurgeProxies([surgeNode(0, 'true')]);
        [$empty_lines] = buildSurgeProxies([surgeNode(0, '{}')]);

        expect($scalar_lines)->toBe($empty_lines);
    });

    it('degrades a JSON list to an empty config rather than passing it through', function () {
        // json_decode('[1,2]', true) yields a list, which IS an array and so
        // passes is_array() — the build*Line() methods then read every key with
        // `??`, so a list behaves as an empty config. Pinned so the guard is not
        // "tightened" to array_is_list() and made to skip these nodes instead.
        [$list_lines] = buildSurgeProxies([surgeNode(0, '[1,2]')]);
        [$empty_lines] = buildSurgeProxies([surgeNode(0, '{}')]);

        expect($list_lines)->toBe($empty_lines);
    });

    it('survives a scalar on a vmess node', function () {
        [$scalar_lines] = buildSurgeProxies([surgeNode(11, '123')]);
        [$empty_lines] = buildSurgeProxies([surgeNode(11, '{}')]);

        expect($scalar_lines)
            ->toBe($empty_lines)
            ->not->toBeEmpty();
    });

    it('survives a scalar on a trojan node', function () {
        [$scalar_lines] = buildSurgeProxies([surgeNode(14, '123')]);
        [$empty_lines] = buildSurgeProxies([surgeNode(14, '{}')]);

        expect($scalar_lines)->toBe($empty_lines);
    });

    it('survives a scalar on a hysteria2 node', function () {
        [$scalar_lines] = buildSurgeProxies([surgeNode(15, '123')]);
        [$empty_lines] = buildSurgeProxies([surgeNode(15, '{}')]);

        expect($scalar_lines)->toBe($empty_lines);
    });

    it('survives a scalar on a shadowsocks2022 node', function () {
        [$scalar_lines] = buildSurgeProxies(
            [surgeNode(1, '123')],
            '2022-blake3-aes-128-gcm',
        );
        [$empty_lines] = buildSurgeProxies(
            [surgeNode(1, '{}')],
            '2022-blake3-aes-128-gcm',
        );

        expect($scalar_lines)->toBe($empty_lines);
    });

    it('keeps rendering later nodes after a scalar-config node', function () {
        [$lines, $names] = buildSurgeProxies([
            surgeNode(0, '123', 'BROKEN'),
            surgeNode(0, '{}', 'GOOD'),
        ]);

        expect($names)->toBe(['BROKEN', 'GOOD'])
            ->and($lines)->toHaveCount(2);
    });

    it('still degrades invalid JSON to an empty config', function () {
        [$invalid_lines] = buildSurgeProxies([surgeNode(0, 'not json at all')]);
        [$empty_lines] = buildSurgeProxies([surgeNode(0, '{}')]);

        expect($invalid_lines)->toBe($empty_lines);
    });
});
