<?php

declare(strict_types=1);

use App\Models\Config;
use App\Models\EmailQueue;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenewHelpers.php';

beforeEach(fn () => TestDatabase::init());
afterEach(fn () => TestDatabase::dropTables());

it('persists grace state first: grace_until = end_date + grace_days, pending_renewal, service kept alive', function () {
    Config::query()->updateOrInsert(
        ['item' => 'stripe_grace_days'],
        ['value' => '3', 'class' => 'billing', 'type' => 'int']
    );

    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(0.0, class: 2, classExpire: $today . ' 23:59:59');
    $sub = makeSub($user, endDate: $today, status: 'active', autoRenew: 1);

    SubscriptionService::enterGrace($sub, $user);

    $expectedGrace = Carbon::parse($today)->addDays(3)->format('Y-m-d H:i:s');

    $freshSub = (new Subscription())->find($sub->id);
    expect($freshSub->status)->toBe('pending_renewal');
    expect($freshSub->grace_until)->toBe($expectedGrace);

    $freshUser = (new User())->find($user->id);
    // class_expire extended to the grace deadline; membership NOT downgraded.
    expect($freshUser->class_expire)->toBe($expectedGrace);
    expect((int) $freshUser->class)->toBe(2);
});

it('defaults to a 3-day grace window when stripe_grace_days is unset', function () {
    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, endDate: $today, status: 'active');

    SubscriptionService::enterGrace($sub, $user);

    $expectedGrace = Carbon::parse($today)->addDays(3)->format('Y-m-d H:i:s');
    expect((new Subscription())->find($sub->id)->grace_until)->toBe($expectedGrace);
});

it('queues a renewal-failed notification and never throws on a missing template', function () {
    Config::query()->updateOrInsert(
        ['item' => 'stripe_grace_days'],
        ['value' => '3', 'class' => 'billing', 'type' => 'int']
    );

    $today = Carbon::today()->format('Y-m-d');
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user, endDate: $today, status: 'active');

    SubscriptionService::enterGrace($sub, $user);

    $queued = (new EmailQueue())->where('to_email', $user->email)->first();
    expect($queued)->not->toBeNull();
    expect($queued->template)->toBe('subscription_renewal_failed.tpl');
});
