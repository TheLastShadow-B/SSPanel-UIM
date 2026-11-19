<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Subscribe;
use App\Services\Subscribe\Surge;
use Illuminate\Support\Collection;

/**
 * 一个中转节点可以有多个入口(custom_config.entries):订阅里展开成每个入口一条,
 * server / 端口换成该入口的,名字加「 · 线路<label>」。见
 * Node/designs/2026-11-19-entry-failover-design.md §5.5。
 */
function entriesNode(array $custom, string $name = '[RELAY] TW HiNet', int $sort = 1): Node
{
    $node = new Node();
    $node->forceFill([
        'id' => 32,
        'name' => $name,
        'server' => 'prompter-shrimp-energetic.mmz.one',
        'sort' => $sort,
        'custom_config' => json_encode($custom),
    ]);
    $node->syncOriginal();

    return $node;
}

function twoEntries(): array
{
    return [
        ['label' => 'A', 'server' => 'prompter-shrimp-energetic.mmz.one', 'port' => '55502'],
        ['label' => 'B', 'server' => 'showbiz-headgear-dowry.mmz.one', 'port' => '55509'],
    ];
}

function ss2022Custom(array $extra = []): array
{
    return array_merge([
        'offset_port_user' => '55502',
        'offset_port_node' => '10060',
        'server_key' => '8aK/TOrpaFToQ/8RcuOhLg==',
        'method' => '2022-blake3-aes-128-gcm',
        'uot' => true,
    ], $extra);
}

describe('Subscribe::expandEntries', function () {
    it('expands a node with two entries into two nodes', function () {
        $out = Subscribe::expandEntries(new Collection([entriesNode(ss2022Custom(['entries' => twoEntries()]))]));

        expect($out)->toHaveCount(2);
        expect($out[0]->name)->toBe('[RELAY] TW HiNet · 线路A');
        expect($out[1]->name)->toBe('[RELAY] TW HiNet · 线路B');
        expect($out[0]->server)->toBe('prompter-shrimp-energetic.mmz.one');
        expect($out[1]->server)->toBe('showbiz-headgear-dowry.mmz.one');
        expect(json_decode($out[0]->custom_config, true)['offset_port_user'])->toBe('55502');
        expect(json_decode($out[1]->custom_config, true)['offset_port_user'])->toBe('55509');
        expect($out[1]->id)->toBe(32);
    });

    it('keeps other custom_config keys', function () {
        $out = Subscribe::expandEntries(new Collection([entriesNode(ss2022Custom(['entries' => twoEntries()]))]));
        $cfg = json_decode($out[1]->custom_config, true);

        expect($cfg)->toMatchArray([
            'offset_port_node' => '10060',
            'server_key' => '8aK/TOrpaFToQ/8RcuOhLg==',
            'method' => '2022-blake3-aes-128-gcm',
            'uot' => true,
        ]);
        expect($cfg)->not->toHaveKey('entries');
    });

    it('leaves nodes without entries untouched', function () {
        $plain = entriesNode(ss2022Custom(), 'HK-01');
        $out = Subscribe::expandEntries(new Collection([$plain]));

        expect($out)->toHaveCount(1);
        expect($out[0])->toBe($plain);
    });

    it('falls back to the original port when an entry has no port', function () {
        $node = entriesNode(ss2022Custom(['entries' => [
            ['label' => 'A', 'server' => 'a.example'],
            ['label' => 'B', 'server' => 'b.example', 'port' => 55509],
        ]]));
        $out = Subscribe::expandEntries(new Collection([$node]));

        expect(json_decode($out[0]->custom_config, true)['offset_port_user'])->toBe('55502');
        expect(json_decode($out[1]->custom_config, true)['offset_port_user'])->toBe('55509');
    });

    it('does not touch the original model', function () {
        $node = entriesNode(ss2022Custom(['entries' => twoEntries()]));
        Subscribe::expandEntries(new Collection([$node]));

        expect($node->isDirty())->toBeFalse();
        expect($node->name)->toBe('[RELAY] TW HiNet');
    });

    it('skips malformed entries and keeps the node when none are usable', function () {
        $half = entriesNode(ss2022Custom(['entries' => [['label' => 'A'], 'junk', ['label' => 'B', 'server' => 'b.example']]]));
        $none = entriesNode(ss2022Custom(['entries' => [['server' => 'x']]]), 'JP-01');
        $scalar = entriesNode(ss2022Custom(['entries' => 'a.example']), 'SG-01');
        $out = Subscribe::expandEntries(new Collection([$half, $none, $scalar]));

        expect($out->pluck('name')->all())->toBe(['[RELAY] TW HiNet · 线路B', 'JP-01', 'SG-01']);
    });

    it('keeps node order with expansions in place', function () {
        $out = Subscribe::expandEntries(new Collection([
            entriesNode(ss2022Custom(), 'HK-01'),
            entriesNode(ss2022Custom(['entries' => twoEntries()])),
            entriesNode(ss2022Custom(), 'US-01'),
        ]));

        expect($out->pluck('name')->all())
            ->toBe(['HK-01', '[RELAY] TW HiNet · 线路A', '[RELAY] TW HiNet · 线路B', 'US-01']);
    });
});

describe('Surge with expanded entries', function () {
    it('emits one proxy line per entry with that entry server and port', function () {
        $nodes = Subscribe::expandEntries(new Collection([entriesNode(ss2022Custom(['entries' => twoEntries()]))]));

        $surge = (new ReflectionClass(Surge::class))->newInstanceWithoutConstructor();
        $user = new stdClass();
        $user->method = 'aes-128-gcm';
        $user->port = 8388;
        $user->passwd = 'pwd123';
        $user->uuid = 'a1b2c3d4-0000-4000-8000-000000000000';
        [$lines, $names] = (new ReflectionMethod(Surge::class, 'buildProxies'))->invoke($surge, $user, $nodes);

        expect($names)->toBe(['[RELAY] TW HiNet · 线路A', '[RELAY] TW HiNet · 线路B']);
        expect($lines[0])->toContain('prompter-shrimp-energetic.mmz.one, 55502');
        expect($lines[1])->toContain('showbiz-headgear-dowry.mmz.one, 55509');
    });
});
