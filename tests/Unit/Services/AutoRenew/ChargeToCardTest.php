<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Cache;
use App\Services\Stripe\StripeService;
use App\Services\SubscriptionService;
use Stripe\StripeClient;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * chargeRenewalToCard — off-session fallback when balance is short.
 *
 * StripeService is always stubbed (a subclass via setInstance), so no network.
 * The "no stored card -> false" branch returns BEFORE the CNY->currency FX step
 * and so is Redis-free and always runs. The charge-success / decline branches
 * cross Exchange (Redis-backed FX), so — like PriceResolverTest — they skip
 * where ext-redis is unavailable, after pre-seeding a deterministic offline rate.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    TestDatabase::init();
});

afterEach(function () {
    if (extension_loaded('redis')) {
        (new Cache())->initRedis()->del('exchange_rate:CNY_USD');
    }

    TestDatabase::dropTables();

    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
});

// fakeCardStripe() lives in AutoRenewHelpers.php (shared with ProcessAutoRenewTest).

it('returns false and leaves the invoice unpaid when there is no stored card', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe(null, 'succeeded'));

    expect(SubscriptionService::chargeRenewalToCard($sub, $inv))->toBeFalse();
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});

it('charges the stored card, marks the invoice paid_gateway and records the stripe amount', function () {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('chargeRenewalToCard FX conversion needs Exchange (Redis)');
    }

    Config::query()->updateOrInsert(
        ['item' => 'stripe_currency'],
        ['value' => 'USD', 'class' => 'billing', 'type' => 'string']
    );
    (new Cache())->initRedis()->setex('exchange_rate:CNY_USD', 3600, 0.10);

    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    $fake = fakeCardStripe('pm_card_1', 'succeeded');
    StripeService::setInstance($fake);

    expect(SubscriptionService::chargeRenewalToCard($sub, $inv))->toBeTrue();
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_gateway');

    // 30 CNY * 0.10 = 3.00 USD -> 300 minor units.
    $freshSub = (new Subscription())->find($sub->id);
    expect((int) $freshSub->stripe_amount)->toBe(300);
    expect($freshSub->stripe_currency)->toBe('USD');

    expect($fake->chargeCalls)->toHaveCount(1);
    expect($fake->chargeCalls[0]['amountMinor'])->toBe(300);
    expect($fake->chargeCalls[0]['idempotencyKey'])->toBe('renew_inv_' . $inv->id);
    expect($fake->chargeCalls[0]['metadata']['invoice_id'])->toBe((string) $inv->id);
});

it('returns false and never throws when the card is declined', function () {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('reaching chargeOffSession needs the FX step (Redis)');
    }

    Config::query()->updateOrInsert(
        ['item' => 'stripe_currency'],
        ['value' => 'USD', 'class' => 'billing', 'type' => 'string']
    );
    (new Cache())->initRedis()->setex('exchange_rate:CNY_USD', 3600, 0.10);

    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    $declined = \Stripe\Exception\CardException::factory(
        'Your card was declined.',
        402,
        null,
        null,
        null,
        'card_declined',
        'card_declined'
    );
    StripeService::setInstance(fakeCardStripe('pm_card_1', $declined));

    expect(SubscriptionService::chargeRenewalToCard($sub, $inv))->toBeFalse();
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
    expect((new Subscription())->find($sub->id)->stripe_amount)->toBeNull();
});

it('returns false when the PaymentIntent is not succeeded (e.g. requires_action)', function () {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('reaching chargeOffSession needs the FX step (Redis)');
    }

    Config::query()->updateOrInsert(
        ['item' => 'stripe_currency'],
        ['value' => 'USD', 'class' => 'billing', 'type' => 'string']
    );
    (new Cache())->initRedis()->setex('exchange_rate:CNY_USD', 3600, 0.10);

    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe('pm_card_1', 'requires_action'));

    expect(SubscriptionService::chargeRenewalToCard($sub, $inv))->toBeFalse();
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});
