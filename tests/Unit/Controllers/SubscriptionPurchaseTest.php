<?php

declare(strict_types=1);

use App\Controllers\User\OrderController;
use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Services\Cache;
use App\Services\DB;
use App\Services\Stripe\StripeService;
use App\Services\SubscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Stripe\Checkout\Session;
use Stripe\StripeClient;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * Task B2 — first-purchase balance-first + auto_renew default.
 *
 * subscription() now settles a paid subscription order from the user's站内余额
 * the moment it is created (when the balance covers the price), flips the order
 * to pending_activation, and lets the existing 5-min activation chain
 * (SubscriptionService::processNewSubscriptionActivation) create the Subscription
 * — now with auto_renew=1 (opt-out). The legacy auto_renew_provider /
 * billing_provider='stripe' checkout branch is gone: every self-managed
 * subscription is billing_provider='manual'.
 *
 * DB-backed against the real MariaDB sspanel_test (mirrors
 * SubscriptionCheckoutModeTest / InvoicePayBalanceGuardTest). No network: the
 * balance path needs none, and the legacy stripe branch (only reachable by the
 * OLD code during the red step) is stubbed AND would throw offline in Exchange
 * before any Stripe call — so the suite never touches live Stripe.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    // Restore the real MariaDB test config (a prior SlimTestCase Feature test may
    // have forced sqlite :memory:) before DB::init() so locks/transactions run on
    // MariaDB exactly like production.
    require BASE_PATH . '/config/.config.test.php';

    TestDatabase::init();
    purchaseEnsureMoneyLogTable();
});

afterEach(function () {
    global $user;
    $user = null;

    if (extension_loaded('redis')) {
        (new Cache())->initRedis()->del('exchange_rate:CNY_USD');
    }

    purchaseDropMoneyLogTable();
    TestDatabase::dropTables();

    // Hand later tests a clean offline singleton.
    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
});

/**
 * subscription()'s balance settle writes UserMoneyLog; Tests\TestDatabase ships
 * no such table, so create it on demand (mirrors the production migration).
 */
function purchaseEnsureMoneyLogTable(): void
{
    $schema = DB::getCapsule()->schema();

    if (! $schema->hasTable('user_money_log')) {
        $schema->create('user_money_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->default(0);
            $table->decimal('before', 12, 2)->default(0);
            $table->decimal('after', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('remark');
            $table->integer('create_time')->default(0);
        });
    }
}

function purchaseDropMoneyLogTable(): void
{
    DB::getCapsule()->schema()->dropIfExists('user_money_log');
}

/**
 * A subscriber eligible to buy: class 0, membership already expired (so the
 * "unexpired plan" guard in subscription() does not block), carrying $money.
 */
function purchaseMakeBuyer(float $money = 0.0): User
{
    $user = new User();
    $user->email = 'subpurchase_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'sub_purchase';
    $user->passwd = bin2hex(random_bytes(8));
    $user->money = $money;
    $user->transfer_enable = 0;
    $user->class = 0;
    $user->class_expire = date('Y-m-d H:i:s', strtotime('-1 day'));
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

function purchaseMakeProduct(float $price = 10.0): Product
{
    $product = new Product();
    $product->type = 'subscription';
    $product->name = 'Pro';
    $product->price = $price;
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

function purchaseRequest(array $params): \Slim\Http\ServerRequest
{
    $decorated = (new \Slim\Http\Factory\DecoratedServerRequestFactory(new \GuzzleHttp\Psr7\HttpFactory()))
        ->createServerRequest('POST', '/user/order');

    return $decorated->withParsedBody($params);
}

function purchaseResponse(): \Slim\Http\Response
{
    return new \Slim\Http\Response(new \GuzzleHttp\Psr7\Response(), new \GuzzleHttp\Psr7\HttpFactory());
}

/**
 * Stub StripeService that records any checkout attempt. With the legacy branch
 * removed this MUST never be called (checkoutCalls stays empty); it exists so
 * that, if the dead branch ever fired, the red step is caught offline.
 */
function purchaseFakeStripe(): StripeService
{
    $client = new class extends StripeClient {
        public function __construct()
        {
            parent::__construct(['api_key' => 'sk_test_purchase']);
        }

        public function __get($name)
        {
            if ($name === 'prices') {
                return new class {
                    public function all($params = null, $opts = null)
                    {
                        return (object) ['data' => [(object) ['id' => 'price_purchase_reuse']]];
                    }
                };
            }

            return parent::__get($name);
        }
    };

    return new class ($client) extends StripeService {
        public array $checkoutCalls = [];

        public function __construct(private StripeClient $fakeClient)
        {
            parent::__construct($fakeClient);
        }

        public function client(): StripeClient
        {
            return $this->fakeClient;
        }

        public function createSubscriptionCheckout($user, $priceId, $metadata, $successUrl, $cancelUrl): Session
        {
            $this->checkoutCalls[] = compact('priceId', 'metadata', 'successUrl', 'cancelUrl');

            return Session::constructFrom([
                'id' => 'cs_test_purchase',
                'url' => 'https://checkout.stripe.test/cs_test_purchase',
            ]);
        }
    };
}

it('settles a subscription purchase from balance, then activation creates a manual auto_renew=1 sub and grants membership', function () {
    $user = purchaseMakeBuyer(50.0);
    $product = purchaseMakeProduct(10.0);

    $GLOBALS['user'] = $user;

    $response = (new OrderController())->subscription(purchaseRequest([
        'type' => 'subscription',
        'product_id' => (string) $product->id,
        'billing_cycle' => 'month',
        'coupon' => '',
    ]), purchaseResponse(), []);

    // Redirect to the invoice view (now showing paid), exactly as today.
    expect($response->getHeaderLine('HX-Redirect'))->toContain('/user/invoice/');

    // Balance deducted by the cycle price.
    expect((float) (new User())->find($user->id)->money)->toBe(40.0);

    $order = (new Order())->where('user_id', $user->id)->first();
    $invoice = (new Invoice())->where('user_id', $user->id)->first();

    // Invoice settled from balance, order ready for the activation chain.
    expect($invoice->status)->toBe('paid_balance');
    expect((int) $invoice->pay_time)->toBeGreaterThan(0);
    expect($invoice->billing_provider)->toBe('manual');
    expect($order->status)->toBe('pending_activation');
    expect($order->billing_provider)->toBe('manual');

    // UserMoneyLog records the -10 deduction.
    $log = (new UserMoneyLog())->where('user_id', $user->id)->first();
    expect($log)->not->toBeNull();
    expect((float) $log->before)->toBe(50.0);
    expect((float) $log->after)->toBe(40.0);
    expect((float) $log->amount)->toBe(-10.0);

    // The existing 5-min activation chain creates the Subscription.
    // (Buffer the cron's progress echoes: the suite is strict about test output.)
    ob_start();
    SubscriptionService::processNewSubscriptionActivation();
    ob_get_clean();

    $sub = (new Subscription())->where('user_id', $user->id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->billing_provider)->toBe('manual');
    expect((int) $sub->auto_renew)->toBe(1);
    expect($sub->status)->toBe('active');

    // Membership granted from product_content (class 1, 100 GB).
    $fresh = (new User())->find($user->id);
    expect((int) $fresh->class)->toBe(1);
    expect((int) $fresh->transfer_enable)->toBe(100 * 1024 ** 3);

    expect((new Order())->find($order->id)->status)->toBe('activated');
});

it('leaves the invoice unpaid and the balance untouched when money is insufficient', function () {
    $user = purchaseMakeBuyer(5.0);
    $product = purchaseMakeProduct(10.0);

    $GLOBALS['user'] = $user;

    $response = (new OrderController())->subscription(purchaseRequest([
        'type' => 'subscription',
        'product_id' => (string) $product->id,
        'billing_cycle' => 'month',
        'coupon' => '',
    ]), purchaseResponse(), []);

    expect($response->getHeaderLine('HX-Redirect'))->toContain('/user/invoice/');

    // Nothing deducted; today's manual invoice flow is untouched.
    expect((float) (new User())->find($user->id)->money)->toBe(5.0);

    $order = (new Order())->where('user_id', $user->id)->first();
    $invoice = (new Invoice())->where('user_id', $user->id)->first();

    expect($invoice->status)->toBe('unpaid');
    expect((int) $invoice->pay_time)->toBe(0);
    expect($invoice->billing_provider)->toBe('manual');
    expect($order->status)->toBe('pending_payment');
    expect($order->billing_provider)->toBe('manual');

    expect((new UserMoneyLog())->where('user_id', $user->id)->first())->toBeNull();
});

it('settles when the balance exactly equals the price', function () {
    $user = purchaseMakeBuyer(10.0);
    $product = purchaseMakeProduct(10.0);

    $GLOBALS['user'] = $user;

    (new OrderController())->subscription(purchaseRequest([
        'type' => 'subscription',
        'product_id' => (string) $product->id,
        'billing_cycle' => 'month',
        'coupon' => '',
    ]), purchaseResponse(), []);

    expect((float) (new User())->find($user->id)->money)->toBe(0.0);
    expect((new Invoice())->where('user_id', $user->id)->first()->status)->toBe('paid_balance');
    expect((new Order())->where('user_id', $user->id)->first()->status)->toBe('pending_activation');
});

it('keeps the zero-price activation path: a free subscription needs no balance and is pending_activation', function () {
    $user = purchaseMakeBuyer(0.0);
    $product = purchaseMakeProduct(0.0);

    $GLOBALS['user'] = $user;

    (new OrderController())->subscription(purchaseRequest([
        'type' => 'subscription',
        'product_id' => (string) $product->id,
        'billing_cycle' => 'month',
        'coupon' => '',
    ]), purchaseResponse(), []);

    $order = (new Order())->where('user_id', $user->id)->first();
    $invoice = (new Invoice())->where('user_id', $user->id)->first();

    expect($order->status)->toBe('pending_activation');
    expect($invoice->status)->toBe('paid_gateway');
    expect((float) (new User())->find($user->id)->money)->toBe(0.0);
    expect((new UserMoneyLog())->where('user_id', $user->id)->first())->toBeNull();
});

it('ignores auto_renew_provider=stripe even with the master switch ON: stays manual, settles from balance, never starts a checkout', function () {
    // Master switch ON is the ONLY condition under which the OLD code acted on
    // auto_renew_provider. Proving it is inert here proves the param is no longer read.
    Config::query()->updateOrInsert(
        ['item' => 'stripe_auto_billing_enabled'],
        ['value' => '1', 'class' => 'billing', 'type' => 'bool']
    );
    Config::query()->updateOrInsert(
        ['item' => 'stripe_currency'],
        ['value' => 'USD', 'class' => 'billing', 'type' => 'string']
    );

    if (extension_loaded('redis')) {
        // Offline, deterministic FX so the legacy branch (old code, red step) cannot
        // reach the network even where ext-redis is present.
        (new Cache())->initRedis()->setex('exchange_rate:CNY_USD', 3600, 0.10);
    }

    $fake = purchaseFakeStripe();
    StripeService::setInstance($fake);

    $user = purchaseMakeBuyer(50.0);
    $product = purchaseMakeProduct(10.0);

    $GLOBALS['user'] = $user;

    $response = (new OrderController())->subscription(purchaseRequest([
        'type' => 'subscription',
        'product_id' => (string) $product->id,
        'billing_cycle' => 'month',
        'coupon' => '',
        'auto_renew_provider' => 'stripe',
    ]), purchaseResponse(), []);

    // No Stripe checkout: redirect to the invoice view, and the stub was never called.
    expect($response->getHeaderLine('HX-Redirect'))->toContain('/user/invoice/');
    expect($fake->checkoutCalls)->toHaveCount(0);

    // Identical to the no-provider sufficient-balance path: manual + settled from balance.
    $order = (new Order())->where('user_id', $user->id)->first();
    $invoice = (new Invoice())->where('user_id', $user->id)->first();

    expect($order->billing_provider)->toBe('manual');
    expect($invoice->billing_provider)->toBe('manual');
    expect($invoice->status)->toBe('paid_balance');
    expect($order->status)->toBe('pending_activation');
    expect((float) (new User())->find($user->id)->money)->toBe(40.0);
});
