<?php

declare(strict_types=1);

use App\Controllers\User\OrderController;
use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * subscription() has NO Stripe subscription-mode checkout branch any more.
 *
 * Task B2 removed the auto_renew_provider / billing_provider='stripe' branch:
 * every self-managed subscription is billing_provider='manual' and the response
 * is always an HX-Redirect to the invoice view (never a Stripe Checkout URL).
 * These DB-backed tests (real MariaDB sspanel_test) are the regression guard for
 * "the legacy checkout-mode branch stays gone". The balance-first settle + the
 * switch-ON removal proof live in tests/Unit/Controllers/SubscriptionPurchaseTest.
 *
 * Both buyers here hold zero balance, so subscription() takes the unchanged
 * "insufficient balance -> unpaid invoice -> redirect to pay" path: no money
 * moves, no UserMoneyLog, no network.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    // SlimTestCase forces sqlite :memory: for the Feature suite; restore the real
    // MariaDB test config before TestDatabase::init() so DB::init() targets
    // sspanel_test instead of die()ing on a missing ":memory:" MariaDB db.
    require BASE_PATH . '/config/.config.test.php';

    TestDatabase::init();
});

afterEach(function () {
    global $user;
    $user = null;

    TestDatabase::dropTables();
});

/**
 * Authenticated user with an EXPIRED self-managed plan, so subscription() is
 * allowed to proceed (no active sub, no unexpired class). Zero balance keeps the
 * purchase on the insufficient-balance (unpaid) path.
 */
function makeSubBuyer(): User
{
    $user = new User();
    $user->email = 'subbuyer_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'sub_buyer';
    $user->passwd = bin2hex(random_bytes(8));
    $user->transfer_enable = 1099511627776;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->class = 0;
    $user->class_expire = date('Y-m-d H:i:s', strtotime('-1 day'));
    $user->save();

    return $user;
}

function makeSubProduct(): Product
{
    $product = new Product();
    $product->type = 'subscription';
    $product->name = 'Pro';
    $product->price = 10.0;
    $product->stock = 100;
    $product->sale_count = 0;
    $product->status = 1;
    $product->content = json_encode([
        'class' => 1,
        'bandwidth' => 100,
        'node_group' => 0,
        'speed_limit' => 0,
        'ip_limit' => 0,
        'billing_cycle' => ['month' => true],
    ]);
    $product->limit = json_encode([
        'class_required' => '',
        'node_group_required' => '',
        'new_user_required' => 0,
    ]);
    $product->create_time = time();
    $product->update_time = time();
    $product->save();

    return $product;
}

/**
 * Build a request carrying the parsed body subscription() reads via getParam().
 */
function subRequest(array $params): \Slim\Http\ServerRequest
{
    $decorated = (new \Slim\Http\Factory\DecoratedServerRequestFactory(new \GuzzleHttp\Psr7\HttpFactory()))
        ->createServerRequest('POST', '/user/order');

    return $decorated->withParsedBody($params);
}

function subResponse(): \Slim\Http\Response
{
    return new \Slim\Http\Response(new \GuzzleHttp\Psr7\Response(), new \GuzzleHttp\Psr7\HttpFactory());
}

it('stamps billing_provider=manual on order and invoice and redirects to the invoice view', function () {
    $user = makeSubBuyer();
    $product = makeSubProduct();

    // Auth::getUser() (called by BaseController::__construct) reads the global $user.
    $GLOBALS['user'] = $user;

    $controller = new OrderController();

    $request = subRequest([
        'type' => 'subscription',
        'product_id' => (string) $product->id,
        'billing_cycle' => 'month',
        'coupon' => '',
    ]);

    $response = $controller->subscription($request, subResponse(), []);

    expect($response->getHeaderLine('HX-Redirect'))->toContain('/user/invoice/');

    $order = (new Order())->where('user_id', $user->id)->first();
    $invoice = (new Invoice())->where('user_id', $user->id)->first();

    expect($order)->not->toBeNull();
    expect($order->billing_provider)->toBe('manual');
    expect($order->product_type)->toBe('subscription');
    expect($invoice)->not->toBeNull();
    expect($invoice->billing_provider)->toBe('manual');
});

it('ignores auto_renew_provider=stripe and never starts a Stripe checkout: stays manual', function () {
    // The legacy branch only fired when this switch was ON; even seeding it OFF here,
    // the param is dead — the redirect is the invoice view and the rows are manual.
    Config::query()->updateOrInsert(
        ['item' => 'stripe_auto_billing_enabled'],
        ['value' => '0', 'class' => 'billing', 'type' => 'bool']
    );

    $user = makeSubBuyer();
    $product = makeSubProduct();

    $GLOBALS['user'] = $user;

    $controller = new OrderController();

    $request = subRequest([
        'type' => 'subscription',
        'product_id' => (string) $product->id,
        'billing_cycle' => 'month',
        'coupon' => '',
        'auto_renew_provider' => 'stripe',
    ]);

    $response = $controller->subscription($request, subResponse(), []);

    expect($response->getHeaderLine('HX-Redirect'))->toContain('/user/invoice/');

    $order = (new Order())->where('user_id', $user->id)->first();
    $invoice = (new Invoice())->where('user_id', $user->id)->first();

    expect($order->billing_provider)->toBe('manual');
    expect($invoice->billing_provider)->toBe('manual');
});
