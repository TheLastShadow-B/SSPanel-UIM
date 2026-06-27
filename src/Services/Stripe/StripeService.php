<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Models\Config;
use App\Models\User;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\StripeClient;

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

    /**
     * Customer's stored default payment method (invoice_settings.default_payment_method),
     * or null when none is set. The field may come back as a bare id string or an
     * expanded object, so resolve both forms.
     */
    public function getDefaultPaymentMethod(string $customerId): ?string
    {
        $customer = $this->client()->customers->retrieve($customerId, []);

        $pm = $customer->invoice_settings->default_payment_method ?? null;
        if ($pm === null) {
            return null;
        }

        return is_string($pm) ? $pm : ($pm->id ?? null);
    }

    /**
     * Attach a payment method and set it as the customer's default off-session
     * payment method (used by the renewal engine's card fallback and the
     * card-binding page). Does not touch any Stripe subscription.
     */
    public function setCustomerDefaultPaymentMethod(string $customerId, string $paymentMethodId): void
    {
        $this->client()->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);

        $this->client()->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);
    }

    /**
     * Retrieve a payment method (for its brand/last4 card summary), or null when
     * Stripe can't resolve it — so the payment-method page degrades gracefully
     * instead of 500ing on a transient API hiccup.
     */
    public function retrievePaymentMethod(string $paymentMethodId): ?PaymentMethod
    {
        try {
            return $this->client()->paymentMethods->retrieve($paymentMethodId);
        } catch (ApiErrorException) {
            return null;
        }
    }

    /**
     * Detach a payment method from its customer. Detaching the customer's default
     * payment method also clears it from invoice_settings.default_payment_method
     * on Stripe's side, so the renewal engine's card fallback finds no card.
     */
    public function detachPaymentMethod(string $paymentMethodId): void
    {
        $this->client()->paymentMethods->detach($paymentMethodId);
    }
}
