<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Utils\Tools;
use Carbon\Carbon;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenewHelpers.php';

beforeEach(fn () => TestDatabase::init());
afterEach(fn () => TestDatabase::dropTables());

it('advances the period, aligns class_expire, clears grace and resets bandwidth', function () {
    $today = Carbon::today()->format('Y-m-d');

    $user = makeUserWithMoney(0.0, class: 2, classExpire: $today . ' 23:59:59');
    // Simulate consumed traffic that a renewal must reset.
    $user->u = 12345;
    $user->d = 67890;
    $user->transfer_today = 999;
    $user->save();

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
    expect((int) $freshUser->transfer_enable)->toBe(Tools::gbToB(200));
    expect((int) $freshUser->u)->toBe(0);
    expect((int) $freshUser->d)->toBe(0);
    expect((int) $freshUser->transfer_today)->toBe(0);
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
