<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Models\StripeEvent;
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
 *  - Unknown / not-yet-implemented types are safe no-ops.
 *
 * The per-type handler bodies are filled in by later tasks:
 *  - handleCheckoutCompleted    (P1.5)
 *  - handleInvoicePaid          (P1.6)
 *  - handleInvoiceFailed        (P1.7)
 *  - handleSubscriptionDeleted  (P1.8)
 *  - setup_intent.succeeded     (P3.3, extended P5.3)
 */
final class WebhookHandler
{
    /**
     * Webhook entry point: dedup on StripeEvent.event_id, then dispatch.
     */
    public function handle(Event $event): void
    {
        // Idempotency. The UNIQUE constraint on event_id is the authoritative
        // guard; the exists() check is a cheap fast-path. Catching the
        // duplicate-insert QueryException closes the check-then-insert race
        // (Stripe redelivers events, and deliveries can overlap).
        if ((new StripeEvent())->where('event_id', $event->id)->exists()) {
            return;
        }

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

        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handleCheckoutCompleted($event);
                break;
            case 'invoice.paid':
                $this->handleInvoicePaid($event);
                break;
            case 'invoice.payment_failed':
            case 'invoice.payment_action_required':
                $this->handleInvoiceFailed($event);
                break;
            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event);
                break;
            case 'customer.subscription.updated':
            case 'setup_intent.succeeded':
            default:
                // Handled in later tasks / no-op for unknown types.
                break;
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

    private function handleCheckoutCompleted(Event $event): void
    {
        // Implemented in Task P1.5.
    }

    private function handleInvoicePaid(Event $event): void
    {
        // Implemented in Task P1.6.
    }

    private function handleInvoiceFailed(Event $event): void
    {
        // Implemented in Task P1.7.
    }

    private function handleSubscriptionDeleted(Event $event): void
    {
        // Implemented in Task P1.8.
    }
}
