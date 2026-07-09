<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderActivation;
use App\Services\SubscriptionService;
use App\Utils\Tools;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * OrderActivation — subscription 新购(subscription_id null)。
 *
 * 逻辑与 SubscriptionService::processNewSubscriptionActivation 单笔体一致:
 * SELF_MANAGED 才处理;已有 active/pending_renewal 订阅则跳过(留给 cron 在旧订阅
 * 结束后处理);激活 = 建 Subscription(auto_renew=1) + grantMembershipFromContent
 * + 订单置 activated。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

if (! function_exists('activationMakeSubOrder')) {
    function activationMakeSubOrder(
        int $userId,
        string $orderStatus = 'pending_activation',
        string $invoiceStatus = 'paid_balance',
        string $billingProvider = 'manual'
    ): Order {
        $order = new Order();
        $order->user_id = $userId;
        $order->product_id = 1;
        $order->product_type = 'subscription';
        $order->product_name = 'Pro';
        $order->product_content = json_encode([
            'class' => 1,
            'bandwidth' => 100,
            'node_group' => 0,
            'speed_limit' => 0,
            'ip_limit' => 0,
            'billing_cycle' => ['month' => true],
            'billing_cycle_selected' => 'month',
            'name' => 'Pro',
        ]);
        $order->subscription_id = null;
        $order->coupon = '';
        $order->price = 10.0;
        $order->status = $orderStatus;
        $order->billing_provider = $billingProvider;
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'product';
        $invoice->user_id = $userId;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => 'Pro (月付)', 'price' => 10.0]]);
        $invoice->price = 10.0;
        $invoice->status = $invoiceStatus;
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return $order;
    }
}

it('creates the subscription and grants membership instantly', function () {
    $user = makeUserWithMoney(0.0, class: 0, classExpire: date('Y-m-d H:i:s', strtotime('-1 day')));
    $order = activationMakeSubOrder($user->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();

    $sub = (new Subscription())->where('user_id', $user->id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->status)->toBe('active');
    expect((int) $sub->auto_renew)->toBe(1);
    expect($sub->billing_provider)->toBe('manual');
    expect($sub->billing_cycle)->toBe('month');

    $fresh = (new User())->find($user->id);
    expect((int) $fresh->class)->toBe(1);
    expect((int) $fresh->transfer_enable)->toBe(Tools::gbToB(100));

    expect((new Order())->find($order->id)->status)->toBe('activated');
});

it('skips when the user already has an active subscription (left for cron)', function () {
    $user = makeUserWithMoney(0.0);
    makeSub($user, status: 'active');
    $order = activationMakeSubOrder($user->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new Order())->find($order->id)->status)->toBe('pending_activation');
    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(1);
});

it('skips non-self-managed billing providers', function () {
    $user = makeUserWithMoney(0.0);
    $order = activationMakeSubOrder($user->id, billingProvider: 'stripe');

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(0);
});

it('is idempotent: one subscription row after two calls', function () {
    $user = makeUserWithMoney(0.0, class: 0, classExpire: date('Y-m-d H:i:s', strtotime('-1 day')));
    $order = activationMakeSubOrder($user->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();
    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();

    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(1);
});

it('keeps the cron loop working through delegation', function () {
    $user = makeUserWithMoney(0.0, class: 0, classExpire: date('Y-m-d H:i:s', strtotime('-1 day')));
    $order = activationMakeSubOrder($user->id);

    ob_start();
    SubscriptionService::processNewSubscriptionActivation();
    ob_get_clean();

    expect((new Order())->find($order->id)->status)->toBe('activated');
    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(1);
});
