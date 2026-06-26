<?php

declare(strict_types=1);

use App\Controllers\Admin\Setting\BillingController;

it('declares the full stripe webhook event set in source', function () {
    $src = file_get_contents(BASE_PATH . '/src/Controllers/Admin/Setting/BillingController.php');
    foreach ([
        'checkout.session.completed',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'setup_intent.succeeded',
    ] as $event) {
        expect($src)->toContain($event);
    }
});
