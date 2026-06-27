<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\EmailQueue;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenewHelpers.php';

beforeEach(function () {
    TestDatabase::init();

    // Master switch ON: expireSubscription then leaves auto_renew=1 subs to the
    // waterfall (its OFF-switch widening is covered by MasterSwitchGateTest).
    Config::query()->updateOrInsert(
        ['item' => 'stripe_auto_billing_enabled'],
        ['value' => '1', 'class' => 'billing', 'type' => 'bool']
    );
});
afterEach(fn () => TestDatabase::dropTables());

/*
 * ---------------------------------------------------------------------------
 * A8 — grace-aware termination.
 *   expireSubscription(): natural expiry ONLY for auto_renew=0 (user cancelled).
 *   terminateLapsed():    pending_renewal + auto_renew=1 + grace elapsed +
 *                         invoice still unpaid -> void order/invoice, expire,
 *                         downgrade, notify.
 * No Stripe / no Redis here.
 * ---------------------------------------------------------------------------
 */

it('expireSubscription naturally expires an auto_renew=0 subscription due today', function () {
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(0.0, class: 3);
    $sub = makeSub($user, endDate: $today, status: 'pending_renewal', autoRenew: 0, billingProvider: 'manual');

    ob_start();
    SubscriptionService::expireSubscription();
    ob_get_clean();

    expect((new Subscription())->find($sub->id)->status)->toBe('expired');
    expect((int) (new User())->find($user->id)->class)->toBe(0);
});

it('expireSubscription also expires an auto_renew=0 subscription whose end_date already passed', function () {
    // Missed-cron resilience: end_date in the past must still be swept (<= today).
    $yesterday = Carbon::yesterday()->format('Y-m-d');
    $user = makeUserWithMoney(0.0, class: 3);
    $sub = makeSub($user, endDate: $yesterday, status: 'pending_renewal', autoRenew: 0, billingProvider: 'manual');

    ob_start();
    SubscriptionService::expireSubscription();
    ob_get_clean();

    expect((new Subscription())->find($sub->id)->status)->toBe('expired');
    expect((int) (new User())->find($user->id)->class)->toBe(0);
});

it('expireSubscription leaves an auto_renew=1 subscription for the renewal waterfall', function () {
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(0.0, class: 3);
    $sub = makeSub($user, endDate: $today, status: 'pending_renewal', autoRenew: 1, billingProvider: 'manual');
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    ob_start();
    SubscriptionService::expireSubscription();
    ob_get_clean();

    // Untouched: still pending_renewal, not downgraded, invoice still payable.
    expect((new Subscription())->find($sub->id)->status)->toBe('pending_renewal');
    expect((int) (new User())->find($user->id)->class)->toBe(3);
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});

it('terminateLapsed leaves a subscription whose grace window is still open', function () {
    $user = makeUserWithMoney(0.0, class: 3);
    $graceFuture = Carbon::now()->addDays(2)->format('Y-m-d H:i:s');
    $sub = makeSub($user, status: 'pending_renewal', autoRenew: 1, graceUntil: $graceFuture);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    ob_start();
    SubscriptionService::terminateLapsed();
    ob_get_clean();

    expect((new Subscription())->find($sub->id)->status)->toBe('pending_renewal');
    expect((int) (new User())->find($user->id)->class)->toBe(3);
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});

it('terminateLapsed terminates a lapsed sub: voids order+invoice, expires sub, downgrades user', function () {
    $user = makeUserWithMoney(0.0, class: 3);
    $gracePast = Carbon::now()->subDay()->format('Y-m-d H:i:s');
    $sub = makeSub($user, status: 'pending_renewal', autoRenew: 1, graceUntil: $gracePast);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);
    $order = (new Order())->where('subscription_id', $sub->id)->first();

    ob_start();
    SubscriptionService::terminateLapsed();
    ob_get_clean();

    expect((new Subscription())->find($sub->id)->status)->toBe('expired');

    $freshUser = (new User())->find($user->id);
    expect((int) $freshUser->class)->toBe(0);
    expect((int) $freshUser->transfer_enable)->toBe(0);

    // Order + invoice voided so the lapsed invoice can no longer be paid.
    expect((new Invoice())->find($inv->id)->status)->toBe('cancelled');
    expect((new Order())->find($order->id)->status)->toBe('cancelled');

    // Expired notification queued.
    $queued = (new EmailQueue())->where('to_email', $user->email)->first();
    expect($queued)->not->toBeNull();
    expect($queued->template)->toBe('subscription_expired.tpl');
});

it('terminateLapsed terminates a lapsed sub whose renewal invoice is only partially paid', function () {
    // A partially_paid invoice is still OWED. If terminateLapsed skipped it (status !== 'unpaid'),
    // the sub would keep service forever on a partial payment. Per the no-refund policy the partial
    // amount is forfeited and the sub is terminated like an unpaid one.
    $user = makeUserWithMoney(0.0, class: 3);
    $gracePast = Carbon::now()->subDay()->format('Y-m-d H:i:s');
    $sub = makeSub($user, status: 'pending_renewal', autoRenew: 1, graceUntil: $gracePast);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);
    $order = (new Order())->where('subscription_id', $sub->id)->first();
    $inv->status = 'partially_paid';
    $inv->save();

    ob_start();
    SubscriptionService::terminateLapsed();
    ob_get_clean();

    expect((new Subscription())->find($sub->id)->status)->toBe('expired');

    $freshUser = (new User())->find($user->id);
    expect((int) $freshUser->class)->toBe(0);
    expect((int) $freshUser->transfer_enable)->toBe(0);

    // Order + invoice voided so the lapsed invoice can no longer be paid.
    expect((new Invoice())->find($inv->id)->status)->toBe('cancelled');
    expect((new Order())->find($order->id)->status)->toBe('cancelled');
});

it('terminateLapsed skips when the invoice was paid within the grace window', function () {
    $user = makeUserWithMoney(0.0, class: 3);
    $gracePast = Carbon::now()->subDay()->format('Y-m-d H:i:s');
    $sub = makeSub($user, status: 'pending_renewal', autoRenew: 1, graceUntil: $gracePast);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);
    // Paid during grace.
    $inv->status = 'paid_balance';
    $inv->save();

    ob_start();
    SubscriptionService::terminateLapsed();
    ob_get_clean();

    // Not terminated: invoice was settled, so leave the activation chain alone.
    expect((new Subscription())->find($sub->id)->status)->toBe('pending_renewal');
    expect((int) (new User())->find($user->id)->class)->toBe(3);
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_balance');
});
