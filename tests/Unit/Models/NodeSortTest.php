<?php

declare(strict_types=1);

use App\Models\Node;

function nodeSortLabel(int $sort): string
{
    $node = (new ReflectionClass(Node::class))->newInstanceWithoutConstructor();
    $node->sort = $sort;

    return $node->sort();
}

describe('Node::sort', function () {
    it('maps 12 to VLESS', function () {
        expect(nodeSortLabel(12))->toBe('VLESS');
    });

    it('keeps the existing protocol labels intact', function () {
        expect(nodeSortLabel(0))->toBe('Shadowsocks')
            ->and(nodeSortLabel(1))->toBe('Shadowsocks2022')
            ->and(nodeSortLabel(2))->toBe('TUIC')
            ->and(nodeSortLabel(3))->toBe('WireGuard')
            ->and(nodeSortLabel(11))->toBe('Vmess')
            ->and(nodeSortLabel(14))->toBe('Trojan')
            ->and(nodeSortLabel(15))->toBe('Hysteria2');
    });

    it('falls back to 未知 for unmapped values', function () {
        expect(nodeSortLabel(99))->toBe('未知');
    });
});
