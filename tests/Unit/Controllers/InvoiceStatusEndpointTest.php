<?php

declare(strict_types=1);

use App\Controllers\User\InvoiceController;
use App\Models\Invoice;
use App\Models\Order;
use Tests\TestDatabase;

require_once __DIR__ . '/../Services/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * GET /user/invoice/{id}/status — 账单页轮询端点。
 * 只暴露本人账单的 invoice/order 状态;他人或不存在 → ret 0(镜像 detail 的
 * user_id 过滤,防 IDOR)。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    $GLOBALS['user'] = null;
    TestDatabase::dropTables();
});

if (! function_exists('statusEndpointRequest')) {
    function statusEndpointRequest(): \Slim\Http\ServerRequest
    {
        return (new \Slim\Http\Factory\DecoratedServerRequestFactory(new \GuzzleHttp\Psr7\HttpFactory()))
            ->createServerRequest('GET', '/user/invoice/1/status');
    }

    function statusEndpointResponse(): \Slim\Http\Response
    {
        return new \Slim\Http\Response(new \GuzzleHttp\Psr7\Response(), new \GuzzleHttp\Psr7\HttpFactory());
    }

    /**
     * @return array{0: Order, 1: Invoice}
     */
    function statusEndpointMakePair(int $userId, string $orderStatus, string $invoiceStatus): array
    {
        $order = new Order();
        $order->user_id = $userId;
        $order->product_id = 1;
        $order->product_type = 'subscription';
        $order->product_name = 'Pro';
        $order->product_content = json_encode(['name' => 'Pro']);
        $order->subscription_id = null;
        $order->coupon = '';
        $order->price = 10.0;
        $order->status = $orderStatus;
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'product';
        $invoice->user_id = $userId;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => 'Pro', 'price' => 10.0]]);
        $invoice->price = 10.0;
        $invoice->status = $invoiceStatus;
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return [$order, $invoice];
    }
}

it('returns invoice and order status for the owner', function () {
    $user = makeUserWithMoney(0.0);
    $GLOBALS['user'] = $user;
    [, $invoice] = statusEndpointMakePair($user->id, 'activated', 'paid_balance');

    $response = (new InvoiceController())->status(
        statusEndpointRequest(),
        statusEndpointResponse(),
        ['id' => (string) $invoice->id]
    );

    $json = json_decode((string) $response->getBody());
    expect($json->ret)->toBe(1);
    expect($json->invoice_status)->toBe('paid_balance');
    expect($json->order_status)->toBe('activated');
});

it('rejects another user\'s invoice (IDOR guard)', function () {
    $owner = makeUserWithMoney(0.0);
    [, $invoice] = statusEndpointMakePair($owner->id, 'pending_payment', 'unpaid');

    $intruder = makeUserWithMoney(0.0);
    $GLOBALS['user'] = $intruder;

    $response = (new InvoiceController())->status(
        statusEndpointRequest(),
        statusEndpointResponse(),
        ['id' => (string) $invoice->id]
    );

    expect(json_decode((string) $response->getBody())->ret)->toBe(0);
});

it('returns ret 0 for a missing invoice', function () {
    $user = makeUserWithMoney(0.0);
    $GLOBALS['user'] = $user;

    $response = (new InvoiceController())->status(
        statusEndpointRequest(),
        statusEndpointResponse(),
        ['id' => '999999']
    );

    expect(json_decode((string) $response->getBody())->ret)->toBe(0);
});
