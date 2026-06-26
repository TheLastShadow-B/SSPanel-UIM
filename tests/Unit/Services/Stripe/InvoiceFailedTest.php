<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Stripe\StripeService;
use App\Services\Stripe\WebhookHandler;
use Carbon\Carbon;
use Stripe\StripeClient;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * P1.7 — handleInvoiceFailed (shared by invoice.payment_failed AND
 *   invoice.payment_action_required, per P1.4 routing):
 *     - set local Subscription stripe_status='past_due'
 *     - set grace_until = now + Config('stripe_grace_days') days
 *     - store the invoice's hosted_invoice_url (SCA/3DS recovery link)
 *     - KEEP SERVICE: end_date/class_expire UNCHANGED, internal status stays
 *       'active' (downgrade happens only on customer.subscription.deleted, P1.8).
 *
 * DB-backed against the real MariaDB `sspanel_test` (same infra as the merged
 * P0 / webhook-dedup / P1.5 / P1.6 tests). Test-infra precedent (matches merged
 * InvoicePaidTest / CheckoutCompletedTest): build rows with `new User()`
 * directly, NOT Tests\Factories\UserFactory (that factory needs fakerphp/faker
 * + writes columns absent from the test schema, so it cannot run here).
 *
 * handleInvoiceFailed never calls live Stripe, but we still restore an offline
 * StripeService singleton in afterEach for hygiene with the rest of the suite.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();

    // Seed the grace-days config (P0.1 key). Config::obtain reads from the DB
    // and Config::set only UPDATEs, so the row must exist here.
    $c = new Config();
    $c->item = 'stripe_grace_days';
    $c->value = '7';
    $c->type = 'int';
    $c->class = 'stripe';
    $c->save();
});

afterEach(function () {
    TestDatabase::dropTables();
    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
});

function makeFailedUser(string $customerId): User
{
    $user = new User();
    $user->email = 'fail_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'fail_buyer';
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

function seedActiveStripeSubForFail(int $userId, string $stripeSubId): Subscription
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
    $s->start_date = '2026-07-01';
    $s->end_date = '2026-07-31';
    $s->reset_day = 1;
    $s->last_reset_date = '2026-07-01';
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

function makeInvoiceFailedEvent(
    string $evtId,
    string $type,
    string $customer,
    string $sub,
    string $url
): \Stripe\Event {
    return \Stripe\Event::constructFrom([
        'id' => $evtId,
        'type' => $type,
        'data' => ['object' => [
            'id' => 'in_fail_' . bin2hex(random_bytes(4)),
            'customer' => $customer,
            'subscription' => $sub,
            'hosted_invoice_url' => $url,
        ]],
    ]);
}

it('marks past_due with grace and stores hosted url on payment_failed, keeps service', function () {
    $user = makeFailedUser('cus_f');
    $sub = seedActiveStripeSubForFail($user->id, 'sub_f');

    (new WebhookHandler())->handle(
        makeInvoiceFailedEvent('evt_f1', 'invoice.payment_failed', 'cus_f', 'sub_f', 'https://pay.stripe.test/in_fail')
    );

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->stripe_status)->toBe('past_due');
    expect($fresh->status)->toBe('active'); // service kept (no downgrade)
    expect($fresh->hosted_invoice_url)->toBe('https://pay.stripe.test/in_fail');
    expect($fresh->grace_until)->not->toBeNull();
    expect(Carbon::parse($fresh->grace_until)->greaterThan(Carbon::now()))->toBeTrue();
    // grace_until ~ now + 7 days (allow 1 day slack for clock/boundary).
    $graceDelta = abs(Carbon::parse($fresh->grace_until)->diffInDays(Carbon::now()));
    expect($graceDelta)->toBeGreaterThanOrEqual(6.0);
    expect($graceDelta)->toBeLessThanOrEqual(7.0);

    // KEEP SERVICE: dates / class_expire untouched.
    expect($fresh->end_date)->toBe('2026-07-31');
    expect((new User())->find($user->id)->class_expire)->toBe('2026-07-31 23:59:59');
});

it('also handles payment_action_required (SCA) the same way', function () {
    $user = makeFailedUser('cus_g');
    $sub = seedActiveStripeSubForFail($user->id, 'sub_g');

    (new WebhookHandler())->handle(
        makeInvoiceFailedEvent('evt_g1', 'invoice.payment_action_required', 'cus_g', 'sub_g', 'https://pay.stripe.test/sca')
    );

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->stripe_status)->toBe('past_due');
    expect($fresh->status)->toBe('active');
    expect($fresh->hosted_invoice_url)->toBe('https://pay.stripe.test/sca');
    expect($fresh->grace_until)->not->toBeNull();
    expect($fresh->end_date)->toBe('2026-07-31');
});

it('ignores a failed invoice whose customer does not match the subscription owner', function () {
    $user = makeFailedUser('cus_owner');
    $sub = seedActiveStripeSubForFail($user->id, 'sub_mismatch');

    (new WebhookHandler())->handle(
        makeInvoiceFailedEvent('evt_mismatch', 'invoice.payment_failed', 'cus_attacker', 'sub_mismatch', 'https://pay.stripe.test/x')
    );

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->stripe_status)->toBe('active'); // untouched
    expect($fresh->grace_until)->toBeNull();
    expect($fresh->hosted_invoice_url)->toBeNull();
});

it('ignores a failed invoice for a non-stripe (manual/balance) subscription', function () {
    $user = makeFailedUser('cus_manual');
    $sub = seedActiveStripeSubForFail($user->id, 'sub_manual');
    $sub->billing_provider = 'manual';
    $sub->save();

    (new WebhookHandler())->handle(
        makeInvoiceFailedEvent('evt_manual', 'invoice.payment_failed', 'cus_manual', 'sub_manual', 'https://pay.stripe.test/m')
    );

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->stripe_status)->toBe('active'); // untouched
    expect($fresh->grace_until)->toBeNull();
});

it('is idempotent: re-delivery of the same failed event keeps a single past_due result', function () {
    $user = makeFailedUser('cus_idem');
    $sub = seedActiveStripeSubForFail($user->id, 'sub_idem');

    $event = makeInvoiceFailedEvent('evt_idem', 'invoice.payment_failed', 'cus_idem', 'sub_idem', 'https://pay.stripe.test/idem');

    $handler = new WebhookHandler();
    $handler->handle($event);
    $handler->handle($event); // replay of the SAME event id

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->stripe_status)->toBe('past_due');
    expect($fresh->status)->toBe('active');
    expect($fresh->hosted_invoice_url)->toBe('https://pay.stripe.test/idem');
});
