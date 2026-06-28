<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Exchange;
use App\Services\Gateway\Stripe;
use App\Services\Stripe\StripeService;
use Stripe\Checkout\Session;
use Stripe\StripeClient;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * Task B1 — Stripe::purchase() saves the card on a subscription's FIRST Stripe
 * payment (the balance-insufficient path).
 *
 * For a SUBSCRIPTION invoice the Checkout must carry `customer` (so the card
 * attaches to a customer the renewal engine reads) AND
 * payment_intent_data.setup_future_usage='off_session' (so Stripe saves the
 * card off-session). For a TOPUP / one-off invoice the call is EXACTLY as
 * before — neither field — which regression-guards non-subscription flows.
 *
 * Fully offline: Exchange and StripeService are both swapped for fakes via
 * their getInstance/setInstance singletons; nothing touches the network (this
 * environment has no redis extension, so the real Exchange path is unreachable
 * by design).
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();

    Config::query()->updateOrInsert(['item' => 'stripe_min_recharge'], ['value' => '1', 'class' => 'billing', 'type' => 'int']);
    Config::query()->updateOrInsert(['item' => 'stripe_max_recharge'], ['value' => '10000', 'class' => 'billing', 'type' => 'int']);
    Config::query()->updateOrInsert(['item' => 'stripe_currency'], ['value' => 'USD', 'class' => 'billing', 'type' => 'string']);
    Config::query()->updateOrInsert(['item' => 'stripe_api_key'], ['value' => 'sk_test_x', 'class' => 'billing', 'type' => 'string']);

    // Deterministic, offline FX — the real Exchange would need redis (absent here).
    Exchange::setInstance(new class extends Exchange {
        public function exchange(float $amount, string $from, string $to): float
        {
            return $amount; // 1:1; the converted value is irrelevant to these assertions
        }
    });
});

afterEach(function () {
    global $user;
    $user = null;

    TestDatabase::dropTables();

    // Hand later tests clean, offline singletons.
    Exchange::setInstance(new Exchange());
    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
});

/**
 * Fake StripeService whose fake client captures the Checkout params passed to
 * checkout->sessions->create, and whose ensureCustomer is deterministic +
 * offline. Read $svc->client()->captured after purchase().
 */
function purchaseSaveCardStripe(string $customerId): StripeService
{
    $client = new class extends StripeClient {
        public ?array $captured = null;

        public function __construct()
        {
            parent::__construct(['api_key' => 'sk_test_savecard']);
        }

        public function __get($name)
        {
            if ($name === 'checkout') {
                return new class ($this) {
                    public object $sessions;

                    public function __construct(object $owner)
                    {
                        $this->sessions = new class ($owner) {
                            public function __construct(private object $owner) {}

                            public function create($params, $opts = null)
                            {
                                $this->owner->captured = ['params' => $params, 'opts' => $opts];

                                return Session::constructFrom([
                                    'id' => 'cs_test_savecard',
                                    'url' => 'https://checkout.stripe.test/cs_test_savecard',
                                ]);
                            }
                        };
                    }
                };
            }

            return parent::__get($name);
        }
    };

    return new class ($client, $customerId) extends StripeService {
        public function __construct(private StripeClient $c, private string $cid)
        {
            parent::__construct($c);
        }

        public function client(): StripeClient
        {
            return $this->c;
        }

        public function ensureCustomer(User $user): string
        {
            return $this->cid;
        }
    };
}

function purchaseSaveCardUser(): User
{
    $user = new User();
    $user->email = 'savecard_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'savecard';
    $user->passwd = bin2hex(random_bytes(8));
    $user->transfer_enable = 1099511627776;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

function purchaseSaveCardOrder(int $userId, string $productType): Order
{
    $order = new Order();
    $order->user_id = $userId;
    $order->product_id = 1;
    $order->product_type = $productType;
    $order->product_name = 'Pro';
    $order->product_content = json_encode(['class' => 1, 'bandwidth' => 100]);
    $order->coupon = '';
    $order->price = 50.0;
    $order->status = 'pending_payment';
    $order->create_time = time();
    $order->update_time = time();
    $order->billing_provider = 'manual';
    $order->save();

    return $order;
}

function purchaseSaveCardInvoice(int $userId, int $orderId, float $price = 50.0): Invoice
{
    $inv = new Invoice();
    $inv->type = 'product';
    $inv->user_id = $userId;
    $inv->order_id = $orderId;
    $inv->content = '[]';
    $inv->price = $price;
    $inv->status = 'unpaid';
    $inv->create_time = time();
    $inv->update_time = time();
    $inv->billing_provider = 'manual';
    $inv->save();

    return $inv;
}

function purchaseSaveCardRequest(int $invoiceId): \Slim\Http\ServerRequest
{
    return (new \Slim\Http\Factory\DecoratedServerRequestFactory(new \GuzzleHttp\Psr7\HttpFactory()))
        ->createServerRequest('POST', '/user/payment/purchase/stripe')
        ->withParsedBody(['invoice_id' => (string) $invoiceId]);
}

function purchaseSaveCardResponse(): \Slim\Http\Response
{
    return new \Slim\Http\Response(new \GuzzleHttp\Psr7\Response(), new \GuzzleHttp\Psr7\HttpFactory());
}

it('sets customer + setup_future_usage=off_session on the Checkout for a subscription invoice', function () {
    $user = purchaseSaveCardUser();
    $order = purchaseSaveCardOrder($user->id, 'subscription');
    $invoice = purchaseSaveCardInvoice($user->id, $order->id);

    $GLOBALS['user'] = $user;

    $svc = purchaseSaveCardStripe('cus_savecard_sub');
    StripeService::setInstance($svc);

    $result = (new Stripe())->purchase(
        purchaseSaveCardRequest($invoice->id),
        purchaseSaveCardResponse(),
        []
    );

    // Redirected to the Checkout URL (HX-Redirect), proving create() ran.
    expect($result->getHeaderLine('HX-Redirect'))->toBe('https://checkout.stripe.test/cs_test_savecard');

    $params = $svc->client()->captured['params'];

    expect($params['customer'])->toBe('cus_savecard_sub');
    expect($params['payment_intent_data']['setup_future_usage'])->toBe('off_session');
    // The trade_no settle key is preserved + the bind ids are added.
    expect($params['payment_intent_data']['metadata'])->toHaveKey('trade_no');
    expect($params['payment_intent_data']['metadata']['invoice_id'])->toBe((string) $invoice->id);
    expect($params['payment_intent_data']['metadata']['order_id'])->toBe((string) $order->id);
});

it('leaves the Checkout unchanged (no customer, no setup_future_usage) for a topup/non-subscription invoice', function () {
    $user = purchaseSaveCardUser();
    $order = purchaseSaveCardOrder($user->id, 'topup');
    $invoice = purchaseSaveCardInvoice($user->id, $order->id);

    $GLOBALS['user'] = $user;

    $svc = purchaseSaveCardStripe('cus_should_not_be_used');
    StripeService::setInstance($svc);

    (new Stripe())->purchase(
        purchaseSaveCardRequest($invoice->id),
        purchaseSaveCardResponse(),
        []
    );

    $params = $svc->client()->captured['params'];

    expect($params)->not->toHaveKey('customer');
    expect($params['payment_intent_data'])->not->toHaveKey('setup_future_usage');
    // The classic one-off shape is intact: email prefilled, trade_no carried.
    expect($params['customer_email'])->toBe($user->email);
    expect($params['payment_intent_data']['metadata'])->toHaveKey('trade_no');
});
