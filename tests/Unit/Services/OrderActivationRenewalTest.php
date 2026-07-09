<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderActivation;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * OrderActivation — subscription 续费(subscription_id 非空)。
 * 逻辑与 SubscriptionService::processRenewalActivation 单笔体一致:
 * advanceRenewedPeriod 推进周期 + 订单置 activated。提前付款语义不变:
 * newStart = end_date + 1 天,提前激活不吃亏。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

if (! function_exists('activationMakeRenewalOrder')) {
    function activationMakeRenewalOrder(int $userId, int $subscriptionId): Order
    {
        $order = new Order();
        $order->user_id = $userId;
        $order->product_id = 1;
        $order->product_type = 'subscription';
        $order->product_name = 'Pro';
        $order->product_content = json_encode(['name' => 'Pro', 'billing_cycle_selected' => 'month']);
        $order->subscription_id = $subscriptionId;
        $order->coupon = '';
        $order->price = 10.0;
        $order->status = 'pending_activation';
        $order->billing_provider = 'manual';
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'product';
        $invoice->user_id = $userId;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => 'Pro 续费', 'price' => 10.0]]);
        $invoice->price = 10.0;
        $invoice->status = 'paid_balance';
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return $order;
    }
}

it('advances the subscription period and activates the renewal order', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user);
    $endBefore = $sub->end_date;
    $order = activationMakeRenewalOrder($user->id, (int) $sub->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();

    $freshSub = (new Subscription())->find($sub->id);
    $expectedStart = Carbon::parse($endBefore)->addDay()->format('Y-m-d');
    expect($freshSub->start_date)->toBe($expectedStart);
    expect($freshSub->status)->toBe('active');
    expect($freshSub->grace_until)->toBeNull();

    expect((new Order())->find($order->id)->status)->toBe('activated');

    $freshUser = (new User())->find($user->id);
    expect($freshUser->class_expire)->toBe($freshSub->end_date . ' 23:59:59');
});

it('returns false when the linked subscription is missing', function () {
    $user = makeUserWithMoney(0.0);
    $order = activationMakeRenewalOrder($user->id, 999999);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new Order())->find($order->id)->status)->toBe('pending_activation');
});

it('is idempotent: the period advances exactly once', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user);
    $order = activationMakeRenewalOrder($user->id, (int) $sub->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();
    $endAfterFirst = (new Subscription())->find($sub->id)->end_date;

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new Subscription())->find($sub->id)->end_date)->toBe($endAfterFirst);
});

it('keeps the cron renewal loop working through delegation', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user);
    $order = activationMakeRenewalOrder($user->id, (int) $sub->id);

    ob_start();
    SubscriptionService::processRenewalActivation();
    ob_get_clean();

    expect((new Order())->find($order->id)->status)->toBe('activated');
});
