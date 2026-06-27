<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Models\StripeEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Stripe\Event;

/**
 * Top-level Stripe webhook dispatcher.
 *
 * Responsibilities:
 *  - Idempotency: every event is recorded once on `stripe_event.event_id`
 *    (UNIQUE); a redelivery of the same event is a no-op.
 *  - Routing: dispatch by `$event->type` to a per-type handler.
 *  - Unknown / not-handled types are safe no-ops.
 *
 * The only live handler here is setup_intent.succeeded (card-binding for the
 * self-managed renewal engine). One-time / first-purchase settlement runs inline
 * in Stripe::notify on payment_intent.succeeded. The native Stripe-subscription
 * handlers (checkout.session-mode subscription create, invoice.paid/failed
 * renewals, customer.subscription.deleted) were removed with that flow.
 *
 * Event rows are recorded only after the selected handler returns, so Stripe
 * redeliveries are still able to retry failed side effects.
 */
final class WebhookHandler
{
    /**
     * Webhook entry point: dedup on StripeEvent.event_id, then dispatch.
     */
    public function handle(Event $event): void
    {
        if ((new StripeEvent())->where('event_id', $event->id)->exists()) {
            return;
        }

        switch ($event->type) {
            case 'setup_intent.succeeded':
                $this->handleSetupIntentSucceeded($event);
                break;
            default:
                // No-op for unknown / not-handled types.
                break;
        }

        $this->recordProcessedEvent($event);
    }

    private function recordProcessedEvent(Event $event): void
    {
        try {
            $record = new StripeEvent();
            $record->event_id = $event->id;
            $record->type = $event->type;
            $record->created_at = Carbon::now()->format('Y-m-d H:i:s');
            $record->save();
        } catch (QueryException $e) {
            // A concurrent delivery already inserted this event_id. Treat as a
            // duplicate (idempotent no-op) only for the UNIQUE violation;
            // rethrow anything else.
            if ($this->isUniqueViolation($e)) {
                return;
            }

            throw $e;
        }
    }

    /**
     * MySQL/MariaDB duplicate-key error code is 1062 (SQLSTATE 23000).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || ($e->getCode() === '23000');
    }

    /**
     * setup_intent.succeeded: a user saved a card via the self-service payment
     * method page (an off_session SetupIntent). Bind that card as the customer's
     * DEFAULT payment method so the renewal engine's card fallback
     * (SubscriptionService::chargeRenewalToCard -> getDefaultPaymentMethod) can
     * charge it off-session later. The webhook — NOT the client confirmSetup —
     * is the source of truth for setting the default.
     *
     * S5: never trust client-supplied ids. The local user is resolved ONLY from
     * the server-stored stripe_customer_id on the event. No-op if the event is
     * missing the customer / payment method, or the customer maps to no local
     * user. setCustomerDefaultPaymentMethod re-attaches the PM, which is a Stripe
     * no-op for the already-attached SetupIntent card (idempotent on redelivery).
     */
    private function handleSetupIntentSucceeded(Event $event): void
    {
        $setupIntent = $event->data->object;
        $customerId = $setupIntent->customer ?? null;
        $paymentMethodId = $setupIntent->payment_method ?? null;

        if ($customerId === null || $paymentMethodId === null) {
            return;
        }

        $user = (new User())->where('stripe_customer_id', $customerId)->first();

        if ($user === null) {
            return;
        }

        StripeService::getInstance()->setCustomerDefaultPaymentMethod($customerId, $paymentMethodId);
    }
}
