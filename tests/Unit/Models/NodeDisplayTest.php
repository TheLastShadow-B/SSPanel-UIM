<?php

declare(strict_types=1);

use App\Models\Node;

function nodeWithoutDb(array $attributes): Node
{
    $node = (new ReflectionClass(Node::class))->newInstanceWithoutConstructor();
    foreach ($attributes as $key => $value) {
        $node->{$key} = $value;
    }

    return $node;
}

describe('Node::displayName', function () {
    it('strips a leading bracketed protocol tag from the node name', function (string $name, string $expected) {
        expect(nodeWithoutDb(['name' => $name])->displayName())->toBe($expected);
    })->with([
        ['[Hy2] HK HGC B', 'HK HGC B'],
        ['[WS] HK HGC D', 'HK HGC D'],
        ['  [VLESS]  JP 01', 'JP 01'],
        ['TW HiNet A', 'TW HiNet A'],
        ['JP [Akamai] A', 'JP [Akamai] A'],
    ]);

    it('keeps the original name when stripping the tag would leave nothing', function () {
        expect(nodeWithoutDb(['name' => '[Hy2]'])->displayName())->toBe('[Hy2]')
            ->and(nodeWithoutDb(['name' => '[Hy2]   '])->displayName())->toBe('[Hy2]');
    });
});

describe('Node::sortShort', function () {
    it('maps every protocol to a short chip label', function (int $sort, string $expected) {
        expect(nodeWithoutDb(['sort' => $sort])->sortShort())->toBe($expected);
    })->with([
        [0, 'SS'],
        [1, 'SS2022'],
        [2, 'TUIC'],
        [3, 'WG'],
        [11, 'Vmess'],
        [12, 'VLESS'],
        [14, 'Trojan'],
        [15, 'Hy2'],
        [99, '未知'],
    ]);
});
