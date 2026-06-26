<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Services\Stripe\StripeService;
use App\Services\Stripe\WebhookHandler;
use Stripe\StripeClient;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * P1.8 — handleSubscriptionDeleted (customer.subscription.deleted):
 *     - set local Subscription status='expired', stripe_status='canceled'
 *     - DOWNGRADE the user (the ONLY Stripe-leg downgrade path), mirroring
 *       SubscriptionService::expireSubscription's user field writes EXACTLY:
 *       class=0, transfer_enable=0, node_group=0, node_speedlimit=0,
 *       node_iplimit=0, u=0, d=0, transfer_today=0.
 *     - S5: bind via stripe_subscription_id, then assert the subscription's
 *       owner is the customer on the event before acting.
 *     - Only act on billing_provider='stripe'.
 *     - IDEMPOTENT: a re-delivery (already-expired subscription) is a safe
 *       no-op — no throw, no harmful re-downgrade.
 *
 * DB-backed against the real MariaDB `sspanel_test` (same infra as the merged
 * P0 / webhook-dedup / P1.5 / P1.6 / P1.7 tests). Test-infra precedent (matches
 * merged InvoiceFailedTest / InvoicePaidTest / CheckoutCompletedTest): build
 * rows with `new User()` directly, NOT Tests\Factories\UserFactory (that factory
 * needs fakerphp/faker + writes columns absent from the test schema, so it
 * cannot run here).
 *
 * handleSubscriptionDeleted never calls live Stripe, but we still restore an
 * offline StripeService singleton in afterEach for hygiene with the rest of the
 * suite.
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

function makeDeletedUser(string $customerId): User
{
    $user = new User();
    $user->email = 'del_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'del_buyer';
    $user->passwd = bin2hex(random_bytes(8));
    $user->stripe_customer_id = $customerId;
    $user->class = 3;
    $user->u = 12345;
    $user->d = 67890;
    $user->transfer_today = 111;
    $user->transfer_enable = 1099511627776; // 1TB
    $user->node_group = 2;
    $user->node_speedlimit = 100;
    $user->node_iplimit = 5;
    $user->class_expire = '2026-07-31 23:59:59';
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

function seedActiveStripeSubForDelete(int $userId, string $stripeSubId): Subscription
{
    $s = new Subscription();
    $s->user_id = $userId;
    $s->product_id = 1;
    $s->product_content = json_encode([
        'class' => 3,
        'bandwidth' => 10,
        'node_group' => 2,
        'speed_limit' => 100,
        'ip_limit' => 5,
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
    $s->stripe_status = 'past_due';
    $s->created_at = date('Y-m-d H:i:s');
    $s->updated_at = date('Y-m-d H:i:s');
    $s->save();

    return $s;
}

function makeSubDeletedEvent(string $evtId, string $customer, string $subId): \Stripe\Event
{
    return \Stripe\Event::constructFrom([
        'id' => $evtId,
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['id' => $subId, 'customer' => $customer]],
    ]);
}

it('expires the subscription and downgrades the user (all fields)', function () {
    $user = makeDeletedUser('cus_del');
    $sub = seedActiveStripeSubForDelete($user->id, 'sub_del');

    (new WebhookHandler())->handle(makeSubDeletedEvent('evt_del', 'cus_del', 'sub_del'));

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->status)->toBe('expired');
    expect($fresh->stripe_status)->toBe('canceled');

    // Downgrade parity with SubscriptionService::expireSubscription — EXACT.
    $u = (new User())->find($user->id);
    expect((int) $u->class)->toBe(0);
    expect((int) $u->transfer_enable)->toBe(0);
    expect((int) $u->node_group)->toBe(0);
    expect((int) $u->node_speedlimit)->toBe(0);
    expect((int) $u->node_iplimit)->toBe(0);
    expect((int) $u->u)->toBe(0);
    expect((int) $u->d)->toBe(0);
    expect((int) $u->transfer_today)->toBe(0);
});

it('ignores deletion when the customer does not match the subscription owner (S5)', function () {
    $user = makeDeletedUser('cus_owner');
    $sub = seedActiveStripeSubForDelete($user->id, 'sub_mismatch');

    (new WebhookHandler())->handle(makeSubDeletedEvent('evt_mismatch', 'cus_attacker', 'sub_mismatch'));

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->status)->toBe('active'); // untouched
    expect($fresh->stripe_status)->toBe('past_due');

    $u = (new User())->find($user->id);
    expect((int) $u->class)->toBe(3); // not downgraded
    expect((int) $u->node_group)->toBe(2);
});

it('ignores deletion for a non-stripe (manual/balance) subscription', function () {
    $user = makeDeletedUser('cus_manual');
    $sub = seedActiveStripeSubForDelete($user->id, 'sub_manual');
    $sub->billing_provider = 'manual';
    $sub->save();

    (new WebhookHandler())->handle(makeSubDeletedEvent('evt_manual', 'cus_manual', 'sub_manual'));

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->status)->toBe('active'); // untouched
    expect($fresh->stripe_status)->toBe('past_due');

    $u = (new User())->find($user->id);
    expect((int) $u->class)->toBe(3); // not downgraded
});

it('is a no-op when there is no local subscription for the stripe id', function () {
    $user = makeDeletedUser('cus_orphan');
    // No subscription seeded for 'sub_orphan'.

    (new WebhookHandler())->handle(makeSubDeletedEvent('evt_orphan', 'cus_orphan', 'sub_orphan'));

    $u = (new User())->find($user->id);
    expect((int) $u->class)->toBe(3); // untouched
});

it('is idempotent: replay of the same deleted event keeps a single expired result', function () {
    $user = makeDeletedUser('cus_idem');
    $sub = seedActiveStripeSubForDelete($user->id, 'sub_idem');

    $event = makeSubDeletedEvent('evt_idem', 'cus_idem', 'sub_idem');

    $handler = new WebhookHandler();
    $handler->handle($event);
    $handler->handle($event); // replay of the SAME event id (StripeEvent dedup)

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->status)->toBe('expired');
    expect($fresh->stripe_status)->toBe('canceled');

    $u = (new User())->find($user->id);
    expect((int) $u->class)->toBe(0);
});

it('is a safe no-op on a second delivery of an already-expired subscription (different event id)', function () {
    $user = makeDeletedUser('cus_already');
    $sub = seedActiveStripeSubForDelete($user->id, 'sub_already');

    // First deletion: expire + downgrade.
    (new WebhookHandler())->handle(makeSubDeletedEvent('evt_first', 'cus_already', 'sub_already'));

    // The user later re-upgrades (e.g. manual grant) while the sub stays expired.
    $u = (new User())->find($user->id);
    $u->class = 5;
    $u->transfer_enable = 1099511627776;
    $u->node_group = 9;
    $u->save();

    // A SECOND, DIFFERENT delivery of subscription.deleted for the same (already
    // expired) subscription must NOT re-downgrade the re-upgraded user.
    (new WebhookHandler())->handle(makeSubDeletedEvent('evt_second', 'cus_already', 'sub_already'));

    $fresh = (new Subscription())->find($sub->id);
    expect($fresh->status)->toBe('expired'); // unchanged

    $u2 = (new User())->find($user->id);
    expect((int) $u2->class)->toBe(5); // NOT re-downgraded
    expect((int) $u2->node_group)->toBe(9);
});
