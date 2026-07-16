<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Models\Config;
use App\Services\Stripe\StripeService;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Stripe\Exception\ApiErrorException;

/**
 * Self-service "支付方式 / Payment method" page: save a card off-session (a
 * SetupIntent + Stripe Elements) so the renewal engine's card fallback
 * (SubscriptionService::chargeRenewalToCard -> getDefaultPaymentMethod) can
 * charge it off-session later, or remove the saved card.
 *
 * SECURITY (S5): every endpoint acts ONLY on $this->user. The Stripe customer id
 * is always server-derived via StripeService::ensureCustomer()/the stored
 * stripe_customer_id; a customer / payment-method / SetupIntent id is NEVER read
 * from the request. The setup_intent.succeeded webhook — not the client
 * confirmSetup — is the source of truth for setting the saved card as default.
 */
final class PaymentMethodController extends BaseController
{
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $stripe = StripeService::getInstance();

        $card = null;

        try {
            // ensureCustomer may hit the Stripe API to CREATE a customer for a
            // user lacking stripe_customer_id; keep it INSIDE the try so a
            // failure degrades to "no saved card" rather than a 500.
            $customerId = $stripe->ensureCustomer($this->user);
            $paymentMethodId = $stripe->getDefaultPaymentMethod($customerId);

            if ($paymentMethodId === null) {
                // setup_intent.succeeded webhook 迟到/丢失兜底:confirmSetup 已把
                // 支付方式附加到客户上,只是默认位没设。采用最近附加的一张并设为
                // 默认(S5:全部服务端推导,不读任何客户端 id)。
                $paymentMethodId = $stripe->getLatestAttachedPaymentMethod($customerId);

                if ($paymentMethodId !== null) {
                    $stripe->setCustomerDefaultPaymentMethod($customerId, $paymentMethodId);
                }
            }

            if ($paymentMethodId !== null) {
                $pm = $stripe->retrievePaymentMethod($paymentMethodId);

                if ($pm !== null && isset($pm->card)) {
                    $card = [
                        'brand' => $pm->card->brand ?? '',
                        'last4' => $pm->card->last4 ?? '',
                        'email' => '',
                    ];
                } elseif ($pm !== null && $pm->type === 'link') {
                    // Link 钱包保存的支付方式没有 card 明细,展示 Link 账户邮箱。
                    $card = [
                        'brand' => 'Link',
                        'last4' => '',
                        'email' => (string) ($pm->link->email ?? ''),
                    ];
                }
            }
        } catch (ApiErrorException) {
            // Degrade to "no saved card" rather than 500 on a transient Stripe hiccup.
            $card = null;
        }

        return $response->write(
            $this->view()
                ->assign('card', $card)
                ->assign('publishable_key', (string) Config::obtain('stripe_publishable_key'))
                ->fetch('user/payment_method.tpl')
        );
    }

    public function createSetupIntent(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $stripe = StripeService::getInstance();

        try {
            // ensureCustomer may hit the Stripe API to CREATE a customer for a
            // user lacking stripe_customer_id; keep it INSIDE the try so an
            // ApiErrorException returns handled JSON instead of an uncaught 500.
            $customerId = $stripe->ensureCustomer($this->user);
            $setupIntent = $stripe->createSetupIntent($customerId, [
                'sspanel_user_id' => (string) $this->user->id,
            ]);
        } catch (ApiErrorException) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '创建 SetupIntent 失败',
            ]);
        }

        return $response->withJson([
            'client_secret' => $setupIntent->client_secret,
            'publishable_key' => (string) Config::obtain('stripe_publishable_key'),
        ]);
    }

    public function detach(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $stripe = StripeService::getInstance();

        try {
            // ensureCustomer may hit the Stripe API to CREATE a customer; keep it
            // INSIDE the try so a failure returns handled JSON instead of a 500.
            $customerId = $stripe->ensureCustomer($this->user);
            // Server-derived: only ever THIS user's current default PM, never a
            // client-supplied id.
            $paymentMethodId = $stripe->getDefaultPaymentMethod($customerId);

            if ($paymentMethodId !== null) {
                $stripe->detachPaymentMethod($paymentMethodId);
            }
        } catch (ApiErrorException) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '移除支付方式失败',
            ]);
        }

        return $response->withJson([
            'ret' => 1,
            'msg' => '已移除支付方式',
        ]);
    }
}
