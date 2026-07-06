<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Services\Stripe\WebhookHandler;
use App\Utils\Tools;
use Carbon\Carbon;
use Tests\TestDatabase;

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

function makeLegacyStripeUser(string $customerId): User
{
    $user = new User();
    $user->email = 'legacy_stripe_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'legacy_stripe';
    $user->passwd = bin2hex(random_bytes(8));
    $user->stripe_customer_id = $customerId;
    $user->class = 1;
    $user->class_expire = '2026-07-31 23:59:59';
    $user->transfer_enable = Tools::gbToB(10);
    $user->u = 123;
    $user->d = 456;
    $user->transfer_today = 78;
    $user->node_group = 0;
    $user->node_speedlimit = 0;
    $user->node_iplimit = 0;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

function makeLegacyStripeSubscription(int $userId, string $stripeSubId): Subscription
{
    $subscription = new Subscription();
    $subscription->user_id = $userId;
    $subscription->product_id = 1;
    $subscription->product_content = json_encode([
        'class' => 1,
        'bandwidth' => 10,
        'node_group' => 0,
        'speed_limit' => 0,
        'ip_limit' => 0,
    ]);
    $subscription->billing_cycle = 'month';
    $subscription->renewal_price = 10.0;
    $subscription->start_date = '2026-07-01';
    $subscription->end_date = '2026-07-31';
    $subscription->reset_day = 1;
    $subscription->last_reset_date = '2026-07-01';
    $subscription->status = 'active';
    $subscription->billing_provider = 'stripe';
    $subscription->auto_renew = 1;
    $subscription->stripe_subscription_id = $stripeSubId;
    $subscription->stripe_status = 'active';
    $subscription->created_at = date('Y-m-d H:i:s');
    $subscription->updated_at = date('Y-m-d H:i:s');
    $subscription->save();

    return $subscription;
}

it('keeps renewing existing native Stripe subscriptions on invoice.paid', function () {
    $user = makeLegacyStripeUser('cus_legacy_renew');
    $subscription = makeLegacyStripeSubscription($user->id, 'sub_legacy_renew');

    (new WebhookHandler())->handle(\Stripe\Event::constructFrom([
        'id' => 'evt_legacy_cycle',
        'type' => 'invoice.paid',
        'data' => ['object' => [
            'id' => 'in_legacy_cycle',
            'customer' => 'cus_legacy_renew',
            'subscription' => 'sub_legacy_renew',
            'billing_reason' => 'subscription_cycle',
        ]],
    ]));

    $freshSub = (new Subscription())->find($subscription->id);
    expect($freshSub->start_date)->toBe('2026-08-01');
    expect($freshSub->end_date)->toBe('2026-08-31');
    expect($freshSub->stripe_status)->toBe('active');
    expect($freshSub->last_paid_stripe_invoice_id)->toBe('in_legacy_cycle');

    $freshUser = (new User())->find($user->id);
    expect($freshUser->class_expire)->toContain('2026-08-31');
    expect((int) $freshUser->u)->toBe(0);
    expect((int) $freshUser->d)->toBe(0);
    expect((int) $freshUser->transfer_today)->toBe(0);
    expect((int) $freshUser->transfer_enable)->toBe(Tools::gbToB(10));
});

it('keeps revoking access for existing native Stripe subscriptions on invoice.payment_failed', function () {
    $user = makeLegacyStripeUser('cus_legacy_fail');
    $subscription = makeLegacyStripeSubscription($user->id, 'sub_legacy_fail');

    (new WebhookHandler())->handle(\Stripe\Event::constructFrom([
        'id' => 'evt_legacy_fail',
        'type' => 'invoice.payment_failed',
        'data' => ['object' => [
            'customer' => 'cus_legacy_fail',
            'subscription' => 'sub_legacy_fail',
            'hosted_invoice_url' => 'https://invoice.stripe.test/legacy-fail',
            'next_payment_attempt' => Carbon::parse('2026-08-02 00:00:00')->getTimestamp(),
        ]],
    ]));

    $freshSub = (new Subscription())->find($subscription->id);
    expect($freshSub->status)->toBe('expired');
    expect($freshSub->stripe_status)->toBe('past_due');
    expect((int) $freshSub->auto_renew)->toBe(0);
    expect($freshSub->hosted_invoice_url)->toBe('https://invoice.stripe.test/legacy-fail');

    $freshUser = (new User())->find($user->id);
    expect((int) $freshUser->class)->toBe(0);
    expect((int) $freshUser->transfer_enable)->toBe(0);
});

it('keeps revoking access for existing native Stripe subscriptions on customer.subscription.deleted', function () {
    $user = makeLegacyStripeUser('cus_legacy_deleted');
    $subscription = makeLegacyStripeSubscription($user->id, 'sub_legacy_deleted');

    (new WebhookHandler())->handle(\Stripe\Event::constructFrom([
        'id' => 'evt_legacy_deleted',
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => [
            'id' => 'sub_legacy_deleted',
            'customer' => 'cus_legacy_deleted',
            'status' => 'canceled',
        ]],
    ]));

    $freshSub = (new Subscription())->find($subscription->id);
    expect($freshSub->status)->toBe('cancelled');
    expect($freshSub->stripe_status)->toBe('canceled');
    expect((int) $freshSub->auto_renew)->toBe(0);

    $freshUser = (new User())->find($user->id);
    expect((int) $freshUser->class)->toBe(0);
    expect((int) $freshUser->transfer_enable)->toBe(0);
});
