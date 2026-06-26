<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Utils\Tools;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Stripe\Event;
use function in_array;
use function json_decode;
use function time;

/**
 * Top-level Stripe webhook dispatcher.
 *
 * Responsibilities:
 *  - Idempotency: every event is recorded once on `stripe_event.event_id`
 *    (UNIQUE); a redelivery of the same event is a no-op.
 *  - Routing: dispatch by `$event->type` to a per-type handler.
 *  - Unknown / not-yet-implemented types are safe no-ops.
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
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
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
            case 'checkout.session.async_payment_failed':
            case 'setup_intent.succeeded':
            default:
                // Handled in later tasks / no-op for unknown types.
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
     * checkout.session.completed (subscription mode): create the local
     * Subscription row + run the FIRST-PERIOD membership grant.
     *
     * Idempotent on the UNIQUE stripe_subscription_id: this event can be
     * redelivered, AND invoice.paid(subscription_create) also fires for the
     * first period (P1.6 no-ops on dates), so the first-period grant must
     * happen exactly once — here.
     *
     * S5: the session is bound to a local user via the server-stored
     * stripe_customer_id (never a client-supplied id). The purchase metadata
     * ({sspanel_user_id, product_id, billing_cycle, order_id, invoice_id}) was
     * set as subscription_data.metadata in P1.3, so it lives on the Stripe
     * SUBSCRIPTION object — retrieved here via the injectable StripeService so
     * it stays stubbable in tests.
     */
    private function handleCheckoutCompleted(Event $event): void
    {
        $session = $event->data->object;

        if (($session->mode ?? null) !== 'subscription') {
            return;
        }

        if ($event->type === 'checkout.session.completed'
            && ! in_array($session->payment_status ?? null, ['paid', 'no_payment_required'], true)
        ) {
            return;
        }

        $stripeSubId = $session->subscription ?? null;
        $customerId = $session->customer ?? null;

        if ($stripeSubId === null || $customerId === null) {
            return;
        }

        // S5: bind the event to a local user via the server-stored customer id.
        $user = (new User())->where('stripe_customer_id', $customerId)->first();

        if ($user === null) {
            return;
        }

        // Idempotent on the UNIQUE stripe_subscription_id: a replay (or the
        // first invoice.paid) must NOT create a 2nd row or re-grant membership.
        if ((new Subscription())->where('stripe_subscription_id', $stripeSubId)->exists()) {
            return;
        }

        // Metadata + the locked recurring price live on the Stripe subscription.
        $stripeSub = StripeService::getInstance()->client()->subscriptions->retrieve($stripeSubId);
        $metadata = $stripeSub->metadata ?? null;

        $billingCycle = $metadata->billing_cycle ?? 'month';
        $orderId = $metadata->order_id ?? null;

        $order = $orderId !== null ? (new Order())->find((int) $orderId) : null;

        if ($order === null) {
            return;
        }

        $content = json_decode($order->product_content);
        $today = Carbon::today();
        $endDate = SubscriptionService::calculateEndDate($today, $billingCycle);

        // Locked price from the resolved Stripe Price (P1.3), if present.
        $priceItem = $stripeSub->items->data[0]->price ?? null;
        $stripeAmount = $priceItem->unit_amount ?? null;
        $stripeCurrency = $priceItem->currency ?? null;

        $now = Carbon::now()->format('Y-m-d H:i:s');

        try {
            $subscription = new Subscription();
            $subscription->user_id = $user->id;
            $subscription->product_id = $order->product_id;
            $subscription->product_content = $order->product_content;
            $subscription->billing_cycle = $billingCycle;
            $subscription->renewal_price = $order->price;
            $subscription->start_date = $today->format('Y-m-d');
            $subscription->end_date = $endDate->format('Y-m-d');
            $subscription->reset_day = (int) $today->format('d');
            $subscription->last_reset_date = $today->format('Y-m-d');
            $subscription->status = 'active';
            $subscription->billing_provider = 'stripe';
            $subscription->auto_renew = 1;
            $subscription->stripe_subscription_id = $stripeSubId;
            $subscription->stripe_status = 'active';
            $subscription->stripe_amount = $stripeAmount;
            $subscription->stripe_currency = $stripeCurrency;
            $subscription->created_at = $now;
            $subscription->updated_at = $now;
            $subscription->save();
        } catch (QueryException $e) {
            // A concurrent delivery already created this subscription. The
            // first-period grant belongs to that winner — no-op here.
            if ($this->isUniqueViolation($e)) {
                return;
            }

            throw $e;
        }

        // FIRST-PERIOD membership grant (shared helper — never re-inlined).
        SubscriptionService::grantMembershipFromContent(
            $user,
            $content,
            $endDate->format('Y-m-d') . ' 23:59:59'
        );

        // Activate + link the pending order back to the new subscription.
        $order->status = 'activated';
        $order->subscription_id = $subscription->id;
        $order->update_time = time();
        $order->save();

        $invoiceId = $metadata->invoice_id ?? null;

        if ($invoiceId !== null) {
            $invoice = (new Invoice())->find((int) $invoiceId);

            if ($invoice !== null && (int) $invoice->order_id === (int) $order->id) {
                $invoice->status = 'paid_gateway';
                $invoice->pay_time = time();
                $invoice->update_time = time();
                $invoice->save();
            }
        }
    }

    /**
     * invoice.paid (subscription mode): advance the local period on a RENEWAL.
     *
     *  - billing_reason='subscription_create' (the FIRST invoice): NO date
     *    change. The first-period membership grant already happened in P1.5's
     *    handleCheckoutCompleted; this invoice fires for the same period, so it
     *    must NOT advance end_date / class_expire (idempotency of period 1).
     *  - billing_reason='subscription_cycle' (a RENEWAL): advance the local
     *    Subscription end_date + the user's class_expire by one billing cycle
     *    and reset the period's bandwidth (the Stripe-leg bandwidth reset lives
     *    HERE per P0.10 / §12.4). Date math mirrors
     *    SubscriptionService::processRenewalActivation; the bandwidth reset
     *    mirrors resetSubscriptionBandwidth.
     *
     * S5: bind via stripe_subscription_id, then assert the subscription's owner
     * is the customer on the invoice before acting (same pattern as P1.5).
     *
     * Idempotency: a re-delivery of the SAME event id is already a no-op (the
     * StripeEvent UNIQUE guard in handle()). Stripe can also emit the SAME
     * logical invoice under a DIFFERENT event id (e.g. invoice.paid vs
     * invoice.payment_succeeded redelivery), which the StripeEvent dedup would
     * NOT catch. We therefore ALSO guard on the Stripe INVOICE id: the renewal
     * stores invoice->id in last_paid_stripe_invoice_id, and a second delivery
     * of that same invoice id is a no-op — so a period advances exactly once.
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

        if ($subscription === null) {
            return;
        }

        // Only act on Stripe-managed subscriptions; never touch manual/balance.
        if ($subscription->billing_provider !== 'stripe') {
            return;
        }

        // S5: assert the subscription belongs to this customer (security).
        $user = (new User())->find($subscription->user_id);

        if ($user === null || $user->stripe_customer_id !== $customerId) {
            return;
        }

        // First invoice of a brand-new subscription: dates already set by
        // checkout.session.completed (P1.5). No-op on dates here.
        if ($reason === 'subscription_create') {
            $this->markInitialInvoicePaidForSubscription($subscription);

            return;
        }

        // Only renewals advance the period.
        if ($reason !== 'subscription_cycle') {
            return;
        }

        // Per-invoice idempotency guard for the cross-event-id case: Stripe can
        // deliver the SAME logical invoice under a DIFFERENT event id (so the
        // StripeEvent dedup in handle() would not catch it). Keyed on the Stripe
        // invoice id we stored on the last successful renewal — a redelivery of
        // that same invoice is a no-op, so the period advances exactly once.
        $invoiceId = $invoice->id ?? null;

        if ($invoiceId !== null && $subscription->last_paid_stripe_invoice_id === $invoiceId) {
            return;
        }

        // Renewal date math — mirrors SubscriptionService::processRenewalActivation.
        $newStart = Carbon::parse($subscription->end_date)->addDay();
        $newEnd = SubscriptionService::calculateEndDate($newStart, $subscription->billing_cycle);

        $now = Carbon::now()->format('Y-m-d H:i:s');

        $subscription->start_date = $newStart->format('Y-m-d');
        $subscription->end_date = $newEnd->format('Y-m-d');
        $subscription->status = 'active';
        $subscription->stripe_status = 'active';
        // Stamp the processed invoice id IN THE SAME save — this is the
        // idempotency marker the guard above reads on redelivery.
        $subscription->last_paid_stripe_invoice_id = $invoiceId;
        // Mark this period's reset locally. resetSubscriptionBandwidth skips
        // stripe subs (P0.10), so this never conflicts with the daily cron; it
        // keeps last_reset_date coherent for the Stripe leg's own period.
        $subscription->last_reset_date = Carbon::today()->format('Y-m-d');
        $subscription->updated_at = $now;
        $subscription->save();

        // class_expire advance + Stripe-leg bandwidth reset for the new period
        // — mirrors SubscriptionService::resetSubscriptionBandwidth.
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

    private function markInitialInvoicePaidForSubscription(Subscription $subscription): void
    {
        $order = (new Order())
            ->where('subscription_id', $subscription->id)
            ->where('billing_provider', 'stripe')
            ->first();

        if ($order === null) {
            return;
        }

        $invoice = (new Invoice())
            ->where('order_id', $order->id)
            ->where('billing_provider', 'stripe')
            ->first();

        if ($invoice === null || $invoice->status === 'paid_gateway') {
            return;
        }

        $invoice->status = 'paid_gateway';
        $invoice->pay_time = time();
        $invoice->update_time = time();
        $invoice->save();
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
