<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Stripe\StripeService;
use App\Services\Stripe\WebhookHandler;
use App\Utils\Tools;
use Stripe\StripeClient;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * P1.5 — handleCheckoutCompleted: create the local Subscription + run the
 * FIRST-PERIOD membership grant, idempotent on stripe_subscription_id.
 *
 * DB-backed against the real MariaDB `sspanel_test` (same as the merged P0 /
 * webhook-dedup tests). The checkout metadata lives on the STRIPE SUBSCRIPTION
 * object (set as subscription_data.metadata in P1.3), NOT on the session, so
 * the handler retrieves it via StripeService::getInstance()->client()
 * ->subscriptions->retrieve(...). We stub StripeService with a fake whose
 * client()->subscriptions->retrieve returns a fake subscription carrying the
 * metadata + the locked price — we never touch live Stripe.
 *
 * Test-infra precedent (matches merged P0.8 SubscriptionServiceGrantTest):
 * build rows with `new User()` directly, NOT Tests\Factories\UserFactory
 * (that factory needs fakerphp/faker + writes columns absent from the test
 * schema, so it cannot run here).
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
    // Restore a real (offline) singleton so later tests build a fresh instance.
    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
});

function makeCheckoutEvent(
    string $evtId,
    string $customer,
    string $sub,
    ?string $paymentStatus = 'paid',
    string $type = 'checkout.session.completed'
): \Stripe\Event {
    return \Stripe\Event::constructFrom([
        'id' => $evtId,
        'type' => $type,
        'data' => ['object' => [
            'id' => 'cs_1',
            'mode' => 'subscription',
            'customer' => $customer,
            'subscription' => $sub,
            'payment_status' => $paymentStatus,
        ]],
    ]);
}

/**
 * Fake StripeService whose client()->subscriptions->retrieve($id) returns a
 * \Stripe\Subscription carrying the P1.3 metadata + a locked recurring price.
 * Records each retrieve() id so tests can assert it was (or was not) called.
 */
function fakeCheckoutStripeService(array $metadata, int $unitAmount = 1000, string $currency = 'usd'): StripeService
{
    $subClient = new class ($metadata, $unitAmount, $currency) {
        public array $retrieveCalls = [];

        public function __construct(
            private array $metadata,
            private int $unitAmount,
            private string $currency
        ) {}

        public function retrieve($id, $params = null, $opts = null): \Stripe\Subscription
        {
            $this->retrieveCalls[] = $id;

            return \Stripe\Subscription::constructFrom([
                'id' => $id,
                'status' => 'active',
                'metadata' => $this->metadata,
                'items' => ['data' => [[
                    'price' => ['unit_amount' => $this->unitAmount, 'currency' => $this->currency],
                ]]],
            ]);
        }
    };

    $client = new class ($subClient) extends StripeClient {
        public function __construct(public $subStub)
        {
            parent::__construct(['api_key' => 'sk_test_p15']);
        }

        public function __get($name)
        {
            if ($name === 'subscriptions') {
                return $this->subStub;
            }

            return parent::__get($name);
        }
    };

    return new class ($client) extends StripeService {
        public function __construct(private StripeClient $fakeClient)
        {
            parent::__construct($fakeClient);
        }

        public function client(): StripeClient
        {
            return $this->fakeClient;
        }
    };
}

function makeCheckoutUser(string $customerId): User
{
    $user = new User();
    $user->email = 'co_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'co_buyer';
    $user->passwd = bin2hex(random_bytes(8));
    $user->stripe_customer_id = $customerId;
    $user->class = 0;
    $user->transfer_enable = 0;
    $user->node_group = 0;
    $user->class_expire = date('Y-m-d H:i:s', strtotime('-1 day'));
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

function makeCheckoutOrder(User $user, int $class, int $bandwidth): Order
{
    $order = new Order();
    $order->user_id = $user->id;
    $order->product_id = 7;
    $order->product_type = 'subscription';
    $order->product_name = 'Pro';
    $order->product_content = json_encode([
        'class' => $class,
        'bandwidth' => $bandwidth,
        'node_group' => 0,
        'speed_limit' => 0,
        'ip_limit' => 0,
        'billing_cycle_selected' => 'month',
        'name' => 'Pro',
    ]);
    $order->subscription_id = null;
    $order->coupon = '';
    $order->price = 10.0;
    $order->status = 'pending_payment';
    $order->billing_provider = 'stripe';
    $order->create_time = time();
    $order->update_time = time();
    $order->save();

    return $order;
}

function makeCheckoutInvoice(User $user, Order $order): Invoice
{
    $invoice = new Invoice();
    $invoice->user_id = $user->id;
    $invoice->order_id = $order->id;
    $invoice->content = json_encode([]);
    $invoice->price = $order->price;
    $invoice->status = 'unpaid';
    $invoice->create_time = time();
    $invoice->update_time = time();
    $invoice->pay_time = 0;
    $invoice->type = 'product';
    $invoice->billing_provider = 'stripe';
    $invoice->save();

    return $invoice;
}

it('creates a local subscription and grants membership on checkout completed', function () {
    $user = makeCheckoutUser('cus_p15');
    $order = makeCheckoutOrder($user, 2, 50);

    $fake = fakeCheckoutStripeService([
        'sspanel_user_id' => (string) $user->id,
        'product_id' => '7',
        'billing_cycle' => 'month',
        'order_id' => (string) $order->id,
        'invoice_id' => '0',
    ], 1234, 'usd');
    StripeService::setInstance($fake);

    (new WebhookHandler())->handle(makeCheckoutEvent('evt_co_1', 'cus_p15', 'sub_p15'));

    $sub = (new Subscription())->where('stripe_subscription_id', 'sub_p15')->first();
    expect($sub)->not->toBeNull();
    expect($sub->user_id)->toBe($user->id);
    expect($sub->billing_provider)->toBe('stripe');
    expect($sub->status)->toBe('active');
    expect($sub->stripe_status)->toBe('active');
    expect((int) $sub->auto_renew)->toBe(1);
    expect($sub->billing_cycle)->toBe('month');
    expect((int) $sub->stripe_amount)->toBe(1234);
    expect($sub->stripe_currency)->toBe('usd');

    // First-period membership grant fired.
    $fresh = (new User())->find($user->id);
    expect((int) $fresh->class)->toBe(2);
    expect((int) $fresh->transfer_enable)->toBe(Tools::gbToB(50));

    // The order was activated and linked back to the new subscription.
    $freshOrder = (new Order())->find($order->id);
    expect($freshOrder->status)->toBe('activated');
    expect((int) $freshOrder->subscription_id)->toBe($sub->id);
});

it('is idempotent on replay (no second subscription row, no double-grant)', function () {
    $user = makeCheckoutUser('cus_dup');
    $order = makeCheckoutOrder($user, 1, 1);

    $meta = [
        'sspanel_user_id' => (string) $user->id,
        'product_id' => '7',
        'billing_cycle' => 'month',
        'order_id' => (string) $order->id,
        'invoice_id' => '0',
    ];

    StripeService::setInstance(fakeCheckoutStripeService($meta));

    // Two DISTINCT event ids carrying the same subscription => the second must
    // not create a 2nd row nor re-grant.
    (new WebhookHandler())->handle(makeCheckoutEvent('evt_a', 'cus_dup', 'sub_dup'));

    $first = (new Subscription())->where('stripe_subscription_id', 'sub_dup')->first();
    expect($first)->not->toBeNull();

    // Simulate downstream usage between deliveries: if the grant ran twice it
    // would clobber this back to 0.
    $touch = (new User())->find($user->id);
    $touch->u = 999;
    $touch->save();

    (new WebhookHandler())->handle(makeCheckoutEvent('evt_b', 'cus_dup', 'sub_dup'));

    expect((new Subscription())->where('stripe_subscription_id', 'sub_dup')->count())->toBe(1);

    $after = (new User())->find($user->id);
    expect((int) $after->u)->toBe(999); // grant did NOT run again
});

it('ignores a session whose customer maps to no local user', function () {
    StripeService::setInstance(fakeCheckoutStripeService([
        'order_id' => '1',
        'billing_cycle' => 'month',
    ]));

    (new WebhookHandler())->handle(makeCheckoutEvent('evt_nouser', 'cus_unknown', 'sub_nouser'));

    expect((new Subscription())->where('stripe_subscription_id', 'sub_nouser')->count())->toBe(0);
});

it('does not grant access while checkout payment is still unpaid', function () {
    $user = makeCheckoutUser('cus_pending');
    $order = makeCheckoutOrder($user, 2, 50);

    StripeService::setInstance(fakeCheckoutStripeService([
        'sspanel_user_id' => (string) $user->id,
        'product_id' => '7',
        'billing_cycle' => 'month',
        'order_id' => (string) $order->id,
        'invoice_id' => '0',
    ]));

    (new WebhookHandler())->handle(makeCheckoutEvent('evt_pending', 'cus_pending', 'sub_pending', 'unpaid'));

    expect((new Subscription())->where('stripe_subscription_id', 'sub_pending')->count())->toBe(0);
    expect((new Order())->find($order->id)->status)->toBe('pending_payment');
    expect((int) (new User())->find($user->id)->class)->toBe(0);
});

it('marks the initial local invoice paid when checkout completes with a paid session', function () {
    $user = makeCheckoutUser('cus_invoice');
    $order = makeCheckoutOrder($user, 2, 50);
    $invoice = makeCheckoutInvoice($user, $order);

    StripeService::setInstance(fakeCheckoutStripeService([
        'sspanel_user_id' => (string) $user->id,
        'product_id' => '7',
        'billing_cycle' => 'month',
        'order_id' => (string) $order->id,
        'invoice_id' => (string) $invoice->id,
    ]));

    (new WebhookHandler())->handle(makeCheckoutEvent('evt_invoice', 'cus_invoice', 'sub_invoice'));

    $freshInvoice = (new Invoice())->find($invoice->id);
    expect($freshInvoice->status)->toBe('paid_gateway');
    expect((int) $freshInvoice->pay_time)->toBeGreaterThan(0);
});

it('does not record an event as processed when checkout side effects throw', function () {
    $user = makeCheckoutUser('cus_throw');
    $order = makeCheckoutOrder($user, 2, 50);

    $client = new class extends StripeClient {
        public function __construct()
        {
            parent::__construct(['api_key' => 'sk_test_throw']);
        }

        public function __get($name)
        {
            if ($name === 'subscriptions') {
                return new class {
                    public function retrieve($id, $params = null, $opts = null): \Stripe\Subscription
                    {
                        throw new RuntimeException('stripe unavailable');
                    }
                };
            }

            return parent::__get($name);
        }
    };

    StripeService::setInstance(new class ($client) extends StripeService {
        public function __construct(private StripeClient $fakeClient)
        {
            parent::__construct($fakeClient);
        }

        public function client(): StripeClient
        {
            return $this->fakeClient;
        }
    });

    expect(fn () => (new WebhookHandler())->handle(
        makeCheckoutEvent('evt_side_effect_fails', 'cus_throw', 'sub_throw')
    ))->toThrow(RuntimeException::class);

    expect((new StripeEvent())->where('event_id', 'evt_side_effect_fails')->exists())->toBeFalse();
    expect((new Subscription())->where('stripe_subscription_id', 'sub_throw')->exists())->toBeFalse();
});
