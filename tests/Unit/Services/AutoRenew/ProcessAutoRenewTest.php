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
 * processAutoRenew — the balance -> card -> grace waterfall, run on the day a
 * self-managed, auto_renew=1 subscription falls due.
 *
 * Both external services are stubbed via setInstance(): StripeService (no
 * network) and Exchange (offline FX at a fixed 0.10 rate, so 30 CNY -> 300
 * minor units). No branch needs ext-redis.
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

function expectedRenewEnd(string $endDate): string
{
    return SubscriptionService::calculateEndDate(Carbon::parse($endDate)->addDay(), 'month')->format('Y-m-d');
}

it('balance covers it: renews to active, advances the period, invoice paid_balance', function () {
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(50.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe(null, 'succeeded')); // never reached

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('active');
    expect($freshSub->end_date)->toBe(expectedRenewEnd($today));
    expect($freshSub->grace_until)->toBeNull();
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_balance');
    expect((new User())->find($user->id)->money)->toBe(20.0);
    expect((new Order())->where('subscription_id', $sub->id)->first()->status)->toBe('activated');
});

it('renews a subscription whose end_date already passed (missed-cron resilience)', function () {
    $yesterday = Carbon::yesterday()->format('Y-m-d');
    $user = makeUserWithMoney(50.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $yesterday, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe(null, 'succeeded')); // never reached

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('active');
    expect($freshSub->end_date)->toBe(expectedRenewEnd($yesterday));
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_balance');
    expect((new User())->find($user->id)->money)->toBe(20.0);
});

it('advances exactly one cycle: the 5-min activation chain cannot double-advance', function () {
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(50.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe(null, 'succeeded')); // never reached

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    // processAutoRenew ALONE must fully apply the renewal: order claimed terminal
    // (activated) and the period advanced exactly one cycle — atomically with payment.
    $afterRenew = (new Subscription())->find($sub->id);
    expect($afterRenew->end_date)->toBe(expectedRenewEnd($today));
    expect((new Order())->where('subscription_id', $sub->id)->first()->status)->toBe('activated');

    // The real 5-min chain runs right after (Cron::boot order). A paid-but-non-terminal
    // renewal order would be promoted to pending_activation and advanced a SECOND time;
    // the atomic claim must make both steps a no-op here.
    ob_start();
    App\Services\Cron::processPendingOrder();
    SubscriptionService::processRenewalActivation();
    ob_get_clean();

    $afterChain = (new Subscription())->find($sub->id);
    // Still exactly one cycle from today, not two.
    expect($afterChain->end_date)->toBe(expectedRenewEnd($today));
    expect($afterChain->status)->toBe('active');
    expect((new User())->find($user->id)->money)->toBe(20.0);
});

it('isolates a failing subscription so the rest of the batch still renews', function () {
    $today = Carbon::today()->format('Y-m-d');

    // Sub A (lower id -> processed first): balance is sufficient, but its billing_cycle
    // is invalid, so advancing the period throws \UnhandledMatchError mid-renewal — an
    // unexpected, uncaught error that the per-subscription try/catch must contain.
    $userA = makeUserWithMoney(50.0, class: 2);
    $subA = makeSub($userA, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $subA->billing_cycle = 'week'; // calculateEndDate() has no 'week' arm
    $subA->save();
    $invA = makeUnpaidRenewalInvoice($userA, $subA, 30.0);

    // Sub B: healthy.
    $userB = makeUserWithMoney(50.0, class: 2);
    $subB = makeSub($userB, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $invB = makeUnpaidRenewalInvoice($userB, $subB, 30.0);

    StripeService::setInstance(fakeCardStripe(null, 'succeeded'));

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    // Sub A's failure rolled back atomically: not renewed, balance intact.
    expect((new Invoice())->find($invA->id)->status)->toBe('unpaid');
    expect((new User())->find($userA->id)->money)->toBe(50.0);

    // Sub B was still processed despite Sub A blowing up.
    expect((new Subscription())->find($subB->id)->status)->toBe('active');
    expect((new Invoice())->find($invB->id)->status)->toBe('paid_balance');
    expect((new User())->find($userB->id)->money)->toBe(20.0);
});

it('balance short and no stored card: enters grace, service kept alive (not downgraded)', function () {
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(10.0, class: 2, classExpire: $today . ' 23:59:59');
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe(null, 'succeeded')); // no card -> false

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    $expectedGrace = Carbon::parse($today)->addDays(3)->format('Y-m-d H:i:s');

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('pending_renewal');
    expect($freshSub->grace_until)->toBe($expectedGrace);
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
    expect((int) (new User())->find($user->id)->class)->toBe(2);
    expect((new User())->find($user->id)->money)->toBe(10.0);
});

it('skips subscriptions with auto_renew=0 (handled by natural expiry instead)', function () {
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(50.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe(null, 'succeeded'));

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    expect((new Subscription())->find($sub->id)->status)->toBe('pending_renewal');
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
    expect((new User())->find($user->id)->money)->toBe(50.0);
});

it('skips a subscription that has no unpaid renewal invoice', function () {
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(50.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    // no invoice created

    StripeService::setInstance(fakeCardStripe(null, 'succeeded'));

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    expect((new Subscription())->find($sub->id)->status)->toBe('pending_renewal');
    expect((new User())->find($user->id)->money)->toBe(50.0);
});

it('balance short but the card succeeds: renews to active, invoice paid_gateway', function () {
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(10.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe('pm_card_1', 'succeeded'));

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('active');
    expect($freshSub->end_date)->toBe(expectedRenewEnd($today));
    expect((int) $freshSub->stripe_amount)->toBe(300);
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_gateway');
    expect((new User())->find($user->id)->money)->toBe(10.0); // balance untouched
    expect((new Order())->where('subscription_id', $sub->id)->first()->status)->toBe('activated');
});

it('does not auto-retry a subscription already in its grace window (no re-charge during grace)', function () {
    // Already IN grace: end_date in the past, grace_until in the FUTURE, invoice still unpaid.
    // D7 (宽限内不自动重试) + D8 (single failure email) require this sub be SKIPPED on the daily
    // run — otherwise it re-runs the whole balance->card waterfall (re-charging the card and
    // re-sending the failure email) every day until the grace window expires.
    $past = Carbon::today()->subDays(2)->format('Y-m-d');
    $graceUntil = Carbon::parse($past)->addDays(3)->format('Y-m-d H:i:s'); // still in the future

    $user = makeUserWithMoney(10.0, class: 2, classExpire: $graceUntil);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $past, status: 'pending_renewal', autoRenew: 1, graceUntil: $graceUntil);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    // A stored card that WOULD succeed if the waterfall ran — this proves the grace guard,
    // not the card. If the sub were (wrongly) re-selected, balance is short -> the card path
    // would charge it.
    $fake = fakeCardStripe('pm_card_1', 'succeeded');
    StripeService::setInstance($fake);

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    // The grace guard excluded it from the selector: no charge attempted...
    expect($fake->chargeCalls)->toHaveCount(0);
    // ...no balance deducted...
    expect((new User())->find($user->id)->money)->toBe(10.0);
    // ...and status / grace_until / invoice all unchanged.
    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('pending_renewal');
    expect($freshSub->grace_until)->toBe($graceUntil);
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});

it('balance short and the card is declined: enters grace, not downgraded', function () {
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(10.0, class: 2, classExpire: $today . ' 23:59:59');
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    $declined = \Stripe\Exception\CardException::factory('Your card was declined.', 402, null, null, null, 'card_declined', 'card_declined');
    StripeService::setInstance(fakeCardStripe('pm_card_1', $declined));

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    $expectedGrace = Carbon::parse($today)->addDays(3)->format('Y-m-d H:i:s');

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('pending_renewal');
    expect($freshSub->grace_until)->toBe($expectedGrace);
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
    expect((int) (new User())->find($user->id)->class)->toBe(2);
});
