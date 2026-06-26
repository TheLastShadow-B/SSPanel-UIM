<?php

declare(strict_types=1);

use App\Models\Config;
use Tests\TestDatabase;

beforeEach(function () {
    TestDatabase::init();
    $c = new Config();
    $c->item = 'cryptomus_currency';
    $c->value = 'CNY';
    $c->class = 'billing';
    $c->is_public = 1;
    $c->type = 'string';
    $c->default = '';
    $c->mark = '';
    $c->save();
});

afterEach(function () {
    TestDatabase::dropTables();
});

// Regression: a class='billing' config item whose admin form field is absent
// (e.g. cryptomus_currency, whose input was commented out of billing.tpl) is
// submitted as null and reaches Config::set(item, null). Production's `value`
// column is varchar(2048) NOT NULL, so the bare UPDATE ... SET value = NULL
// throws (MySQL 1048) and Config::set returns false -> BillingController emits
// "保存 cryptomus_currency 时出错" and aborts the whole billing save. Config::set
// must coerce null to '' so a missing param can never crash the settings save.
it('coerces a null value to empty string instead of failing on the NOT NULL column', function () {
    expect(Config::set('cryptomus_currency', null))->toBeTrue();
    expect(Config::obtain('cryptomus_currency'))->toBe('');
});

it('still persists ordinary string values unchanged', function () {
    expect(Config::set('cryptomus_currency', 'USD'))->toBeTrue();
    expect(Config::obtain('cryptomus_currency'))->toBe('USD');
});
