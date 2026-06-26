<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Stripe\StripeService;
use App\Services\Stripe\WebhookHandler;
use App\Utils\Tools;
use Carbon\Carbon;
use Stripe\StripeClient;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * P1.6 — handleInvoicePaid:
 *   - billing_reason='subscription_create'  -> NO date change (the first-period
 *     grant already happened in P1.5's handleCheckoutCompleted).
 *   - billing_reason='subscription_cycle'   -> advance the local Subscription's
 *     end_date + the user's class_expire by one cycle AND reset the period's
 *     bandwidth. IDEMPOTENT: a re-delivery of the same logical invoice must not
 *     advance twice (per-event dedup in handle() + same-period guard).
 *
 * DB-backed against the real MariaDB `sspanel_test` (same as the merged P0 /
 * webhook-dedup / P1.5 tests). Test-infra precedent (matches merged
 * CheckoutCompletedTest): build rows with `new User()` directly, NOT
 * Tests\Factories\UserFactory (that factory needs fakerphp/faker + writes
 * columns absent from the test schema, so it cannot run here).
 *
 * handleInvoicePaid never calls live Stripe, but we still restore an offline
 * StripeService singleton in afterEach for hygiene with the rest of the suite.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
});

function makeInvoicePaidEvent(
    string $evtId,
    string $customer,
    string $sub,
    string $reason,
    string $invId
): \Stripe\Event {
    return \Stripe\Event::constructFrom([
        'id' => $evtId,
        'type' => 'invoice.paid',
        'data' => ['object' => [
            'id' => $invId,
            'customer' => $customer,
            'subscription' => $sub,
            'billing_reason' => $reason,
        ]],
    ]);
}

/**
 * A richer invoice.paid event that also carries a REALISTIC Stripe billing
 * period end (the next-period anchor instant — day after end_date at 00:00:00).
 * Used to exercise the cross-event-id idempotency guard, which keys on the
 * invoice id; the period fields mirror a real payload but are not the guard.
 */
function makeInvoicePaidEventWithPeriod(
    string $evtId,
    string $customer,
    string $sub,
    string $reason,
    string $invId,
    int $periodEnd
): \Stripe\Event {
    return \Stripe\Event::constructFrom([
        'id' => $evtId,
        'type' => 'invoice.paid',
        'data' => ['object' => [
            'id' => $invId,
            'customer' => $customer,
            'subscription' => $sub,
            'billing_reason' => $reason,
            'period_end' => $periodEnd,
            'lines' => ['data' => [[
                'period' => ['end' => $periodEnd],
            ]]],
        ]],
    ]);
}

function makeInvoiceUser(string $customerId): User
{
    $user = new User();
    $user->email = 'inv_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'inv_buyer';
    $user->passwd = bin2hex(random_bytes(8));
    $user->stripe_customer_id = $customerId;
    $user->class = 1;
    $user->u = 0;
    $user->d = 0;
    $user->transfer_today = 0;
    $user->transfer_enable = 0;
    $user->node_group = 0;
    $user->class_expire = '2026-07-31 23:59:59';
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

function seedStripeSub(int $userId, string $stripeSubId, string $end): Subscription
{
    $s = new Subscription();
    $s->user_id = $userId;
    $s->product_id = 1;
    $s->product_content = json_encode([
        'class' => 1,
        'bandwidth' => 10,
        'node_group' => 0,
        'speed_limit' => 0,
        'ip_limit' => 0,
    ]);
    $s->billing_cycle = 'month';
    $s->renewal_price = 10.0;
    $s->start_date = Carbon::parse($end)->subMonth()->format('Y-m-d');
    $s->end_date = $end;
    $s->reset_day = 1;
    $s->last_reset_date = $end;
    $s->status = 'active';
    $s->billing_provider = 'stripe';
    $s->auto_renew = 1;
    $s->stripe_subscription_id = $stripeSubId;
    $s->stripe_status = 'active';
    $s->created_at = date('Y-m-d H:i:s');
    $s->updated_at = date('Y-m-d H:i:s');
    $s->save();

    return $s;
}

function seedStripeOrderAndInvoice(User $user, Subscription $subscription): Invoice
{
    $order = new Order();
    $order->user_id = $user->id;
    $order->product_id = $subscription->product_id;
    $order->product_type = 'subscription';
    $order->product_name = 'Pro';
    $order->product_content = $subscription->product_content;
    $order->subscription_id = $subscription->id;
    $order->coupon = '';
    $order->price = $subscription->renewal_price;
    $order->status = 'activated';
    $order->billing_provider = 'stripe';
    $order->create_time = time();
    $order->update_time = time();
    $order->save();

    $invoice = new Invoice();
    $invoice->user_id = $user->id;
    $invoice->order_id = $order->id;
    $invoice->content = json_encode([]);
    $invoice->price = $order->price;
    $invoice->status = 'unpaid';
    $invoice->create_time = time();
    $invoice->update_time = time();
    $invoice->pay_time = 0;
    $invoice->type = 'product';
    $invoice->billing_provider = 'stripe';
    $invoice->save();

    return $invoice;
}

it('does not extend dates on subscription_create', function () {
    $user = makeInvoiceUser('cus_inv');
    $sub = seedStripeSub($user->id, 'sub_inv', '2026-07-31');

    (new WebhookHandler())->handle(
        makeInvoicePaidEvent('evt_create', 'cus_inv', 'sub_inv', 'subscription_create', 'in_create')
    );

    expect((new Subscription())->find($sub->id)->end_date)->toBe('2026-07-31');
    // class_expire was set by the (simulated) first-period grant; create is a no-op.
    expect((new User())->find($user->id)->class_expire)->toContain('2026-07-31');
});

it('marks the local invoice paid on subscription_create without extending dates', function () {
    $user = makeInvoiceUser('cus_create_invoice');
    $sub = seedStripeSub($user->id, 'sub_create_invoice', '2026-07-31');
    $invoice = seedStripeOrderAndInvoice($user, $sub);

    (new WebhookHandler())->handle(
        makeInvoicePaidEvent('evt_create_invoice', 'cus_create_invoice', 'sub_create_invoice', 'subscription_create', 'in_create')
    );

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->end_date)->toBe('2026-07-31');

    $freshInvoice = (new Invoice())->find($invoice->id);
    expect($freshInvoice->status)->toBe('paid_gateway');
    expect((int) $freshInvoice->pay_time)->toBeGreaterThan(0);
});

it('advances end_date and class_expire on subscription_cycle and resets bandwidth', function () {
    $user = makeInvoiceUser('cus_inv2');
    // dirty the period's bandwidth so we can prove the reset fired.
    $user->u = 12345;
    $user->d = 67890;
    $user->transfer_today = 111;
    $user->save();

    $sub = seedStripeSub($user->id, 'sub_inv2', '2026-07-31');

    (new WebhookHandler())->handle(
        makeInvoicePaidEvent('evt_cycle', 'cus_inv2', 'sub_inv2', 'subscription_cycle', 'in_cycle')
    );

    $fresh = (new Subscription())->find($sub->id);
    // newStart = 2026-07-31 + 1 day = 2026-08-01; newEnd = +1 month -1 day = 2026-08-31.
    expect($fresh->start_date)->toBe('2026-08-01');
    expect($fresh->end_date)->toBe('2026-08-31');

    $freshUser = (new User())->find($user->id);
    expect($freshUser->class_expire)->toContain('2026-08-31');
    // bandwidth reset for the new period.
    expect((int) $freshUser->u)->toBe(0);
    expect((int) $freshUser->d)->toBe(0);
    expect((int) $freshUser->transfer_today)->toBe(0);
    expect((int) $freshUser->transfer_enable)->toBe(Tools::gbToB(10));
});

it('is idempotent: re-delivery of the same invoice.paid does not advance twice', function () {
    $user = makeInvoiceUser('cus_inv3');
    $sub = seedStripeSub($user->id, 'sub_inv3', '2026-07-31');

    $event = makeInvoicePaidEvent('evt_replay', 'cus_inv3', 'sub_inv3', 'subscription_cycle', 'in_cycle');

    $handler = new WebhookHandler();
    $handler->handle($event);
    $handler->handle($event); // replay of the SAME event id

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->end_date)->toBe('2026-08-31'); // advanced exactly once
    expect((new StripeEvent())->where('event_id', 'evt_replay')->count())->toBe(1);
});

it('does not advance twice when the SAME invoice id arrives under a different event id', function () {
    $user = makeInvoiceUser('cus_inv4');
    $sub = seedStripeSub($user->id, 'sub_inv4', '2026-07-31');

    // REALISTIC Stripe anchor: period.end is the NEXT period's start instant,
    // i.e. the day AFTER end_date at 00:00:00 (2026-09-01 00:00:00) — strictly
    // LATER than end-of-day(end_date). This is exactly the value the old
    // endOfDay/period-end guard mishandled; the invoice-id guard ignores it.
    $periodEnd = Carbon::parse('2026-09-01 00:00:00')->getTimestamp();

    $handler = new WebhookHandler();
    // First delivery: advances 2026-07-31 -> 2026-08-31 and stamps the invoice id.
    $handler->handle(
        makeInvoicePaidEventWithPeriod('evt_p1', 'cus_inv4', 'sub_inv4', 'subscription_cycle', 'in_same', $periodEnd)
    );
    $afterFirst = (new Subscription())->find($sub->id);
    expect($afterFirst->end_date)->toBe('2026-08-31');
    expect($afterFirst->last_paid_stripe_invoice_id)->toBe('in_same');

    // Second delivery: DIFFERENT event id (StripeEvent dedup will NOT catch it),
    // SAME invoice id -> the invoice-id guard must block a second advance.
    $handler->handle(
        makeInvoicePaidEventWithPeriod('evt_p2', 'cus_inv4', 'sub_inv4', 'subscription_cycle', 'in_same', $periodEnd)
    );

    expect((new Subscription())->find($sub->id)->end_date)->toBe('2026-08-31'); // still once
});

it('advances again on the genuine NEXT cycle (different invoice id)', function () {
    $user = makeInvoiceUser('cus_inv5');
    $sub = seedStripeSub($user->id, 'sub_inv5', '2026-07-31');

    $handler = new WebhookHandler();
    // Cycle 1: 2026-07-31 -> 2026-08-31 (invoice in_c1).
    $handler->handle(makeInvoicePaidEventWithPeriod(
        'evt_c1', 'cus_inv5', 'sub_inv5', 'subscription_cycle', 'in_c1',
        Carbon::parse('2026-09-01 00:00:00')->getTimestamp()
    ));
    expect((new Subscription())->find($sub->id)->end_date)->toBe('2026-08-31');

    // Cycle 2: a NEW invoice id -> must advance 2026-08-31 -> 2026-09-30.
    $handler->handle(makeInvoicePaidEventWithPeriod(
        'evt_c2', 'cus_inv5', 'sub_inv5', 'subscription_cycle', 'in_c2',
        Carbon::parse('2026-10-01 00:00:00')->getTimestamp()
    ));
    $afterSecond = (new Subscription())->find($sub->id);
    expect($afterSecond->end_date)->toBe('2026-09-30');
    expect($afterSecond->last_paid_stripe_invoice_id)->toBe('in_c2');
});

it('ignores a cycle invoice whose customer does not match the subscription', function () {
    $user = makeInvoiceUser('cus_owner');
    $sub = seedStripeSub($user->id, 'sub_mismatch', '2026-07-31');

    // event carries a DIFFERENT customer than the subscription's owner.
    (new WebhookHandler())->handle(
        makeInvoicePaidEvent('evt_mismatch', 'cus_attacker', 'sub_mismatch', 'subscription_cycle', 'in_x')
    );

    expect((new Subscription())->find($sub->id)->end_date)->toBe('2026-07-31'); // untouched
});

it('ignores a cycle invoice for a non-stripe (manual/balance) subscription', function () {
    $user = makeInvoiceUser('cus_manual');
    $sub = seedStripeSub($user->id, 'sub_manual', '2026-07-31');
    $sub->billing_provider = 'manual';
    $sub->save();

    (new WebhookHandler())->handle(
        makeInvoicePaidEvent('evt_manual', 'cus_manual', 'sub_manual', 'subscription_cycle', 'in_m')
    );

    expect((new Subscription())->find($sub->id)->end_date)->toBe('2026-07-31'); // untouched
});

it('marks a stripe subscription expired and revokes access on invoice payment failure', function () {
    $user = makeInvoiceUser('cus_fail');
    $user->class = 1;
    $user->transfer_enable = Tools::gbToB(10);
    $user->save();

    $sub = seedStripeSub($user->id, 'sub_fail', '2026-07-31');

    (new WebhookHandler())->handle(\Stripe\Event::constructFrom([
        'id' => 'evt_fail',
        'type' => 'invoice.payment_failed',
        'data' => ['object' => [
            'customer' => 'cus_fail',
            'subscription' => 'sub_fail',
            'hosted_invoice_url' => 'https://invoice.stripe.test/fail',
            'next_payment_attempt' => Carbon::parse('2026-08-02 00:00:00')->getTimestamp(),
        ]],
    ]));

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->status)->toBe('expired');
    expect($fresh->stripe_status)->toBe('past_due');
    expect((int) $fresh->auto_renew)->toBe(0);
    expect($fresh->hosted_invoice_url)->toBe('https://invoice.stripe.test/fail');

    $freshUser = (new User())->find($user->id);
    expect((int) $freshUser->class)->toBe(0);
    expect((int) $freshUser->transfer_enable)->toBe(0);
});

it('marks a stripe subscription cancelled and revokes access on subscription deleted', function () {
    $user = makeInvoiceUser('cus_deleted');
    $user->class = 1;
    $user->transfer_enable = Tools::gbToB(10);
    $user->save();

    $sub = seedStripeSub($user->id, 'sub_deleted', '2026-07-31');

    (new WebhookHandler())->handle(\Stripe\Event::constructFrom([
        'id' => 'evt_deleted',
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => [
            'id' => 'sub_deleted',
            'customer' => 'cus_deleted',
            'status' => 'canceled',
        ]],
    ]));

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->status)->toBe('cancelled');
    expect($fresh->stripe_status)->toBe('canceled');
    expect((int) $fresh->auto_renew)->toBe(0);

    $freshUser = (new User())->find($user->id);
    expect((int) $freshUser->class)->toBe(0);
    expect((int) $freshUser->transfer_enable)->toBe(0);
});
