<?php

declare(strict_types=1);

use App\Controllers\User\OrderController;
use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cache;
use App\Services\Stripe\StripeService;
use Stripe\Checkout\Session;
use Stripe\StripeClient;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * P1.3 — subscription() must branch into Stripe subscription-mode checkout.
 *
 * These DB-backed tests run against the real MariaDB `sspanel_test` (same as
 * the merged P0 / IDOR feature tests). We drive the real OrderController method
 * with a fake authenticated user (Auth::getUser() reads the global $user) and a
 * stubbed StripeService so we never touch live Stripe.
 *
 * The manual/balance path needs no network and always runs. The full Stripe
 * branch goes through PriceResolver::resolve() -> Exchange (Redis-backed FX), so
 * — exactly like the merged PriceResolverTest — it skips where ext-redis is
 * unavailable, after pre-seeding a deterministic offline FX rate.
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    // SlimTestCase forces sqlite :memory: for the API feature suite; restore the
    // real MariaDB test config before TestDatabase::init() so DB::init() targets
    // `sspanel_test` instead of die()ing on a missing ":memory:" MariaDB db.
    require BASE_PATH . '/config/.config.test.php';

    TestDatabase::init();
});

afterEach(function () {
    global $user;
    $user = null;

    if (extension_loaded('redis')) {
        $redis = (new Cache())->initRedis();
        $redis->del('exchange_rate:CNY_USD');
    }

    TestDatabase::dropTables();

    // Restore a real (offline) singleton so later tests build a fresh instance.
    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
});

/**
 * Authenticated user with an EXPIRED self-managed plan, so subscription() is
 * allowed to proceed (no active sub, no unexpired class).
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

/**
 * Fake price client used by PriceResolver::resolve(): pretends a recurring price
 * already exists so resolve() never calls prices->create() against live Stripe.
 */
function subFakeStripeService(): StripeService
{
    $client = new class extends StripeClient {
        public function __construct()
        {
            parent::__construct(['api_key' => 'sk_test_sub']);
        }

        public function __get($name)
        {
            if ($name === 'prices') {
                return new class {
                    public function all($params = null, $opts = null)
                    {
                        return (object) ['data' => [(object) ['id' => 'price_sub_reuse_1']]];
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

        public function createSubscriptionCheckout(
            $user,
            $priceId,
            $metadata,
            $successUrl,
            $cancelUrl
        ): Session {
            $this->checkoutCalls[] = compact('priceId', 'metadata', 'successUrl', 'cancelUrl');

            return Session::constructFrom([
                'id' => 'cs_test_sub_1',
                'url' => 'https://checkout.stripe.test/cs_test_sub_1',
            ]);
        }
    };
}

it('keeps the manual path unchanged: stamps billing_provider=manual on order and invoice', function () {
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
        // no auto_renew_provider -> manual
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

it('falls back to manual when auto_renew_provider=stripe but the master switch is OFF', function () {
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

    // Switch OFF -> behaves exactly like the manual path.
    expect($response->getHeaderLine('HX-Redirect'))->toContain('/user/invoice/');

    $order = (new Order())->where('user_id', $user->id)->first();
    $invoice = (new Invoice())->where('user_id', $user->id)->first();

    expect($order->billing_provider)->toBe('manual');
    expect($invoice->billing_provider)->toBe('manual');
});

/**
 * Redis-free deterministic check of the new branch's SOURCE-OF-TRUTH side effect:
 * with the master switch ON and auto_renew_provider=stripe, the order + invoice
 * MUST be persisted with billing_provider='stripe'. This persistence happens
 * BEFORE PriceResolver::resolve() (which needs Redis/Exchange). Where ext-redis
 * is missing, resolve() throws — we catch it and still assert the stamped rows,
 * proving the branch fired regardless of the downstream FX/Stripe call.
 */
it('stamps billing_provider=stripe on order+invoice when the master switch is ON', function () {
    Config::query()->updateOrInsert(
        ['item' => 'stripe_auto_billing_enabled'],
        ['value' => '1', 'class' => 'billing', 'type' => 'bool']
    );
    Config::query()->updateOrInsert(
        ['item' => 'stripe_currency'],
        ['value' => 'USD', 'class' => 'billing', 'type' => 'string']
    );

    if (extension_loaded('redis')) {
        // Pre-seed FX so Exchange is offline + deterministic when available.
        (new Cache())->initRedis()->setex('exchange_rate:CNY_USD', 3600, 0.10);
    }

    $fake = subFakeStripeService();
    StripeService::setInstance($fake);

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

    $completed = false;
    $response = null;

    try {
        $response = $controller->subscription($request, subResponse(), []);
        $completed = true;
    } catch (\Throwable $e) {
        // Without ext-redis, resolve()->Exchange throws AFTER persistence. That is
        // fine — the source-of-truth assertion below is what this test guards.
        if (extension_loaded('redis')) {
            throw $e;
        }
    }

    // Source-of-truth flag stamped on BOTH order and invoice (Redis or not).
    $order = (new Order())->where('user_id', $user->id)->first();
    $invoice = (new Invoice())->where('user_id', $user->id)->first();

    expect($order)->not->toBeNull();
    expect($order->billing_provider)->toBe('stripe');
    expect($invoice)->not->toBeNull();
    expect($invoice->billing_provider)->toBe('stripe');

    if ($completed) {
        // With Redis available the full branch completes: redirect to Checkout.
        expect($response->getHeaderLine('HX-Redirect'))->toBe('https://checkout.stripe.test/cs_test_sub_1');
        expect($fake->checkoutCalls)->toHaveCount(1);
        $call = $fake->checkoutCalls[0];
        expect($call['priceId'])->toBe('price_sub_reuse_1');
        expect($call['metadata']['sspanel_user_id'])->toBe((string) $user->id);
        expect($call['metadata']['product_id'])->toBe((string) $product->id);
        expect($call['metadata']['billing_cycle'])->toBe('month');
        expect($call['metadata']['order_id'])->toBe((string) $order->id);
    }
});
