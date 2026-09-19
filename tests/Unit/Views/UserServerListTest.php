<?php

declare(strict_types=1);

it('lists every node with three carrier columns, regions collapsible but open by default, without a carrier filter', function () {
    $tpl = file_get_contents(__DIR__ . '/../../../resources/views/cafe/user/server.tpl');

    expect($tpl)->toContain('class="t-acc c-card')
        ->toContain('data-open="true"')
        ->toContain(':data-open="String(isOpen(')
        ->not->toContain('x-data="{ open: false }"')
        ->not->toContain('node_bandwidth')
        ->not->toContain('regions-toggle')
        ->not->toContain('$carrier_tally')
        ->not->toContain('data-carrier')
        ->not->toContain('node-tile')
        ->not->toContain('node-seg')
        ->not->toContain('每分钟自动刷新')
        ->not->toContain('无数据')
        ->toContain("{if \$server['probe_status'] === null}")
        ->toContain('未开启检测')
        ->toContain('data-probe-node=')
        ->toContain('data-probe-carrier=')
        ->toContain("\$server['display_name']")
        ->toContain("\$server['proto']");
});

it('renders one carrier cell per configured carrier in carrier order, not CT/CM/CU codes', function () {
    $tpl = file_get_contents(__DIR__ . '/../../../resources/views/cafe/user/server.tpl');

    expect($tpl)->toContain('foreach $carriers as $code => $name')
        ->not->toContain('>CT<')
        ->not->toContain('>CM<')
        ->not->toContain('>CU<');
});

it('shows region availability as online/total only', function () {
    $tpl = file_get_contents(__DIR__ . '/../../../resources/views/cafe/user/server.tpl');

    expect($tpl)->toContain("{\$group['online']}/{count(\$group['servers'])}")
        ->not->toContain('全部在线')
        ->not->toContain('个节点');
});

it('shows a notice instead of carrier sections on the detail page of an unmonitored node', function () {
    $tpl = file_get_contents(__DIR__ . '/../../../resources/views/cafe/user/server_probe.tpl');

    expect($tpl)->toContain("{if !\$probe['enabled']}")
        ->toContain('未开启回国检测');
});

it('renders the detail page as one status card: a row plus bar per carrier, targets with their own bars behind a toggle', function () {
    $tpl = file_get_contents(__DIR__ . '/../../../resources/views/cafe/user/server_probe.tpl');

    expect(substr_count($tpl, 'class="c-card overflow-hidden"'))->toBe(1)
        ->and($tpl)->toContain('class="probe-group t-acc"')
        ->toContain('data-toggle-targets')
        ->toContain("\$target['history']['buckets']")
        ->toContain("\$target['history']['uptime']")
        ->not->toContain('<details')
        ->not->toContain('c-card-pad" aria-label');
});
