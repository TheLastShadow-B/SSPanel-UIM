<?php

declare(strict_types=1);

it('lists every node expanded with three carrier columns and a carrier filter, without accordions', function () {
    $tpl = file_get_contents(__DIR__ . '/../../../resources/views/cafe/user/server.tpl');

    expect($tpl)->not->toContain('t-acc')
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
