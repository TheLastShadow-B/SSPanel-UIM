<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Cron;
use App\Services\OrderActivation;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * OrderActivation::tryActivate — 支付即时激活的幂等入口(topup 部分)。
 *
 * 语义:订单行锁 + 状态复查;pending_payment 且账单已结清则原地推进到
 * pending_activation(镜像 Cron::processPendingOrder)再激活;重复调用只成功一次;
 * 不支持的遗留类型返回 false 留给 cron。
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

if (! function_exists('activationMakeTopupOrder')) {
    /**
     * @return array{0: Order, 1: Invoice}
     */
    function activationMakeTopupOrder(
        int $userId,
        float $amount,
        string $orderStatus,
        string $invoiceStatus
    ): array {
        $order = new Order();
        $order->user_id = $userId;
        $order->product_id = 0;
        $order->product_type = 'topup';
        $order->product_name = '余额充值';
        $order->product_content = json_encode(['amount' => $amount]);
        $order->subscription_id = null;
        $order->coupon = '';
        $order->price = $amount;
        $order->status = $orderStatus;
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'topup';
        $invoice->user_id = $userId;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => '余额充值', 'price' => $amount]]);
        $invoice->price = $amount;
        $invoice->status = $invoiceStatus;
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return [$order, $invoice];
    }
}

it('activates a paid pending_activation topup order and credits money once', function () {
    $user = makeUserWithMoney(5.0);
    [$order] = activationMakeTopupOrder($user->id, 30.0, 'pending_activation', 'paid_gateway');

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();

    expect((float) (new User())->find($user->id)->money)->toBe(35.0);
    expect((new Order())->find($order->id)->status)->toBe('activated');
});

it('flips a pending_payment order with a settled invoice, then activates (webhook beats cron)', function () {
    $user = makeUserWithMoney(0.0);
    [$order] = activationMakeTopupOrder($user->id, 10.0, 'pending_payment', 'paid_gateway');

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();

    expect((float) (new User())->find($user->id)->money)->toBe(10.0);
    expect((new Order())->find($order->id)->status)->toBe('activated');
});

it('refuses when the invoice is still unpaid and changes nothing', function () {
    $user = makeUserWithMoney(0.0);
    [$order] = activationMakeTopupOrder($user->id, 10.0, 'pending_payment', 'unpaid');

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();

    expect((float) (new User())->find($user->id)->money)->toBe(0.0);
    expect((new Order())->find($order->id)->status)->toBe('pending_payment');
});

it('is idempotent: the second call is a no-op', function () {
    $user = makeUserWithMoney(0.0);
    [$order] = activationMakeTopupOrder($user->id, 10.0, 'pending_activation', 'paid_balance');

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();
    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();

    expect((float) (new User())->find($user->id)->money)->toBe(10.0);
});

it('returns false for a missing order id', function () {
    expect(OrderActivation::tryActivate(424242))->toBeFalse();
});

it('leaves legacy product types to the cron loops', function () {
    $user = makeUserWithMoney(0.0);
    [$order] = activationMakeTopupOrder($user->id, 10.0, 'pending_activation', 'paid_gateway');
    $order->product_type = 'bandwidth';
    $order->save();

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new Order())->find($order->id)->status)->toBe('pending_activation');
});

it('keeps Cron::processTopupOrderActivation working through delegation', function () {
    $user = makeUserWithMoney(0.0);
    [$order] = activationMakeTopupOrder($user->id, 20.0, 'pending_activation', 'paid_gateway');

    ob_start();
    Cron::processTopupOrderActivation();
    ob_get_clean();

    expect((float) (new User())->find($user->id)->money)->toBe(20.0);
    expect((new Order())->find($order->id)->status)->toBe('activated');
});
