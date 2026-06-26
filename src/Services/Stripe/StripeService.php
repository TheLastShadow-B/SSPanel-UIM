<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Models\Config;
use App\Models\User;
use Stripe\Checkout\Session;
use Stripe\Collection;
use Stripe\PaymentIntent;
use Stripe\SetupIntent;
use Stripe\StripeClient;
use Stripe\Subscription;

/**
 * Injectable wrapper around \Stripe\StripeClient.
 *
 * Each public method is a thin wrapper over a single Stripe SDK call so tests
 * can swap a fake subclass via setInstance() and override individual methods
 * (or client()) without ever touching live Stripe.
 *
 * NEVER pass payment_method_types anywhere — dynamic payment methods are used.
 */
class StripeService
{
    private static ?self $instance = null;

    private StripeClient $client;

    public function __construct(?StripeClient $client = null)
    {
        $this->client = $client ?? new StripeClient([
            'api_key' => (string) Config::obtain('stripe_api_key'),
            'stripe_version' => '2026-03-25.dahlia',
        ]);
    }

    public function client(): StripeClient
    {
        return $this->client;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function setInstance(self $fake): void
    {
        self::$instance = $fake;
    }

    public function ensureCustomer(User $user): string
    {
        if (! empty($user->stripe_customer_id)) {
            return $user->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'email' => $user->email,
            'metadata' => ['sspanel_user_id' => (string) $user->id],
        ]);

        $user->stripe_customer_id = $customer->id;
        $user->save();

        return $customer->id;
    }

    public function createSubscriptionCheckout(
        User $user,
        string $priceId,
        array $metadata,
        string $successUrl,
        string $cancelUrl
    ): Session {
        $customerId = $this->ensureCustomer($user);

        // NOTE: deliberately NOT passing payment_method_types (dynamic payment methods).
        return $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [
                ['price' => $priceId, 'quantity' => 1],
            ],
            'subscription_data' => [
                'metadata' => $metadata,
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);
    }

    public function createSetupIntent(string $customerId, array $metadata = []): SetupIntent
    {
        return $this->client()->setupIntents->create([
            'customer' => $customerId,
            'usage' => 'off_session',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Off-session one-time charge against a stored card.
     *
     * NOTE: deliberately NOT passing payment_method_types (dynamic payment methods).
     */
    public function chargeOffSession(
        string $customerId,
        string $paymentMethodId,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        array $metadata = []
    ): PaymentIntent {
        return $this->client()->paymentIntents->create([
            'amount' => $amountMinor,
            'currency' => $currency,
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
            'off_session' => true,
            'confirm' => true,
            'metadata' => $metadata,
        ], [
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function setDefaultPaymentMethod(string $customerId, string $subscriptionId, string $paymentMethodId): void
    {
        $this->client()->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);

        $this->client()->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        $this->client()->subscriptions->update($subscriptionId, [
            'default_payment_method' => $paymentMethodId,
        ]);
    }

    public function cancelAtPeriodEnd(string $subscriptionId): void
    {
        $this->client()->subscriptions->update($subscriptionId, [
            'cancel_at_period_end' => true,
        ]);
    }

    public function updateSubscriptionPrice(
        string $subscriptionId,
        string $newPriceId,
        string $prorationBehavior
    ): Subscription {
        $subscription = $this->client()->subscriptions->retrieve($subscriptionId, []);
        $itemId = $subscription->items->data[0]->id;

        return $this->client()->subscriptions->update($subscriptionId, [
            'items' => [
                ['id' => $itemId, 'price' => $newPriceId],
            ],
            'proration_behavior' => $prorationBehavior,
        ]);
    }

    public function createAlignedSubscription(
        string $customerId,
        string $priceId,
        int $anchorTs,
        string $defaultPaymentMethod,
        array $metadata
    ): Subscription {
        return $this->client()->subscriptions->create([
            'customer' => $customerId,
            'items' => [
                ['price' => $priceId],
            ],
            'billing_cycle_anchor' => $anchorTs,
            'proration_behavior' => 'none',
            'default_payment_method' => $defaultPaymentMethod,
            'metadata' => $metadata,
        ]);
    }

    public function listInvoices(string $customerId): Collection
    {
        return $this->client()->invoices->all(['customer' => $customerId]);
    }
}
