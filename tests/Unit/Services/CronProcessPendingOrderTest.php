<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Services\Cron;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * Cron::processPendingOrder — the 24h auto-cancel sweep for unpaid orders.
 *
 * generateRenewalOrder creates a subscription renewal order (subscription_id
 * set) up to subscription_renewal_days (default 7) BEFORE expiry. That order
 * legitimately sits unpaid for days, so the 24h sweep must EXEMPT it; otherwise
 * it is cancelled before the renewal date and processAutoRenew finds no unpaid
 * invoice at expiry, stranding the sub (auto_renew=1, excluded from
 * expireSubscription, never graced). First-purchase orders (subscription_id
 * null) still time out at 24h.
 * ---------------------------------------------------------------------------
 */

beforeEach(fn () => TestDatabase::init());
afterEach(fn () => TestDatabase::dropTables());

if (! function_exists('makeStalePendingOrder')) {
    /**
     * A pending_payment order created >24h ago with an unpaid invoice.
     * $subscriptionId set => renewal order; null => first-purchase order.
     *
     * @return array{0: Order, 1: Invoice}
     */
    function makeStalePendingOrder(int $userId, ?int $subscriptionId): array
    {
        $stale = time() - 86400 - 100; // comfortably older than the 24h window

        $order = new Order();
        $order->user_id = $userId;
        $order->product_id = 1;
        $order->product_type = 'subscription';
        $order->product_name = 'Pro';
        $order->product_content = json_encode(['name' => 'Pro']);
        $order->subscription_id = $subscriptionId;
        $order->coupon = '';
        $order->price = 30.0;
        $order->status = 'pending_payment';
        $order->create_time = $stale;
        $order->update_time = $stale;
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'product';
        $invoice->user_id = $userId;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => 'Pro', 'price' => 30.0]]);
        $invoice->price = 30.0;
        $invoice->status = 'unpaid';
        $invoice->create_time = $stale;
        $invoice->update_time = $stale;
        $invoice->save();

        return [$order, $invoice];
    }
}

it('does NOT cancel a stale renewal order (subscription_id set) past the 24h window', function () {
    $user = makeUserWithMoney(0.0);
    [$order, $invoice] = makeStalePendingOrder($user->id, subscriptionId: 42);

    ob_start();
    Cron::processPendingOrder();
    ob_get_clean();

    // Survives so processAutoRenew can settle it on the renewal date.
    expect((new Order())->find($order->id)->status)->toBe('pending_payment');
    expect((new Invoice())->find($invoice->id)->status)->toBe('unpaid');
});

it('still cancels a stale non-renewal order (subscription_id null) past the 24h window', function () {
    $user = makeUserWithMoney(0.0);
    [$order, $invoice] = makeStalePendingOrder($user->id, subscriptionId: null);

    ob_start();
    Cron::processPendingOrder();
    ob_get_clean();

    expect((new Order())->find($order->id)->status)->toBe('cancelled');
    expect((new Invoice())->find($invoice->id)->status)->toBe('cancelled');
});
