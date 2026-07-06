<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\User;
use App\Services\SubscriptionService;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenewHelpers.php';

beforeEach(function () {
    TestDatabase::init();
    ensureUserMoneyLogTable();
});

afterEach(function () {
    dropUserMoneyLogTable();
    TestDatabase::dropTables();
});

it('deducts balance and marks the invoice paid when money is enough', function () {
    $user = makeUserWithMoney(50.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    $ok = SubscriptionService::payRenewalFromBalance($sub, $inv);

    expect($ok)->toBeTrue();
    expect((new User())->find($user->id)->money)->toBe(20.0);

    $fresh = (new Invoice())->find($inv->id);
    expect($fresh->status)->toBe('paid_balance');
    expect($fresh->pay_time)->not->toBeNull();
});

it('does nothing and returns false when money is insufficient', function () {
    $user = makeUserWithMoney(10.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    expect(SubscriptionService::payRenewalFromBalance($sub, $inv))->toBeFalse();
    expect((new User())->find($user->id)->money)->toBe(10.0);
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});

it('settles when balance exactly equals the invoice price', function () {
    $user = makeUserWithMoney(30.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    expect(SubscriptionService::payRenewalFromBalance($sub, $inv))->toBeTrue();
    expect((new User())->find($user->id)->money)->toBe(0.0);
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_balance');
});

it('is idempotent: returns false and does not double-charge an already-paid invoice', function () {
    $user = makeUserWithMoney(50.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);
    $inv->status = 'paid_gateway';
    $inv->save();

    expect(SubscriptionService::payRenewalFromBalance($sub, $inv))->toBeFalse();
    expect((new User())->find($user->id)->money)->toBe(50.0);
});

it('writes a UserMoneyLog row recording the deduction', function () {
    $user = makeUserWithMoney(50.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    SubscriptionService::payRenewalFromBalance($sub, $inv);

    $log = (new App\Models\UserMoneyLog())->where('user_id', $user->id)->first();
    expect($log)->not->toBeNull();
    expect((float) $log->before)->toBe(50.0);
    expect((float) $log->after)->toBe(20.0);
    expect((float) $log->amount)->toBe(-30.0);
});
