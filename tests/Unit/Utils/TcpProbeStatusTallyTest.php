<?php

declare(strict_types=1);

use App\Utils\TcpProbeStatus;

it('tallies carrier statuses across nodes in carrier order with every status key present', function () {
    $rows = [
        7 => ['telecom' => ['status' => 'green'], 'mobile' => ['status' => 'yellow'], 'unicom' => ['status' => 'green']],
        8 => ['telecom' => ['status' => 'red'], 'mobile' => ['status' => 'red'], 'unicom' => ['status' => 'red']],
        9 => ['telecom' => ['status' => 'green'], 'mobile' => ['status' => 'gray'], 'unicom' => ['status' => 'yellow']],
    ];

    $tally = TcpProbeStatus::tally($rows);

    expect(array_keys($tally))->toBe(['telecom', 'unicom', 'mobile'])
        ->and($tally['telecom'])->toBe(['carrier' => 'telecom', 'name' => '电信', 'green' => 2, 'yellow' => 0, 'red' => 1, 'gray' => 0, 'total' => 3])
        ->and($tally['unicom'])->toBe(['carrier' => 'unicom', 'name' => '联通', 'green' => 1, 'yellow' => 1, 'red' => 1, 'gray' => 0, 'total' => 3])
        ->and($tally['mobile'])->toBe(['carrier' => 'mobile', 'name' => '移动', 'green' => 0, 'yellow' => 1, 'red' => 1, 'gray' => 1, 'total' => 3]);
});

it('counts a missing or unknown carrier status as gray', function () {
    $tally = TcpProbeStatus::tally([
        1 => ['telecom' => ['status' => 'green']],
        2 => ['telecom' => ['status' => 'purple'], 'unicom' => [], 'mobile' => ['status' => 'green']],
    ]);

    expect($tally['telecom']['gray'])->toBe(1)
        ->and($tally['unicom']['gray'])->toBe(2)
        ->and($tally['mobile'])->toMatchArray(['green' => 1, 'gray' => 1, 'total' => 2]);
});

it('returns zero counts for every carrier when there are no nodes', function () {
    $tally = TcpProbeStatus::tally([]);

    expect(array_keys($tally))->toBe(['telecom', 'unicom', 'mobile'])
        ->and($tally['mobile'])->toMatchArray(['green' => 0, 'yellow' => 0, 'red' => 0, 'gray' => 0, 'total' => 0]);
});
