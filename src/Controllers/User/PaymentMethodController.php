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
        $customerId = $stripe->ensureCustomer($this->user);

        $card = null;

        try {
            $paymentMethodId = $stripe->getDefaultPaymentMethod($customerId);

            if ($paymentMethodId !== null) {
                $pm = $stripe->retrievePaymentMethod($paymentMethodId);

                if ($pm !== null && isset($pm->card)) {
                    $card = [
                        'brand' => $pm->card->brand ?? '',
                        'last4' => $pm->card->last4 ?? '',
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
        $customerId = $stripe->ensureCustomer($this->user);

        try {
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
        $customerId = $stripe->ensureCustomer($this->user);

        try {
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
