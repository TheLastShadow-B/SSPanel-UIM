<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Paylist;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Gateway\Base;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * Gateway/Base::postPayment 现在应在标记账单已付后同步激活订单:
 * 网关回调一到,订阅/充值立即生效,不再等 5 分钟 cron。
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

if (! function_exists('postPaymentTestGateway')) {
    function postPaymentTestGateway(): Base
    {
        return new class extends Base {
            public function purchase(ServerRequest $request, Response $response, array $args): ResponseInterface
            {
                throw new RuntimeException('unused in test');
            }

            public function notify(ServerRequest $request, Response $response, array $args): ResponseInterface
            {
                throw new RuntimeException('unused in test');
            }

            public static function _name(): string
            {
                return 'testgw';
            }

            public static function _enable(): bool
            {
                return false;
            }

            public static function _readableName(): string
            {
                return 'Test Gateway';
            }

            public static function getPurchaseHTML(): string
            {
                return '';
            }
        };
    }
}

if (! function_exists('postPaymentMakePending')) {
    /**
     * 一套「网关待支付」组合:pending_payment 订单 + unpaid 账单 + status=0 paylist。
     *
     * @return array{0: Order, 1: Invoice, 2: string} 订单、账单、tradeno
     */
    function postPaymentMakePending(User $user, string $productType, array $content, float $price): array
    {
        $order = new Order();
        $order->user_id = $user->id;
        $order->product_id = 1;
        $order->product_type = $productType;
        $order->product_name = 'Pro';
        $order->product_content = json_encode($content);
        $order->subscription_id = null;
        $order->coupon = '';
        $order->price = $price;
        $order->status = 'pending_payment';
        $order->billing_provider = 'manual';
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = $productType === 'topup' ? 'topup' : 'product';
        $invoice->user_id = $user->id;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => 'Pro', 'price' => $price]]);
        $invoice->price = $price;
        $invoice->status = 'unpaid';
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        $tradeno = 'testgw_' . bin2hex(random_bytes(6));
        $paylist = new Paylist();
        $paylist->userid = $user->id;
        $paylist->total = $price;
        $paylist->invoice_id = $invoice->id;
        $paylist->tradeno = $tradeno;
        $paylist->status = 0;
        $paylist->gateway = 'testgw';
        $paylist->save();

        return [$order, $invoice, $tradeno];
    }
}

it('activates a subscription order the moment the gateway callback lands', function () {
    $user = makeUserWithMoney(0.0, class: 0, classExpire: date('Y-m-d H:i:s', strtotime('-1 day')));
    [$order, $invoice, $tradeno] = postPaymentMakePending($user, 'subscription', [
        'class' => 1,
        'bandwidth' => 100,
        'node_group' => 0,
        'speed_limit' => 0,
        'ip_limit' => 0,
        'billing_cycle_selected' => 'month',
        'name' => 'Pro',
    ], 10.0);

    postPaymentTestGateway()->postPayment($tradeno);

    expect((new Invoice())->find($invoice->id)->status)->toBe('paid_gateway');
    expect((new Order())->find($order->id)->status)->toBe('activated');
    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(1);
    expect((int) (new User())->find($user->id)->class)->toBe(1);
});

it('credits a topup order the moment the gateway callback lands', function () {
    $user = makeUserWithMoney(5.0);
    [$order, , $tradeno] = postPaymentMakePending($user, 'topup', ['amount' => 30.0], 30.0);

    postPaymentTestGateway()->postPayment($tradeno);

    expect((float) (new User())->find($user->id)->money)->toBe(35.0);
    expect((new Order())->find($order->id)->status)->toBe('activated');
});

it('stays safe on duplicate webhook delivery', function () {
    $user = makeUserWithMoney(0.0);
    [$order, , $tradeno] = postPaymentMakePending($user, 'topup', ['amount' => 10.0], 10.0);

    $gateway = postPaymentTestGateway();
    $gateway->postPayment($tradeno);
    $gateway->postPayment($tradeno);

    expect((float) (new User())->find($user->id)->money)->toBe(10.0);
    expect((new Order())->find($order->id)->status)->toBe('activated');
});
