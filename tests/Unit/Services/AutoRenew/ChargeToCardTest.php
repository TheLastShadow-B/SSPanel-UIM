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
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * chargeRenewalToCard — off-session fallback when balance is short.
 *
 * Both external services are stubbed via setInstance(): StripeService (no
 * network) and Exchange (no Redis/HTTP FX). That makes every branch — including
 * the CNY->currency conversion and the charge-success/decline paths — run
 * deterministically WITHOUT ext-redis. The fake Exchange models a fixed 0.10
 * rate, so 30 CNY -> 3.00 -> 300 minor units.
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

// fakeCardStripe()/fakeExchange() live in AutoRenewHelpers.php.

it('returns false and leaves the invoice unpaid when there is no stored card', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe(null, 'succeeded'));

    expect(SubscriptionService::chargeRenewalToCard($sub, $inv))->toBeFalse();
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});

it('charges the stored card, marks the invoice paid_gateway and records the stripe amount', function () {
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

it('returns false WITHOUT charging when the invoice was already settled (concurrent actor)', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    // A concurrent path (e.g. user paid the renewal manually) settled it AFTER
    // this batch selected it as unpaid. The idempotency key does not protect this.
    $inv->status = 'paid_balance';
    $inv->save();

    $fake = fakeCardStripe('pm_card_1', 'succeeded');
    StripeService::setInstance($fake);

    expect(SubscriptionService::chargeRenewalToCard($sub, $inv))->toBeFalse();
    // The re-load guard fired before chargeOffSession -> no second charge.
    expect($fake->chargeCalls)->toHaveCount(0);
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_balance');
    expect((new Subscription())->find($sub->id)->stripe_amount)->toBeNull();
});

it('returns false and never throws when the FX conversion fails (Redis/Guzzle down)', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    // The CNY->minor Exchange call blows up (GuzzleException/RedisException
    // surface as \Throwable); the caller must still get a clean false to grace.
    Exchange::setInstance(fakeExchange(new RuntimeException('FX backend unavailable')));

    $fake = fakeCardStripe('pm_card_1', 'succeeded');
    StripeService::setInstance($fake);

    // chargeRenewalToCard echoes the swallowed error (cron logging convention).
    ob_start();
    $result = SubscriptionService::chargeRenewalToCard($sub, $inv);
    ob_get_clean();

    expect($result)->toBeFalse();
    expect($fake->chargeCalls)->toHaveCount(0); // threw before the charge
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});

it('returns false and never throws when the card is declined', function () {
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

    ob_start();
    $result = SubscriptionService::chargeRenewalToCard($sub, $inv);
    ob_get_clean();

    expect($result)->toBeFalse();
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
    expect((new Subscription())->find($sub->id)->stripe_amount)->toBeNull();
});

it('returns false when the PaymentIntent is not succeeded (e.g. requires_action)', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    StripeService::setInstance(fakeCardStripe('pm_card_1', 'requires_action'));

    expect(SubscriptionService::chargeRenewalToCard($sub, $inv))->toBeFalse();
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});

it('returns false WITHOUT charging when the renewal cannot complete (invalid billing_cycle -> no orphan charge)', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);

    // A corrupt billing_cycle: advanceRenewedPeriod -> calculateEndDate match has no
    // 'week' arm and no default, so the post-charge transaction would throw
    // UnhandledMatchError. Without pre-validation the card is debited but nothing is
    // recorded (invoice stays unpaid -> grace) = customer charged for no service.
    $sub->billing_cycle = 'week';
    $sub->save();
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    $fake = fakeCardStripe('pm_card_1', 'succeeded');
    StripeService::setInstance($fake);

    ob_start();
    $result = SubscriptionService::chargeRenewalToCard($sub, $inv);
    ob_get_clean();

    expect($result)->toBeFalse();
    // The pre-validation fired BEFORE chargeOffSession -> the card is never touched.
    expect($fake->chargeCalls)->toHaveCount(0);
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
    expect((new Subscription())->find($sub->id)->stripe_amount)->toBeNull();
});

it('does not double-advance when a concurrent cron settles during the off-session charge', function () {
    // Parallel daily-cron race: both processes pass the pre-charge guard while the invoice is
    // still unpaid and each calls chargeOffSession once (Stripe's idempotency key dedupes to a
    // SINGLE real charge). Then BOTH run the settle transaction. Without a row-locked re-check
    // the second settle re-records the charge and advances the period a SECOND time = one free
    // cycle. The settle must re-load the invoice under lockForUpdate and no-op if already settled.
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(0.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    // Exactly one cycle forward from today — the only correct advance.
    $expectedEnd = SubscriptionService::calculateEndDate(Carbon::parse($today)->addDay(), 'month')->format('Y-m-d');

    // SEAM: chargeOffSession runs AFTER the pre-charge guard but BEFORE the settle transaction —
    // precisely the window a parallel actor can win. Here a *concurrent* cron process fully settles
    // the renewal (fresh instances, mimicking a separate process): invoice -> paid_balance, order ->
    // activated, period advanced ONCE. Our subsequent settle must detect this and not advance again.
    $stripe = new class (
        new StripeClient(['api_key' => 'sk_test_race']),
        (int) $inv->id,
        (int) $inv->order_id,
        (int) $sub->id
    ) extends StripeService {
        public int $chargeCount = 0;

        public function __construct(StripeClient $c, public int $invId, public int $orderId, public int $subId)
        {
            parent::__construct($c);
        }

        public function ensureCustomer(User $user): string
        {
            return $user->stripe_customer_id ?: 'cus_race';
        }

        public function getDefaultPaymentMethod(string $customerId): ?string
        {
            return 'pm_card_1';
        }

        public function chargeOffSession(
            string $customerId,
            string $paymentMethodId,
            int $amountMinor,
            string $currency,
            string $idempotencyKey,
            array $metadata = []
        ): PaymentIntent {
            $this->chargeCount++;

            // Concurrent actor's full settle (separate process -> fresh model instances).
            $concInv = (new Invoice())->find($this->invId);
            $concInv->status = 'paid_balance';
            $concInv->pay_time = time();
            $concInv->update_time = time();
            $concInv->save();

            $concOrder = (new Order())->find($this->orderId);
            $concOrder->status = 'activated';
            $concOrder->update_time = time();
            $concOrder->save();

            $concSub = (new Subscription())->find($this->subId);
            $concUser = (new User())->find($concSub->user_id);
            SubscriptionService::advanceRenewedPeriod($concSub, $concUser);

            return PaymentIntent::constructFrom(['id' => 'pi_race', 'status' => 'succeeded']);
        }
    };
    StripeService::setInstance($stripe);

    ob_start();
    $result = SubscriptionService::chargeRenewalToCard($sub, $inv);
    ob_get_clean();

    // The PI succeeded (idempotency dedupes the real charge), so the waterfall treats this as a
    // success and does NOT fall through to grace.
    expect($result)->toBeTrue();
    expect($stripe->chargeCount)->toBe(1);

    $freshSub = (new Subscription())->find($sub->id);
    // Advanced exactly ONCE (by the concurrent actor); our settle re-checked under lock and no-op'd:
    expect($freshSub->end_date)->toBe($expectedEnd);
    // ...proven by our settle never recording a stripe charge...
    expect($freshSub->stripe_amount)->toBeNull();
    // ...nor overwriting the concurrent actor's paid_balance settlement to paid_gateway...
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_balance');
    // ...nor re-touching the already-activated order.
    expect((new Order())->find($inv->order_id)->status)->toBe('activated');
});

it('reverts the invoice from processing back to unpaid when the charge fails (claim released)', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    // SEAM: capture the invoice status AT CHARGE TIME — it must be 'processing', proving the
    // claim landed BEFORE the off-session charge (closing the double-charge window) — then throw
    // a decline. The catch must release the claim ('processing' -> 'unpaid') so the user can still
    // settle the renewal manually during the grace window.
    $declined = \Stripe\Exception\CardException::factory(
        'Your card was declined.',
        402,
        null,
        null,
        null,
        'card_declined',
        'card_declined'
    );
    $stripe = new class (new StripeClient(['api_key' => 'sk_test_revert']), (int) $inv->id, $declined) extends StripeService {
        public ?string $statusAtCharge = null;

        public function __construct(StripeClient $c, public int $invId, public \Throwable $err)
        {
            parent::__construct($c);
        }

        public function ensureCustomer(User $user): string
        {
            return $user->stripe_customer_id ?: 'cus_revert';
        }

        public function getDefaultPaymentMethod(string $customerId): ?string
        {
            return 'pm_card_1';
        }

        public function chargeOffSession(
            string $customerId,
            string $paymentMethodId,
            int $amountMinor,
            string $currency,
            string $idempotencyKey,
            array $metadata = []
        ): PaymentIntent {
            $this->statusAtCharge = (new Invoice())->find($this->invId)->status;

            throw $this->err;
        }
    };
    StripeService::setInstance($stripe);

    ob_start();
    $result = SubscriptionService::chargeRenewalToCard($sub, $inv);
    ob_get_clean();

    expect($result)->toBeFalse();
    // The claim landed before the charge...
    expect($stripe->statusAtCharge)->toBe('processing');
    // ...and was released back to unpaid (still payable during grace).
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
    expect((new Subscription())->find($sub->id)->stripe_amount)->toBeNull();
});

it('reclaims a STALE processing invoice (crashed prior attempt > 600s ago) and charges it once', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    // A prior attempt claimed the invoice (-> processing) then crashed before it could settle or
    // revert, leaving a STALE lease (update_time far in the past). The next run must reclaim and
    // charge; the renew_inv_{id} idempotency key keeps Stripe to a SINGLE real charge.
    $inv->status = 'processing';
    $inv->update_time = time() - 3600;
    $inv->save();

    $fake = fakeCardStripe('pm_card_1', 'succeeded');
    StripeService::setInstance($fake);

    ob_start();
    $result = SubscriptionService::chargeRenewalToCard($sub, $inv);
    ob_get_clean();

    expect($result)->toBeTrue();
    expect($fake->chargeCalls)->toHaveCount(1);
    expect($fake->chargeCalls[0]['idempotencyKey'])->toBe('renew_inv_' . $inv->id);
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_gateway');
    expect((int) (new Subscription())->find($sub->id)->stripe_amount)->toBe(300);
});

it('does NOT reclaim a FRESH processing invoice (another worker owns the in-flight charge)', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    // Freshly claimed by a concurrent worker (recent update_time): that worker owns the charge.
    // We must NOT reclaim or charge — return false and touch nothing.
    $inv->status = 'processing';
    $inv->update_time = time();
    $inv->save();

    $fake = fakeCardStripe('pm_card_1', 'succeeded');
    StripeService::setInstance($fake);

    expect(SubscriptionService::chargeRenewalToCard($sub, $inv))->toBeFalse();
    expect($fake->chargeCalls)->toHaveCount(0);
    expect((new Invoice())->find($inv->id)->status)->toBe('processing');
    expect((new Subscription())->find($sub->id)->stripe_amount)->toBeNull();
});

it('does NOT revert the claim on an AMBIGUOUS ApiConnectionException (charge may have succeeded)', function () {
    // A connection drop thrown FROM chargeOffSession may mean the PaymentIntent create actually
    // SUCCEEDED under the renew_inv_{id} idempotency key. Reverting processing -> unpaid here would
    // let processAutoRenew enter grace on a charged renewal (charged-but-unpaid, no service). The
    // claim must STAY 'processing' so the stale-processing reclaim path retries later under the SAME
    // idempotency key (Stripe dedupes to a single real charge).
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    $apiConn = \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe.');
    StripeService::setInstance(fakeCardStripe('pm_card_1', $apiConn));

    ob_start();
    $result = SubscriptionService::chargeRenewalToCard($sub, $inv);
    ob_get_clean();

    expect($result)->toBeFalse();
    // NOT reverted — left processing for the stale-reclaim retry (the whole point of this fix).
    expect((new Invoice())->find($inv->id)->status)->toBe('processing');
    expect((new Subscription())->find($sub->id)->stripe_amount)->toBeNull();
});

it('DOES revert the claim to unpaid on a DEFINITIVE CardException decline (no charge stands)', function () {
    // A CardException is Stripe DEFINITIVELY declining: no charge stands, so revert processing ->
    // unpaid and fall through to grace with the renewal still payable by the user.
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

    ob_start();
    $result = SubscriptionService::chargeRenewalToCard($sub, $inv);
    ob_get_clean();

    expect($result)->toBeFalse();
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
    expect((new Subscription())->find($sub->id)->stripe_amount)->toBeNull();
});
