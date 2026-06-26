<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenewHelpers.php';

beforeEach(fn () => TestDatabase::init());
afterEach(fn () => TestDatabase::dropTables());

it('advances the period, aligns class_expire, clears grace and preserves quota', function () {
    $today = Carbon::today()->format('Y-m-d');

    $user = makeUserWithMoney(0.0, class: 2, classExpire: $today . ' 23:59:59');
    // Mid-cycle quota that a renewal must PRESERVE: advancing the period only moves
    // dates; the daily resetSubscriptionBandwidth on reset_day owns the quota reset.
    $user->u = 12345;
    $user->d = 67890;
    $user->transfer_today = 999;
    $user->save();
    $originalTransferEnable = (int) $user->transfer_enable;

    $content = ['name' => 'Pro', 'bandwidth' => 200, 'class' => 2, 'node_group' => 1, 'speed_limit' => 0, 'ip_limit' => 0];
    $sub = makeSub(
        $user,
        renewalPrice: 30.0,
        endDate: $today,
        status: 'pending_renewal',
        autoRenew: 1,
        billingProvider: 'manual',
        content: $content,
        graceUntil: Carbon::parse($today)->addDays(3)->format('Y-m-d H:i:s'),
    );

    SubscriptionService::advanceRenewedPeriod($sub, $user);

    $expectedStart = Carbon::parse($today)->addDay();
    $expectedEnd = SubscriptionService::calculateEndDate($expectedStart->copy(), 'month');

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('active');
    expect($freshSub->start_date)->toBe($expectedStart->format('Y-m-d'));
    expect($freshSub->end_date)->toBe($expectedEnd->format('Y-m-d'));
    expect($freshSub->grace_until)->toBeNull();

    $freshUser = (new User())->find($user->id);
    expect($freshUser->class_expire)->toBe($expectedEnd->format('Y-m-d') . ' 23:59:59');
    // Quota untouched — no early bandwidth grant, no early reset.
    expect((int) $freshUser->transfer_enable)->toBe($originalTransferEnable);
    expect((int) $freshUser->u)->toBe(12345);
    expect((int) $freshUser->d)->toBe(67890);
    expect((int) $freshUser->transfer_today)->toBe(999);
});

it('renewing early (end_date in the future) advances dates but does NOT reset quota', function () {
    // generateRenewalOrder creates the renewal order up to subscription_renewal_days
    // (default 7) BEFORE expiry. A user who pays that invoice early must keep their
    // current-cycle quota: dates advance, but transfer_enable/u/d are untouched until
    // the daily resetSubscriptionBandwidth fires on reset_day.
    $future = Carbon::today()->addDays(5)->format('Y-m-d');

    $user = makeUserWithMoney(0.0, class: 2);
    $user->u = 12345;
    $user->d = 67890;
    $user->transfer_today = 999;
    $user->transfer_enable = 555; // distinct from gbToB(bandwidth) to prove no reset
    $user->save();

    $content = ['name' => 'Pro', 'bandwidth' => 200, 'class' => 2, 'node_group' => 1, 'speed_limit' => 0, 'ip_limit' => 0];
    $sub = makeSub($user, renewalPrice: 30.0, endDate: $future, status: 'pending_renewal', content: $content);

    SubscriptionService::advanceRenewedPeriod($sub, $user);

    $expectedStart = Carbon::parse($future)->addDay();
    $expectedEnd = SubscriptionService::calculateEndDate($expectedStart->copy(), 'month');

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('active');
    expect($freshSub->start_date)->toBe($expectedStart->format('Y-m-d'));
    expect($freshSub->end_date)->toBe($expectedEnd->format('Y-m-d'));

    $freshUser = (new User())->find($user->id);
    // class_expire still aligns to the new period end...
    expect($freshUser->class_expire)->toBe($expectedEnd->format('Y-m-d') . ' 23:59:59');
    // ...but quota is NOT reset early.
    expect((int) $freshUser->transfer_enable)->toBe(555);
    expect((int) $freshUser->u)->toBe(12345);
    expect((int) $freshUser->d)->toBe(67890);
    expect((int) $freshUser->transfer_today)->toBe(999);
});

it('rolls a month-end anchor forward without overflow', function () {
    // end_date 2026-01-31 -> newStart 2026-02-01 -> +1mo no-overflow -1d = 2026-02-28.
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, endDate: '2026-01-31', status: 'pending_renewal');

    SubscriptionService::advanceRenewedPeriod($sub, $user);

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->start_date)->toBe('2026-02-01');
    expect($freshSub->end_date)->toBe('2026-02-28');
});
