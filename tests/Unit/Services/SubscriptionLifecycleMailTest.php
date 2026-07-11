<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\EmailQueue;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\OrderActivation;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * 订阅生命周期「好消息」通知:开通成功 + 续费成功。
 *
 * 挂点:
 *  - OrderActivation::activateNewSubscription / activateRenewal —— 通知在
 *    tryActivate 的事务提交后发出(IM 分支是同步网络调用,不得拖在事务里),
 *    通知失败绝不影响激活结果。
 *  - SubscriptionService::processAutoRenew 的 $renewed 汇合点 —— 余额/存档卡
 *    两条腿共用,结算事务已提交。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
    ensureUserMoneyLogTable();
});

afterEach(function () {
    dropUserMoneyLogTable();
    TestDatabase::dropTables();
});

if (! function_exists('lifecycleMakeSubOrder')) {
    /**
     * 一笔已用余额结清、等待激活的新购订阅订单(镜像 OrderController::subscription
     * 余额结算后的落库状态)。
     */
    function lifecycleMakeSubOrder(int $userId): Order
    {
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
        $order->status = 'pending_activation';
        $order->billing_provider = 'manual';
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'product';
        $invoice->user_id = $userId;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => 'Pro (月付)', 'price' => 10.0]]);
        $invoice->price = 10.0;
        $invoice->status = 'paid_balance';
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return $order;
    }
}

it('queues an activation mail after a new subscription activates', function () {
    $user = makeUserWithMoney(0.0, class: 0, classExpire: date('Y-m-d H:i:s', strtotime('-1 day')));
    $order = lifecycleMakeSubOrder((int) $user->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();

    $row = (new EmailQueue())->where('template', 'subscription_activated.tpl')->first();
    expect($row)->not->toBeNull();
    expect($row->to_email)->toBe($user->email);
    expect($row->subject)->toContain('订阅开通成功');

    $payload = json_decode($row->array, true);
    expect($payload['plan_name'])->toBe('Pro');
    expect($payload['billing_cycle_text'])->toBe('月付');
    expect($payload['start_date'])->toBe(Carbon::today()->format('Y-m-d'));
    expect($payload)->toHaveKey('end_date');

    // 重复调用不激活也不重复入队
    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new EmailQueue())->where('template', 'subscription_activated.tpl')->count())->toBe(1);
});

it('queues a renewal-success mail with the payment method when a paid renewal activates', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, renewalPrice: 30.0);
    $inv = makeUnpaidRenewalInvoice($user, $sub, 30.0);
    $inv->status = 'paid_balance';
    $inv->save();

    $order = (new Order())->where('subscription_id', $sub->id)->first();

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();

    $row = (new EmailQueue())->where('template', 'subscription_renewed.tpl')->first();
    expect($row)->not->toBeNull();
    expect($row->subject)->toContain('订阅续费成功');

    $payload = json_decode($row->array, true);
    expect($payload['plan_name'])->toBe('Pro');
    expect($payload['payment_method_text'])->toBe('账户余额');
    // end_date 必须是推进后的新周期
    expect($payload['end_date'])->toBe((new Subscription())->find($sub->id)->end_date);
});

it('queues a renewal-success mail from the balance auto-renew leg', function () {
    Config::query()->updateOrInsert(
        ['item' => 'balance_auto_renew_enabled'],
        ['value' => '1', 'class' => 'billing', 'type' => 'bool']
    );
    Config::query()->updateOrInsert(
        ['item' => 'stripe_auto_billing_enabled'],
        ['value' => '0', 'class' => 'billing', 'type' => 'bool']
    );

    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(50.0, class: 2);
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $today, status: 'pending_renewal', autoRenew: 1);
    makeUnpaidRenewalInvoice($user, $sub, 30.0);

    ob_start();
    SubscriptionService::processAutoRenew();
    ob_get_clean();

    $row = (new EmailQueue())->where('template', 'subscription_renewed.tpl')->first();
    expect($row)->not->toBeNull();
    expect($row->to_email)->toBe($user->email);

    $payload = json_decode($row->array, true);
    expect($payload['payment_method_text'])->toBe('账户余额（自动续费）');
    expect($payload['end_date'])->toBe((new Subscription())->find($sub->id)->end_date);
});
