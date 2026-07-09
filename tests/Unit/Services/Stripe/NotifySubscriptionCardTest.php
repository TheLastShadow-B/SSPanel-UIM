<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Paylist;
use App\Models\User;
use App\Services\Gateway\Stripe;
use App\Services\Stripe\StripeService;
use GuzzleHttp\Psr7\HttpFactory;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;
use Stripe\StripeClient;
use Tests\TestDatabase;

require_once __DIR__ . '/../AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * Task B1 — payment_intent.succeeded (trade_no settle path): after a FIRST-
 * period SUBSCRIPTION invoice settles via Checkout, bind the card Stripe saved
 * off-session as the customer DEFAULT payment method, so the renewal engine's
 * card fallback (SubscriptionService::chargeRenewalToCard ->
 * getDefaultPaymentMethod) has a card to charge.
 *
 * A non-subscription settle (topup / one-off) must NOT touch the default card —
 * the existing trade_no settle behavior is unchanged for those.
 *
 * The webhook is signed locally with the configured endpoint secret so
 * Webhook::constructEvent verifies for real; StripeService is stubbed via
 * setInstance so nothing touches the network. S5: the customer is bound only
 * from the server-stored stripe_customer_id (never a client-supplied id).
 * ---------------------------------------------------------------------------
 */

const NOTIFY_CARD_SECRET = 'whsec_test_notify_card_secret';

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
    ensureUserMoneyLogTable();

    Config::query()->updateOrInsert(
        ['item' => 'stripe_endpoint_secret'],
        ['value' => NOTIFY_CARD_SECRET, 'class' => 'billing', 'type' => 'string']
    );
});

afterEach(function () {
    dropUserMoneyLogTable();
    TestDatabase::dropTables();
    StripeService::setInstance(new StripeService(new StripeClient(['api_key' => 'sk_test_x'])));
});

/**
 * Fake StripeService that records setCustomerDefaultPaymentMethod() calls so a
 * test can assert the webhook bound the saved card as the customer default —
 * without ever reaching the network.
 */
function notifyCardFakeStripe(): StripeService
{
    return new class (new StripeClient(['api_key' => 'sk_test_card'])) extends StripeService {
        /** @var array<int,array{customerId:string,pmId:string}> */
        public array $defaultCalls = [];

        public function setCustomerDefaultPaymentMethod(string $customerId, string $paymentMethodId): void
        {
            $this->defaultCalls[] = ['customerId' => $customerId, 'pmId' => $paymentMethodId];
        }
    };
}

/**
 * Build a Stripe webhook request whose Stripe-Signature header is valid for the
 * test endpoint secret, with $payload as the raw (already-JSON) body.
 */
function notifyCardRequest(string $payload)
{
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, NOTIFY_CARD_SECRET);
    $header = 't=' . $timestamp . ',v1=' . $signature;

    $guzzle = new HttpFactory();
    $stream = $guzzle->createStream($payload);
    $stream->rewind();

    $request = (new DecoratedServerRequestFactory($guzzle))
        ->createServerRequest('POST', '/payment/notify/stripe')
        ->withHeader('Stripe-Signature', $header)
        ->withBody($stream);

    $response = (new DecoratedResponseFactory($guzzle, $guzzle))->createResponse();

    return [$request, $response];
}

function notifyCardUser(string $customerId): User
{
    $user = new User();
    $user->email = 'notifycard_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'notifycard';
    $user->passwd = bin2hex(random_bytes(8));
    $user->money = 0;
    $user->stripe_customer_id = $customerId;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->save();

    return $user;
}

/**
 * The settled chain a trade_no resolves through: Paylist -> Invoice -> Order.
 * $productType drives the subscription gate. paylist.total == invoice.price so
 * postPayment takes no overpay/UserMoneyLog branch (no extra tables needed).
 */
function notifyCardSettleRows(int $userId, string $productType, string $tradeNo): array
{
    $order = new Order();
    $order->user_id = $userId;
    $order->product_id = 1;
    $order->product_type = $productType;
    $order->product_name = 'Pro';
    $order->product_content = json_encode(
        $productType === 'topup'
            ? ['amount' => 30.0]
            : [
                'class' => 1,
                'bandwidth' => 100,
                'billing_cycle_selected' => 'month',
                'name' => 'Pro',
                'node_group' => 1,
                'speed_limit' => 0,
                'ip_limit' => 0,
            ]
    );
    $order->coupon = '';
    $order->price = 30.0;
    $order->status = 'pending_payment';
    $order->create_time = time();
    $order->update_time = time();
    $order->billing_provider = 'manual';
    $order->save();

    $invoice = new Invoice();
    $invoice->type = 'product';
    $invoice->user_id = $userId;
    $invoice->order_id = $order->id;
    $invoice->content = json_encode([['content_id' => 0, 'name' => 'Sub', 'price' => 30]]);
    $invoice->price = 30;
    $invoice->status = 'unpaid';
    $invoice->create_time = time();
    $invoice->update_time = time();
    $invoice->billing_provider = 'manual';
    $invoice->save();

    $paylist = new Paylist();
    $paylist->userid = $userId;
    $paylist->total = 30;
    $paylist->status = 0;
    $paylist->invoice_id = $invoice->id;
    $paylist->tradeno = $tradeNo;
    $paylist->gateway = 'Stripe';
    $paylist->save();

    return [$order, $invoice, $paylist];
}

function notifyCardPayload(string $evtId, string $piId, string $tradeNo, ?string $customer, ?string $pm): string
{
    $object = [
        'id' => $piId,
        'status' => 'succeeded',
        'metadata' => ['trade_no' => $tradeNo],
    ];

    if ($customer !== null) {
        $object['customer'] = $customer;
    }

    if ($pm !== null) {
        $object['payment_method'] = $pm;
    }

    return json_encode([
        'id' => $evtId,
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => $object],
    ]);
}

it('binds the saved card as the customer default after a subscription invoice settles', function () {
    $user = notifyCardUser('cus_notify_sub');
    [, $invoice] = notifyCardSettleRows($user->id, 'subscription', 'trade_card_sub');

    $fake = notifyCardFakeStripe();
    StripeService::setInstance($fake);

    [$request, $response] = notifyCardRequest(
        notifyCardPayload('evt_card_sub', 'pi_card_sub', 'trade_card_sub', 'cus_notify_sub', 'pm_card_sub')
    );

    $result = (new Stripe())->notify($request, $response, []);

    expect($result->getStatusCode())->toBe(204);
    // Settled exactly as before.
    expect((new Invoice())->find($invoice->id)->status)->toBe('paid_gateway');
    // Card bound as the customer default.
    expect($fake->defaultCalls)->toHaveCount(1);
    expect($fake->defaultCalls[0]['customerId'])->toBe('cus_notify_sub');
    expect($fake->defaultCalls[0]['pmId'])->toBe('pm_card_sub');
});

it('does NOT touch the default card when the settled order is not a subscription', function () {
    $user = notifyCardUser('cus_notify_top');
    [, $invoice] = notifyCardSettleRows($user->id, 'topup', 'trade_card_top');

    $fake = notifyCardFakeStripe();
    StripeService::setInstance($fake);

    [$request, $response] = notifyCardRequest(
        notifyCardPayload('evt_card_top', 'pi_card_top', 'trade_card_top', 'cus_notify_top', 'pm_card_top')
    );

    $result = (new Stripe())->notify($request, $response, []);

    expect($result->getStatusCode())->toBe(204);
    // Still settled (unchanged one-off behavior)...
    expect((new Invoice())->find($invoice->id)->status)->toBe('paid_gateway');
    // ...but no default card was set.
    expect($fake->defaultCalls)->toHaveCount(0);
});

it('is a no-op (and still settles) when the PI carries no payment_method', function () {
    $user = notifyCardUser('cus_notify_nopm');
    [, $invoice] = notifyCardSettleRows($user->id, 'subscription', 'trade_card_nopm');

    $fake = notifyCardFakeStripe();
    StripeService::setInstance($fake);

    [$request, $response] = notifyCardRequest(
        notifyCardPayload('evt_card_nopm', 'pi_card_nopm', 'trade_card_nopm', 'cus_notify_nopm', null)
    );

    (new Stripe())->notify($request, $response, []);

    expect((new Invoice())->find($invoice->id)->status)->toBe('paid_gateway');
    expect($fake->defaultCalls)->toHaveCount(0);
});

it('is a no-op when the PI customer maps to no local user (never trusts client data)', function () {
    $user = notifyCardUser('cus_real');
    notifyCardSettleRows($user->id, 'subscription', 'trade_card_idor');

    $fake = notifyCardFakeStripe();
    StripeService::setInstance($fake);

    // The PI customer does not match any server-stored stripe_customer_id.
    [$request, $response] = notifyCardRequest(
        notifyCardPayload('evt_card_idor', 'pi_card_idor', 'trade_card_idor', 'cus_forged', 'pm_forged')
    );

    (new Stripe())->notify($request, $response, []);

    expect($fake->defaultCalls)->toHaveCount(0);
});
