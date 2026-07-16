<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Paylist;
use App\Models\User;
use App\Services\Auth;
use App\Services\Exchange;
use App\Services\Stripe\StripeService;
use App\Services\Stripe\WebhookHandler;
use App\Services\View;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RedisException;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;
use voku\helper\AntiXSS;
use function in_array;

final class Stripe extends Base
{
    public function __construct()
    {
        $this->antiXss = new AntiXSS();
    }

    public static function _name(): string
    {
        return 'stripe';
    }

    public static function _enable(): bool
    {
        return self::getActiveGateway('stripe');
    }

    public static function _readableName(): string
    {
        return 'Stripe';
    }

    public function purchase(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $invoice_id = $this->antiXss->xss_clean($request->getParam('invoice_id'));
        $user = Auth::getUser();
        $invoice = (new Invoice())->where('id', $invoice_id)
            ->where('user_id', $user->id)
            ->first();

        if ($invoice === null) {
            return $response->withJson([
                'ret' => 0,
                'msg' => 'Invoice not found',
            ]);
        }

        $price = $invoice->price;

        if ($price < Config::obtain('stripe_min_recharge') ||
            $price > Config::obtain('stripe_max_recharge')
        ) {
            return $response->withJson([
                'ret' => 0,
                'msg' => 'Price out of range',
            ]);
        }

        $pl = (new Paylist())->where('invoice_id', $invoice_id)->first();

        if ($pl === null) {
            $pl = new Paylist();
            $pl->userid = $user->id;
            $pl->total = $price;
            $pl->invoice_id = $invoice_id;
            $pl->tradeno = self::generateGuid();
        }

        $pl->gateway = self::_readableName();
        $pl->save();

        $stripe_currency = Config::obtain('stripe_currency');

        try {
            $exchange_amount = Exchange::getInstance()->exchange((float) $price, 'CNY', $stripe_currency);
        } catch (GuzzleException|RedisException) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '汇率获取失败',
            ]);
        }
        // https://docs.stripe.com/currencies?presentment-currency=US#zero-decimal
        if (! in_array(
            $stripe_currency,
            ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
                'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
            ]
        )) {
            $exchange_amount *= 100;
        }

        // 已绑卡快捷支付:支付方式全部服务端推导(客户的默认支付方式),不读任何
        // 客户端 id。成功后与 Checkout 走同一结算路径 —— payment_intent.succeeded
        // webhook 按 metadata.trade_no 调 postPayment,幂等键锚定 tradeno 防重复扣款。
        if ((bool) $request->getParam('use_saved_card')) {
            $stripeService = StripeService::getInstance();

            try {
                $customerId = $stripeService->ensureCustomer($user);
                $paymentMethodId = $stripeService->getDefaultPaymentMethod($customerId);

                if ($paymentMethodId === null) {
                    return $response->withJson([
                        'ret' => 0,
                        'msg' => '尚未绑定支付方式，请先在「个人设置 → 支付方式」中绑定',
                    ]);
                }

                $stripeService->chargeOffSession(
                    $customerId,
                    $paymentMethodId,
                    (int) round($exchange_amount),
                    $stripe_currency,
                    'inv_card_' . $pl->tradeno,
                    ['trade_no' => $pl->tradeno, 'invoice_id' => (string) $invoice->id]
                );
            } catch (ApiErrorException $e) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '扣款未成功：' . ($e->getError()->message ?? '卡片被拒绝，请尝试其他支付方式'),
                ]);
            }

            return $response->withHeader(
                'HX-Redirect',
                $_ENV['baseUrl'] . '/user/invoice/' . $invoice_id . '/view?paid=1'
            );
        }

        $stripe = StripeService::getInstance()->client();
        $session = null;

        // Subscription FIRST-period invoices (the balance-insufficient path) save the
        // card off-session so the renewal engine's card fallback
        // (SubscriptionService::chargeRenewalToCard -> getDefaultPaymentMethod) has a
        // card to charge later. Detected server-side via the invoice's
        // Order.product_type; topups / one-off products are left EXACTLY as before
        // (no customer, no setup_future_usage). The webhook — NOT the client — sets
        // the saved card as the customer default after settlement.
        $order = (int) $invoice->order_id > 0
            ? (new Order())->find((int) $invoice->order_id)
            : null;
        $is_subscription = $order !== null && $order->product_type === 'subscription';

        $payment_intent_data = [
            'metadata' => [
                'trade_no' => $pl->tradeno,
            ],
        ];

        $session_params = [
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => Config::obtain('stripe_currency'),
                        'product_data' => [
                            'name' => 'Invoice #' . $invoice_id,
                        ],
                        'unit_amount' => (int) round($exchange_amount),
                    ],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'success_url' => $_ENV['baseUrl'] . '/user/invoice/' . $invoice_id . '/view?session_id={CHECKOUT_SESSION_ID}&paid=1',
            'cancel_url' => $_ENV['baseUrl'] . '/user/invoice/' . $invoice_id . '/view?canceled=1',
        ];

        if ($is_subscription) {
            // Bind the Checkout to the Stripe customer + flag the card for off-session
            // reuse so Stripe saves it. customer_email is mutually exclusive with
            // customer, so it is omitted on this branch (the customer already carries
            // the email). The bind ids ride along in metadata for observability.
            $session_params['customer'] = StripeService::getInstance()->ensureCustomer($user);
            $payment_intent_data['setup_future_usage'] = 'off_session';
            $payment_intent_data['metadata']['invoice_id'] = (string) $invoice->id;
            $payment_intent_data['metadata']['order_id'] = (string) $order->id;
        } else {
            // Unchanged one-off / topup behavior: prefill the email, never save a card.
            $session_params['customer_email'] = $user->email;
        }

        $session_params['payment_intent_data'] = $payment_intent_data;

        try {
            $session = $stripe->checkout->sessions->create($session_params, [
                'idempotency_key' => 'checkout_' . $pl->tradeno,
            ]);
        } catch (ApiErrorException) {
            return $response->withJson([
                'ret' => 0,
                'msg' => 'Stripe API error',
            ]);
        }

        return $response->withHeader('HX-Redirect', $session->url);
    }

    public function notify(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        try {
            $event = Webhook::constructEvent(
                $request->getBody()->getContents(),
                $request->getHeaderLine('Stripe-Signature'),
                Config::obtain('stripe_endpoint_secret')
            );
        } catch (UnexpectedValueException) {
            return $response->withStatus(400)->withJson([
                'ret' => 0,
                'msg' => 'Unexpected Value error',
            ]);
        } catch (SignatureVerificationException) {
            return $response->withStatus(400)->withJson([
                'ret' => 0,
                'msg' => 'Signature Verification error',
            ]);
        }

        // One-time Stripe charges (mode:'payment') still flow through postPayment.
        $object = $event->data->object;

        if ($event->type === 'payment_intent.succeeded' && $object->status === 'succeeded') {
            $tradeNo = $object->metadata->trade_no ?? null;

            // Off-session self-managed renewal PaymentIntents (SubscriptionService::chargeRenewalToCard)
            // carry only metadata.invoice_id — no trade_no — and are already settled inline by the
            // auto-renew cron. There is no Paylist to advance, so a missing/empty trade_no is a handled
            // no-op: calling postPayment(null) would TypeError -> 500 -> Stripe retry loop.
            if ($tradeNo !== null && $tradeNo !== '') {
                $this->postPayment($tradeNo);
                $this->saveSubscriptionCardAsDefault($object, $tradeNo);
            }

            return $response->withStatus(204);
        }

        // All other events (subscription auto-billing) go through the handler,
        // which dedups on stripe_event and dispatches by type.
        (new WebhookHandler())->handle($event);

        return $response->withStatus(204);
    }

    /**
     * After a FIRST-period SUBSCRIPTION invoice settles via the one-time Checkout
     * (the balance-insufficient path), bind the card Stripe saved off-session
     * (payment_intent_data.setup_future_usage='off_session' in purchase()) as the
     * customer's DEFAULT payment method, so the renewal engine's card fallback
     * (SubscriptionService::chargeRenewalToCard -> getDefaultPaymentMethod) has a
     * card to charge. The webhook — NOT the client confirm — is the source of truth.
     *
     * S5: never trust client data. The customer is resolved ONLY from the
     * server-stored stripe_customer_id matching the PaymentIntent's customer; the
     * "subscription order" gate walks the fully server-side trade_no -> Paylist ->
     * Invoice -> Order chain. Idempotent / no-op-safe: re-delivery just re-sets the
     * same default (a Stripe no-op), and a missing payment_method / customer, an
     * unknown customer, or a non-subscription order (topups, one-off products) all
     * short-circuit before any Stripe call.
     */
    private function saveSubscriptionCardAsDefault(object $object, string $tradeNo): void
    {
        $customerId = $object->customer ?? null;
        $paymentMethodId = $object->payment_method ?? null;

        if ($customerId === null || $paymentMethodId === null) {
            return;
        }

        // S5: only act for a Stripe customer we manage (server-stored id), never an
        // id taken from the event payload as authoritative on its own.
        $user = (new User())->where('stripe_customer_id', $customerId)->first();

        if ($user === null) {
            return;
        }

        // Subscription-order gate — resolved entirely from the settled trade_no.
        $paylist = (new Paylist())->where('tradeno', $tradeNo)->first();

        if ($paylist === null) {
            return;
        }

        $invoice = (new Invoice())->find((int) $paylist->invoice_id);

        if ($invoice === null) {
            return;
        }

        $order = (int) $invoice->order_id > 0
            ? (new Order())->find((int) $invoice->order_id)
            : null;

        if ($order === null || $order->product_type !== 'subscription') {
            return;
        }

        // Defense in depth: the settled order must belong to the resolved customer.
        if ((int) $order->user_id !== (int) $user->id) {
            return;
        }

        StripeService::getInstance()->setCustomerDefaultPaymentMethod($customerId, $paymentMethodId);
    }

    /**
     * @throws Exception
     */
    public static function getPurchaseHTML(): string
    {
        return View::getSmarty()->fetch('gateway/stripe.tpl');
    }
}
