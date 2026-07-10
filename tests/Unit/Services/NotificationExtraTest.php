<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\EmailQueue;
use App\Services\Notification;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

it('merges extra vars into the queued mail payload without clobbering base keys', function () {
    $user = makeUserWithMoney(0.0);
    $user->contact_method = 1;
    $user->save();

    Notification::notifyUser($user, '标题', '正文', 'subscription_renewal.tpl', [
        'plan_name' => 'Pro',
        'invoice_url' => 'https://x/user/invoice/9/view',
        'title' => '不应覆盖',
    ]);

    $row = (new EmailQueue())->where('template', 'subscription_renewal.tpl')->first();
    expect($row)->not->toBeNull();
    $payload = json_decode($row->array, true);
    expect($payload['plan_name'])->toBe('Pro');
    expect($payload['invoice_url'])->toBe('https://x/user/invoice/9/view');
    expect($payload['title'])->toBe('标题');
    expect($payload['text'])->toBe('正文');
});

it('queues subscription_renewal.tpl with plan_name/amount/invoice_url when generateRenewalOrder fires', function () {
    // generateRenewalOrder selects: status='active', end_date == today + subscription_renewal_days,
    // billing_provider in SELF_MANAGED (manual/balance). makeSub already defaults to
    // billing_provider='manual'; only status/end_date need to match the selection window.
    Config::query()->updateOrInsert(
        ['item' => 'subscription_renewal_days'],
        ['value' => '7', 'class' => 'cron', 'type' => 'int']
    );

    $user = makeUserWithMoney(0.0);
    $endDate = Carbon::today()->addDays(7)->format('Y-m-d');
    makeSub($user, renewalPrice: 30.0, endDate: $endDate, status: 'active');

    ob_start();
    SubscriptionService::generateRenewalOrder();
    ob_get_clean();

    $row = (new EmailQueue())->where('template', 'subscription_renewal.tpl')->first();
    expect($row)->not->toBeNull();
    $payload = json_decode($row->array, true);
    expect($payload['plan_name'])->toBe('Pro');
    expect((float) $payload['amount'])->toBe(30.0);
    expect($payload['invoice_url'])->toEndWith('/view');
});
