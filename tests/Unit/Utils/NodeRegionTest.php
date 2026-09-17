<?php

declare(strict_types=1);

use App\Utils\NodeRegion;

it('recognizes node country and region markers without matching protocol substrings', function (string $name, string $code) {
    expect(NodeRegion::detect($name))->toBe($code);
})->with([
    ['HK-HGC-VLESS-Beta', 'HK'],
    ['hk01 专线', 'HK'],
    ['🇯🇵 东京 01', 'JP'],
    ['香港入口 🇺🇸 US 01', 'US'],
    ['日本 JP 02', 'JP'],
    ['台灣 01', 'TW'],
    ['Taipei 02', 'TW'],
    ['Singapore 01', 'SG'],
    ['SG-01', 'SG'],
    ['South Korea 02', 'KR'],
    ['US02', 'US'],
    ['Los Angeles 01', 'US'],
    ['UK-London', 'GB'],
    ['🇩🇪 01', 'DE'],
    ['印度 01', 'IN'],
    ['印度尼西亚 01', 'ID'],
    ['XrayR-Test-SS2022', 'OTHER'],
    ['XrayR-Test-Hy2', 'OTHER'],
    ['BUSINESS Trojan INTERNAL', 'OTHER'],
    ['', 'OTHER'],
]);

it('groups visible nodes once, preserves their order and metadata, and counts only online nodes', function () {
    $servers = [
        ['id' => 1, 'name' => 'Unknown A', 'online' => 0, 'class' => 0],
        ['id' => 2, 'name' => 'JP 02', 'online' => 1, 'class' => 3],
        ['id' => 3, 'name' => 'HK 01', 'online' => -1, 'class' => 1],
        ['id' => 4, 'name' => 'JP 01', 'online' => -1, 'class' => 1],
        ['id' => 5, 'name' => 'Unknown B', 'online' => 1, 'class' => 2],
    ];

    $groups = NodeRegion::group($servers);

    expect(array_column($groups, 'code'))->toBe(['HK', 'JP', 'OTHER'])
        ->and(array_column($groups, 'online'))->toBe([0, 1, 1])
        ->and($groups[0]['name'])->toBe('香港')
        ->and($groups[0]['flag'])->toBe('🇭🇰')
        ->and($groups[1]['servers'])->toBe([$servers[1], $servers[3]])
        ->and($groups[2]['servers'])->toBe([$servers[0], $servers[4]])
        ->and($groups[2]['name'])->toBe('其他地区')
        ->and($groups[2]['flag'])->toBe('');

    $ids = array_merge(...array_map(fn ($group) => array_column($group['servers'], 'id'), $groups));
    sort($ids);
    expect($ids)->toBe([1, 2, 3, 4, 5]);
});

it('does not create placeholder groups when no nodes are visible', function () {
    expect(NodeRegion::group([]))->toBe([]);
});

it('prioritizes each configured country over a conflicting node name and flag', function (string $country) {
    $groups = NodeRegion::group([['id' => 1, 'name' => '🇩🇪 DE Frankfurt', 'country' => $country, 'online' => 1]]);
    expect($groups[0]['code'])->toBe($country)
        ->and($groups[0]['online'])->toBe(1);
})->with(['US', 'HK', 'JP', 'SG', 'TW']);

it('keeps automatic detection for legacy or unrecognized country values', function (mixed $country) {
    expect(NodeRegion::group([['name' => 'HK-HGC', 'country' => $country]])[0]['code'])->toBe('HK');
})->with([[''], [null], ['XX'], [['US']]]);

it('normalizes supported codes and rejects invalid country input', function () {
    expect(NodeRegion::normalizeCountry(' us '))->toBe('US')
        ->and(NodeRegion::normalizeCountry(''))->toBe('')
        ->and(NodeRegion::normalizeCountry('DE'))->toBeNull()
        ->and(NodeRegion::normalizeCountry(['US']))->toBeNull()
        ->and(NodeRegion::normalizeCountry(1))->toBeNull();
});
