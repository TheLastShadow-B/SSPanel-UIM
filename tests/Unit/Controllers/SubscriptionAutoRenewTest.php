<?php

declare(strict_types=1);

use App\Controllers\User\SubscriptionController;
use App\Models\Subscription;
use App\Models\User;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * P1 — user opt-out for auto-renew.
 *
 * New subscriptions default to auto_renew=1 (opt-out); these endpoints let the
 * OWNER turn it off (取消自动续费) or back on (开启自动续费). DB-backed against the
 * real MariaDB sspanel_test, no network.
 *
 * SECURITY: each endpoint acts ONLY on $this->user's own active/pending_renewal
 * subscription — a subscription id is NEVER read from the request, so one user
 * can never touch another's sub. Cancelling lets the sub run out its current
 * period and then expire naturally (SubscriptionService::expireSubscription
 * already handles auto_renew=0).
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    global $user;
    $user = null;
    TestDatabase::dropTables();
});

function makeAutoRenewUser(): User
{
    $user = new User();
    $user->email = 'autorenew_ctrl_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'autorenew_ctrl';
    $user->passwd = bin2hex(random_bytes(8));
    $user->class = 1;
    $user->transfer_enable = 0;
    $user->class_expire = date('Y-m-d H:i:s', strtotime('+30 days'));
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

function makeAutoRenewSub(User $user, int $autoRenew = 1, string $status = 'active'): Subscription
{
    $sub = new Subscription();
    $sub->user_id = $user->id;
    $sub->product_id = 1;
    $sub->product_content = json_encode(['name' => 'Pro', 'bandwidth' => 100]);
    $sub->billing_cycle = 'month';
    $sub->renewal_price = 30.0;
    $sub->start_date = date('Y-m-d', strtotime('-1 day'));
    $sub->end_date = date('Y-m-d', strtotime('+29 days'));
    $sub->reset_day = 1;
    $sub->last_reset_date = date('Y-m-d');
    $sub->status = $status;
    $sub->billing_provider = 'manual';
    $sub->auto_renew = $autoRenew;
    $sub->created_at = '2026-01-01 00:00:00';
    $sub->updated_at = '2026-01-01 00:00:00';
    $sub->save();

    return $sub;
}

function autoRenewRequest(): \Slim\Http\ServerRequest
{
    return (new \Slim\Http\Factory\DecoratedServerRequestFactory(new \GuzzleHttp\Psr7\HttpFactory()))
        ->createServerRequest('POST', '/user/subscription/cancel');
}

function autoRenewResponse(): \Slim\Http\Response
{
    return new \Slim\Http\Response(new \GuzzleHttp\Psr7\Response(), new \GuzzleHttp\Psr7\HttpFactory());
}

it('cancelAutoRenew sets the caller\'s own active subscription auto_renew=0', function () {
    $user = makeAutoRenewUser();
    $sub = makeAutoRenewSub($user, 1);
    $GLOBALS['user'] = $user;

    $response = (new SubscriptionController())->cancelAutoRenew(autoRenewRequest(), autoRenewResponse(), []);

    $json = json_decode((string) $response->getBody(), true);
    expect($json['ret'])->toBe(1);
    expect((int) (new Subscription())->find($sub->id)->auto_renew)->toBe(0);
});

it('cancelAutoRenew also acts on a pending_renewal subscription', function () {
    $user = makeAutoRenewUser();
    $sub = makeAutoRenewSub($user, 1, 'pending_renewal');
    $GLOBALS['user'] = $user;

    $response = (new SubscriptionController())->cancelAutoRenew(autoRenewRequest(), autoRenewResponse(), []);

    expect(json_decode((string) $response->getBody(), true)['ret'])->toBe(1);
    expect((int) (new Subscription())->find($sub->id)->auto_renew)->toBe(0);
});

it('enableAutoRenew sets the caller\'s own subscription auto_renew=1', function () {
    $user = makeAutoRenewUser();
    $sub = makeAutoRenewSub($user, 0);
    $GLOBALS['user'] = $user;

    $response = (new SubscriptionController())->enableAutoRenew(autoRenewRequest(), autoRenewResponse(), []);

    $json = json_decode((string) $response->getBody(), true);
    expect($json['ret'])->toBe(1);
    expect((int) (new Subscription())->find($sub->id)->auto_renew)->toBe(1);
});

it('cancelAutoRenew is a graceful ret:0 when the caller has no active subscription (no exception)', function () {
    $user = makeAutoRenewUser();
    // An expired sub must NOT be matched by the active/pending_renewal filter.
    makeAutoRenewSub($user, 1, 'expired');
    $GLOBALS['user'] = $user;

    $response = (new SubscriptionController())->cancelAutoRenew(autoRenewRequest(), autoRenewResponse(), []);

    expect(json_decode((string) $response->getBody(), true)['ret'])->toBe(0);
});

it('enableAutoRenew is a graceful ret:0 when the caller has no active subscription', function () {
    $user = makeAutoRenewUser();
    $GLOBALS['user'] = $user;

    $response = (new SubscriptionController())->enableAutoRenew(autoRenewRequest(), autoRenewResponse(), []);

    expect(json_decode((string) $response->getBody(), true)['ret'])->toBe(0);
});

it('cancelAutoRenew touches ONLY the caller\'s own subscription, never another user\'s', function () {
    $userA = makeAutoRenewUser();
    $subA = makeAutoRenewSub($userA, 1);

    $userB = makeAutoRenewUser();
    $subB = makeAutoRenewSub($userB, 1);

    // B is the authenticated caller; no subscription id is read from the request.
    $GLOBALS['user'] = $userB;
    (new SubscriptionController())->cancelAutoRenew(autoRenewRequest(), autoRenewResponse(), []);

    // Only B's sub flipped; A's is untouched.
    expect((int) (new Subscription())->find($subB->id)->auto_renew)->toBe(0);
    expect((int) (new Subscription())->find($subA->id)->auto_renew)->toBe(1);
});
