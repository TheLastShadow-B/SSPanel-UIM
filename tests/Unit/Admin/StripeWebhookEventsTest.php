<?php

declare(strict_types=1);

use App\Controllers\Admin\Setting\BillingController;

it('registers only the live stripe webhook events and no native-subscription ones', function () {
    $src = file_get_contents(BASE_PATH . '/src/Controllers/Admin/Setting/BillingController.php');

    // Live events the gateway / webhook handler actually consume: one-time and
    // first-purchase settlement + self-managed renewal off-session charges
    // (payment_intent.succeeded) and renewal card-binding (setup_intent.succeeded).
    foreach ([
        'payment_intent.succeeded',
        'setup_intent.succeeded',
    ] as $event) {
        expect($src)->toContain($event);
    }

    // Native Stripe-subscription events were removed with that flow and must no
    // longer be registered.
    foreach ([
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ] as $event) {
        expect($src)->not->toContain($event);
    }
});
