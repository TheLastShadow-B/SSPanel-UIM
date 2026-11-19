<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Subscribe\NodeEntries;
use Illuminate\Support\Collection;

/*
 * A node whose custom_config has "entries" is listed once per entry in a
 * subscription. These tests pin what each line looks like and what happens to
 * a list that is wrong. They build models in memory and need no database;
 * NodeEntriesSubscriptionTest.php runs the real generators.
 */

if (! function_exists('entriesTestNode')) {
    function entriesTestNode(mixed $custom, string $name = 'TW Relay'): Node
    {
        $node = new Node();
        $node->forceFill([
            'id' => 7,
            'name' => $name,
            'server' => 'entry-a.example.com',
            'sort' => 1,
            'custom_config' => is_string($custom) ? $custom : json_encode($custom),
        ]);
        $node->syncOriginal();

        return $node;
    }
}

if (! function_exists('entriesTestCustom')) {
    function entriesTestCustom(array $extra = []): array
    {
        return array_merge([
            'offset_port_user' => '20001',
            'offset_port_node' => '10001',
            'server_key' => 'c2VydmVyLWtleS1mb3ItdGVzdA==',
            'method' => '2022-blake3-aes-128-gcm',
            'uot' => true,
        ], $extra);
    }
}

if (! function_exists('entriesTestPair')) {
    function entriesTestPair(): array
    {
        return [
            ['label' => 'A', 'server' => 'entry-a.example.com', 'port' => '20001'],
            ['label' => 'B', 'server' => 'entry-b.example.com', 'port' => '20002'],
        ];
    }
}

if (! function_exists('entriesTestExpand')) {
    function entriesTestExpand(Node ...$nodes): Collection
    {
        return NodeEntries::expand(new Collection($nodes));
    }
}

if (! function_exists('entriesTestPort')) {
    function entriesTestPort(Node $line): mixed
    {
        return json_decode($line->custom_config, true)['offset_port_user'];
    }
}

describe('a node with entries', function () {
    it('becomes one line per entry', function () {
        $lines = entriesTestExpand(entriesTestNode(entriesTestCustom(['entries' => entriesTestPair()])));

        expect($lines)->toHaveCount(2)
            ->and($lines->pluck('name')->all())->toBe(['TW Relay · 线路A', 'TW Relay · 线路B'])
            ->and($lines->pluck('server')->all())->toBe(['entry-a.example.com', 'entry-b.example.com'])
            ->and(entriesTestPort($lines[0]))->toBe('20001')
            ->and(entriesTestPort($lines[1]))->toBe('20002')
            ->and($lines->pluck('id')->all())->toBe([7, 7]);
    });

    it('keeps the rest of custom_config and leaves the list out', function () {
        $lines = entriesTestExpand(entriesTestNode(entriesTestCustom(['entries' => entriesTestPair()])));
        $custom = json_decode($lines[1]->custom_config, true);

        expect($custom)->toBe([
            'offset_port_user' => '20002',
            'offset_port_node' => '10001',
            'server_key' => 'c2VydmVyLWtleS1mb3ItdGVzdA==',
            'method' => '2022-blake3-aes-128-gcm',
            'uot' => true,
        ]);
    });

    it('keeps its own port for an entry with no port or an empty one', function () {
        $lines = entriesTestExpand(entriesTestNode(entriesTestCustom(['entries' => [
            ['label' => 'A', 'server' => 'a.example.com'],
            ['label' => 'B', 'server' => 'b.example.com', 'port' => ''],
            ['label' => 'C', 'server' => 'c.example.com', 'port' => null],
            ['label' => 'D', 'server' => 'd.example.com', 'port' => 20004],
        ]])));

        expect($lines->map(fn (Node $line) => entriesTestPort($line))->all())
            ->toBe(['20001', '20001', '20001', 20004]);
    });

    it('gets no port of its own when neither it nor the entry has one', function () {
        $lines = entriesTestExpand(entriesTestNode(['entries' => [['label' => 'A', 'server' => 'a.example.com']]]));

        expect(json_decode($lines[0]->custom_config, true))->toBe([])
            ->and($lines[0]->server)->toBe('a.example.com');
    });

    it('is left as it was', function () {
        $node = entriesTestNode(entriesTestCustom(['entries' => entriesTestPair()]));
        $before = $node->getAttributes();
        $lines = entriesTestExpand($node);

        expect($node->isDirty())->toBeFalse()
            ->and($node->getAttributes())->toBe($before)
            ->and($lines[0])->not->toBe($node);
    });

    it('stays where it was in the list', function () {
        $lines = entriesTestExpand(
            entriesTestNode(entriesTestCustom(), 'HK Direct'),
            entriesTestNode(entriesTestCustom(['entries' => entriesTestPair()])),
            entriesTestNode(entriesTestCustom(), 'US Direct'),
        );

        expect($lines->pluck('name')->all())
            ->toBe(['HK Direct', 'TW Relay · 线路A', 'TW Relay · 线路B', 'US Direct']);
    });

    it('accepts a number as a label and trims labels and servers', function () {
        $lines = entriesTestExpand(entriesTestNode(entriesTestCustom(['entries' => [
            ['label' => 1, 'server' => ' a.example.com '],
            ['label' => ' B ', 'server' => 'b.example.com'],
        ]])));

        expect($lines->pluck('name')->all())->toBe(['TW Relay · 线路1', 'TW Relay · 线路B'])
            ->and($lines[0]->server)->toBe('a.example.com');
    });
});

describe('a node without usable entries', function () {
    it('passes through as the same object', function (mixed $custom) {
        $node = entriesTestNode($custom);
        $lines = entriesTestExpand($node);

        expect($lines)->toHaveCount(1)
            ->and($lines[0])->toBe($node);
    })->with([
        'no entries key' => [entriesTestCustom()],
        'an empty list' => [entriesTestCustom(['entries' => []])],
        'a string' => [entriesTestCustom(['entries' => 'a.example.com'])],
        'null' => [entriesTestCustom(['entries' => null])],
        'only malformed entries' => [entriesTestCustom(['entries' => [['server' => 'a.example.com'], 'junk']])],
        'a scalar custom_config' => ['123'],
        'an empty custom_config' => [''],
        'broken JSON' => ['{"entries": ['],
    ]);
});

describe('a wrong entry', function () {
    it('is left out while the others stay', function (mixed $wrong) {
        $lines = entriesTestExpand(entriesTestNode(entriesTestCustom(['entries' => [
            $wrong,
            ['label' => 'B', 'server' => 'entry-b.example.com', 'port' => '20002'],
        ]])));

        expect($lines->pluck('name')->all())->toBe(['TW Relay · 线路B']);
    })->with([
        'not an object' => ['junk'],
        'no label' => [['server' => 'a.example.com']],
        'no server' => [['label' => 'A']],
        'a blank label' => [['label' => '  ', 'server' => 'a.example.com']],
        'a blank server' => [['label' => 'A', 'server' => '']],
        'a server that is not text' => [['label' => 'A', 'server' => ['a.example.com']]],
        'port 0' => [['label' => 'A', 'server' => 'a.example.com', 'port' => 0]],
        'a port above 65535' => [['label' => 'A', 'server' => 'a.example.com', 'port' => '70000']],
        'a port that is not a number' => [['label' => 'A', 'server' => 'a.example.com', 'port' => 'https']],
        'a fractional port' => [['label' => 'A', 'server' => 'a.example.com', 'port' => 20001.5]],
        'a boolean port' => [['label' => 'A', 'server' => 'a.example.com', 'port' => true]],
    ]);

    it('is left out when it repeats a label', function () {
        $lines = entriesTestExpand(entriesTestNode(entriesTestCustom(['entries' => [
            ['label' => 'A', 'server' => 'first.example.com'],
            ['label' => 'A', 'server' => 'second.example.com'],
            ['label' => 'B', 'server' => 'entry-b.example.com'],
        ]])));

        expect($lines->pluck('name')->all())->toBe(['TW Relay · 线路A', 'TW Relay · 线路B'])
            ->and($lines[0]->server)->toBe('first.example.com');
    });
});
