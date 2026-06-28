<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Models\Subscription;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Utils\Tools;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Stripe\Event;
use function json_decode;

/**
 * Top-level Stripe webhook dispatcher.
 *
 * Responsibilities:
 *  - Idempotency: every event is recorded once on `stripe_event.event_id`
 *    (UNIQUE); a redelivery of the same event is a no-op.
 *  - Routing: dispatch by `$event->type` to a per-type handler.
 *  - Unknown / not-handled types are safe no-ops.
 *
 * New subscription purchases now use the self-managed renewal engine. Existing
 * native Stripe subscription rows still need their runtime webhooks until those
 * rows are migrated or cancelled, so invoice/subscription events below remain
 * compatibility handlers.
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

    /**
     * Compatibility path for native Stripe subscriptions created before the
     * self-managed renewal engine. New purchases no longer create this shape,
     * but existing rows still rely on invoice.paid to advance local access.
     */
    private function handleInvoicePaid(Event $event): void
    {
        $invoice = $event->data->object;
        $stripeSubId = $invoice->subscription ?? null;
        $customerId = $invoice->customer ?? null;
        $reason = $invoice->billing_reason ?? null;

        if ($stripeSubId === null || $customerId === null) {
            return;
        }

        $subscription = (new Subscription())->where('stripe_subscription_id', $stripeSubId)->first();

        if ($subscription === null || $subscription->billing_provider !== 'stripe') {
            return;
        }

        $user = (new User())->find($subscription->user_id);

        if ($user === null || $user->stripe_customer_id !== $customerId) {
            return;
        }

        if ($reason === 'subscription_create') {
            return;
        }

        if ($reason !== 'subscription_cycle') {
            return;
        }

        $invoiceId = $invoice->id ?? null;

        if ($invoiceId !== null && $subscription->last_paid_stripe_invoice_id === $invoiceId) {
            return;
        }

        $newStart = Carbon::parse($subscription->end_date)->addDay();
        $newEnd = SubscriptionService::calculateEndDate($newStart, $subscription->billing_cycle);

        $subscription->start_date = $newStart->format('Y-m-d');
        $subscription->end_date = $newEnd->format('Y-m-d');
        $subscription->status = 'active';
        $subscription->stripe_status = 'active';
        $subscription->last_paid_stripe_invoice_id = $invoiceId;
        $subscription->last_reset_date = Carbon::today()->format('Y-m-d');
        $subscription->updated_at = Carbon::now()->format('Y-m-d H:i:s');
        $subscription->save();

        $content = json_decode($subscription->product_content);

        $user->class_expire = $newEnd->format('Y-m-d') . ' 23:59:59';
        $user->u = 0;
        $user->d = 0;
        $user->transfer_today = 0;
        $user->transfer_enable = Tools::gbToB($content->bandwidth);
        $user->save();
    }

    private function handleInvoiceFailed(Event $event): void
    {
        $invoice = $event->data->object;
        $stripeSubId = $invoice->subscription ?? null;
        $customerId = $invoice->customer ?? null;

        if ($stripeSubId === null || $customerId === null) {
            return;
        }

        $subscription = (new Subscription())->where('stripe_subscription_id', $stripeSubId)->first();

        if ($subscription === null || $subscription->billing_provider !== 'stripe') {
            return;
        }

        $user = (new User())->find($subscription->user_id);

        if ($user === null || $user->stripe_customer_id !== $customerId) {
            return;
        }

        $subscription->status = 'expired';
        $subscription->stripe_status = 'past_due';
        $subscription->auto_renew = 0;
        $subscription->hosted_invoice_url = $invoice->hosted_invoice_url ?? null;
        $subscription->grace_until = isset($invoice->next_payment_attempt)
            ? Carbon::createFromTimestamp((int) $invoice->next_payment_attempt)->format('Y-m-d H:i:s')
            : null;
        $subscription->updated_at = Carbon::now()->format('Y-m-d H:i:s');
        $subscription->save();

        $this->revokeAccessIfNoOtherActiveSubscription($user, (int) $subscription->id);
    }

    private function handleSubscriptionDeleted(Event $event): void
    {
        $stripeSub = $event->data->object;
        $stripeSubId = $stripeSub->id ?? null;
        $customerId = $stripeSub->customer ?? null;

        if ($stripeSubId === null || $customerId === null) {
            return;
        }

        $subscription = (new Subscription())->where('stripe_subscription_id', $stripeSubId)->first();

        if ($subscription === null || $subscription->billing_provider !== 'stripe') {
            return;
        }

        $user = (new User())->find($subscription->user_id);

        if ($user === null || $user->stripe_customer_id !== $customerId) {
            return;
        }

        $subscription->status = 'cancelled';
        $subscription->stripe_status = $stripeSub->status ?? 'canceled';
        $subscription->auto_renew = 0;
        $subscription->updated_at = Carbon::now()->format('Y-m-d H:i:s');
        $subscription->save();

        $this->revokeAccessIfNoOtherActiveSubscription($user, (int) $subscription->id);
    }

    private function revokeAccessIfNoOtherActiveSubscription(User $user, int $subscriptionId): void
    {
        $hasOtherActiveSubscription = (new Subscription())
            ->where('user_id', $user->id)
            ->where('id', '<>', $subscriptionId)
            ->whereIn('status', ['active', 'pending_renewal'])
            ->exists();

        if ($hasOtherActiveSubscription) {
            return;
        }

        $user->u = 0;
        $user->d = 0;
        $user->transfer_today = 0;
        $user->transfer_enable = 0;
        $user->class = 0;
        $user->class_expire = Carbon::now()->format('Y-m-d H:i:s');
        $user->save();
    }
}
