<?php

declare(strict_types=1);

it('wires auto-renew and termination into the daily cron in the correct order', function () {
    $src = file_get_contents(BASE_PATH . '/src/Command/Cron.php');

    // Both new renewal-engine entrypoints must be scheduled by the daily cron.
    expect($src)->toContain('SubscriptionService::processAutoRenew();');
    expect($src)->toContain('SubscriptionService::terminateLapsed();');

    // generateRenewalOrder must create the unpaid renewal invoice before
    // processAutoRenew tries to pay it.
    expect(strpos($src, 'SubscriptionService::generateRenewalOrder();'))
        ->toBeLessThan(strpos($src, 'SubscriptionService::processAutoRenew();'));

    // terminateLapsed must run after expireSubscription.
    expect(strpos($src, 'SubscriptionService::expireSubscription();'))
        ->toBeLessThan(strpos($src, 'SubscriptionService::terminateLapsed();'));
});
