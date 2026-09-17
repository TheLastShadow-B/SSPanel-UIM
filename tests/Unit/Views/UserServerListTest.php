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
