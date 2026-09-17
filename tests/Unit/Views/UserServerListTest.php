<?php

declare(strict_types=1);

it('lists every node with three carrier columns and a carrier filter, regions collapsible but open by default', function () {
    $tpl = file_get_contents(__DIR__ . '/../../../resources/views/cafe/user/server.tpl');

    expect($tpl)->toContain('class="t-acc c-card')
        ->toContain('data-open="true"')
        ->toContain(':data-open="String(isOpen(')
        ->not->toContain('x-data="{ open: false }"')
        ->not->toContain('node_bandwidth')
        ->not->toContain('regions-toggle')
        ->toContain('$carrier_tally')
        ->toContain('data-carrier')
        ->toContain('data-probe-node=')
        ->toContain('data-probe-carrier=')
        ->toContain("\$server['display_name']")
        ->toContain("\$server['proto']");
});

it('renders one carrier cell per configured carrier in carrier order, not CT/CM/CU codes', function () {
    $tpl = file_get_contents(__DIR__ . '/../../../resources/views/cafe/user/server.tpl');

    expect($tpl)->toContain('foreach $carrier_tally as $carrier')
        ->not->toContain('>CT<')
        ->not->toContain('>CM<')
        ->not->toContain('>CU<');
});
