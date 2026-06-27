<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Exchange;
use App\Services\Stripe\StripeService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Stripe\StripeClient;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * B5 — master-switch gate (config item stripe_auto_billing_enabled, type=bool).
 *
 *   processAutoRenew():   a clean kill-switch — when the switch is OFF the whole
 *                         engine no-ops (no balance deduction, no card charge,
 *                         no grace).
 *   expireSubscription(): when the switch is OFF it ALSO naturally-expires the
 *                         due auto_renew=1 pending_renewal subs that
 *                         processAutoRenew would no longer touch, so disabling
 *                         the feature does not strand them. When the switch is ON
 *                         behaviour is unchanged (auto_renew=1 stays with the
 *                         waterfall).
 *
 * Offline: StripeService + Exchange are stubbed, so no branch hits the network
 * or ext-redis. Mirrors ProcessAutoRenewTest's setup.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    TestDatabase::init();
    ensureUserMoneyLogTable();

    Config::query()->updateOrInsert(
        ['item' => 'stripe_currency'],
        ['value' => 'USD', 'class' => 'billing', 'type' => 'string']
    );
    Exchange::setInstance(fakeExchange(0.10));
});

afterEach(function () {
    dropUserMoneyLogTable();
    TestDatabase::dropTables();

    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
    Exchange::setInstance(new Exchange());
});

it('switch OFF: processAutoRenew is a kill-switch — a due auto_renew=1 sub with balance is NOT renewed', function () {
    Config::query()->updateOrInsert(
        ['item' => 'stripe_auto_billing_enabled'],
        ['value' => '0', 'class' => 'billing', 'type' => 'bool']
    );

    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(50.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    // A stored card AND sufficient balance — both would settle if the engine ran.
    $fake = fakeCardStripe('pm_card_1', 'succeeded');
    StripeService::setInstance($fake);

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    // Nothing happened: no card charge, no balance deduction, status/grace/invoice untouched.
    expect($fake->chargeCalls)->toHaveCount(0);
    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('pending_renewal');
    expect($freshSub->grace_until)->toBeNull();
    expect($freshSub->end_date)->toBe($today);
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
    expect((new User())->find($user->id)->money)->toBe(50.0);
});

it('switch OFF: expireSubscription naturally expires the due auto_renew=1 pending_renewal sub', function () {
    Config::query()->updateOrInsert(
        ['item' => 'stripe_auto_billing_enabled'],
        ['value' => '0', 'class' => 'billing', 'type' => 'bool']
    );

    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(50.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);
    $order = (new Order())->where('subscription_id', $sub->id)->first();

    ob_start();
    SubscriptionService::expireSubscription();
    ob_get_clean();

    // Falls back to natural expiry: expired + downgraded, with the renewal order/invoice cancelled.
    expect((new Subscription())->find($sub->id)->status)->toBe('expired');
    expect((int) (new User())->find($user->id)->class)->toBe(0);
    expect((new Invoice())->find($inv->id)->status)->toBe('cancelled');
    expect((new Order())->find($order->id)->status)->toBe('cancelled');
});

it('switch ON: processAutoRenew still renews from balance (regression)', function () {
    Config::query()->updateOrInsert(
        ['item' => 'stripe_auto_billing_enabled'],
        ['value' => '1', 'class' => 'billing', 'type' => 'bool']
    );

    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(50.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe(null, 'succeeded')); // balance covers it; card never reached

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('active');
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_balance');
    expect((new User())->find($user->id)->money)->toBe(20.0);
});

it('switch ON: expireSubscription leaves the due auto_renew=1 sub for the renewal waterfall', function () {
    Config::query()->updateOrInsert(
        ['item' => 'stripe_auto_billing_enabled'],
        ['value' => '1', 'class' => 'billing', 'type' => 'bool']
    );

    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(50.0, class: 3);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    ob_start();
    SubscriptionService::expireSubscription();
    ob_get_clean();

    // Untouched: still pending_renewal, not downgraded, invoice still payable.
    expect((new Subscription())->find($sub->id)->status)->toBe('pending_renewal');
    expect((int) (new User())->find($user->id)->class)->toBe(3);
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});
