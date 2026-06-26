<?php

declare(strict_types=1);

use App\Models\Config;
use Tests\TestDatabase;

beforeEach(function () {
    TestDatabase::init();
    // mirror the rows the P0 migration seeds
    foreach ([
        ['stripe_publishable_key', '', 1, 'string'],
        ['stripe_auto_billing_enabled', '0', 0, 'bool'],
        ['balance_auto_renew_enabled', '0', 0, 'bool'],
        ['stripe_grace_days', '7', 0, 'int'],
    ] as [$item, $val, $pub, $type]) {
        $c = new Config();
        $c->item = $item;
        $c->value = $val;
        $c->class = 'billing';
        $c->is_public = $pub;
        $c->type = $type;
        $c->default = $val;
        $c->mark = '';
        $c->save();
    }
});

afterEach(function () {
    TestDatabase::dropTables();
});

it('exposes the new billing keys to the admin billing class', function () {
    $items = Config::getItemListByClass('billing');
    expect($items)->toContain('stripe_publishable_key')
        ->toContain('stripe_auto_billing_enabled')
        ->toContain('balance_auto_renew_enabled')
        ->toContain('stripe_grace_days');
});

it('reads stripe_grace_days as an int and the toggle as bool', function () {
    expect(Config::obtain('stripe_grace_days'))->toBe(7);
    expect(Config::obtain('stripe_auto_billing_enabled'))->toBeFalse();
});

it('renders an input for every new billing key in the admin template', function () {
    // The save JS iterates $update_field and reads $('#<item>').val(); each new
    // class='billing' key therefore needs a matching element id or its value is
    // clobbered with undefined on save.
    $tpl = file_get_contents(BASE_PATH . '/resources/views/tabler/admin/setting/billing.tpl');
    foreach ([
        'stripe_publishable_key',
        'stripe_auto_billing_enabled',
        'balance_auto_renew_enabled',
        'stripe_grace_days',
    ] as $item) {
        expect($tpl)->toContain('id="' . $item . '"');
    }
});
