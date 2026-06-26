<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Cache;
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
 * StripeService is always stubbed. The balance and no-card branches are
 * Redis-free and always run. The card-charge branches cross Exchange
 * (Redis-backed FX), so they skip where ext-redis is unavailable, after
 * pre-seeding a deterministic offline rate (same convention as PriceResolverTest).
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    TestDatabase::init();
    ensureUserMoneyLogTable();
});

afterEach(function () {
    if (extension_loaded('redis')) {
        (new Cache())->initRedis()->del('exchange_rate:CNY_USD');
    }

    dropUserMoneyLogTable();
    TestDatabase::dropTables();

    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
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
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('card charge crosses Exchange FX (Redis)');
    }

    Config::query()->updateOrInsert(
        ['item' => 'stripe_currency'],
        ['value' => 'USD', 'class' => 'billing', 'type' => 'string']
    );
    (new Cache())->initRedis()->setex('exchange_rate:CNY_USD', 3600, 0.10);

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
});

it('balance short and the card is declined: enters grace, not downgraded', function () {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('reaching the decline needs the FX step (Redis)');
    }

    Config::query()->updateOrInsert(
        ['item' => 'stripe_currency'],
        ['value' => 'USD', 'class' => 'billing', 'type' => 'string']
    );
    (new Cache())->initRedis()->setex('exchange_rate:CNY_USD', 3600, 0.10);

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
