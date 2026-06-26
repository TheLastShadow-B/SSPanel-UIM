<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Services\Cron;
use Carbon\Carbon;
use Tests\TestDatabase;

beforeEach(function () {
    TestDatabase::init();
    $_ENV['appName'] = 'Test';
    $_ENV['class_expire_reset_traffic'] = -1;
});

afterEach(function () {
    TestDatabase::dropTables();
});

/**
 * 直接创建用户行（仅使用测试 schema 中存在的列）。
 * 注意：tests/Factories/UserFactory 依赖 fakerphp/faker（未安装）且写入了
 * 测试表中不存在的列（username/locale/uuid 等），无法在本环境运行；
 * 与已合并的 P0.1/P0.2/P0.3 测试一致，这里直接用 new User() 建行。
 */
function makeExpiredPaidUser(int $class): User
{
    $user = new User();
    $user->email = 'cron_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'cron_test';
    $user->passwd = bin2hex(random_bytes(8));
    $user->class = $class;
    $user->class_expire = date('Y-m-d H:i:s', strtotime('-2 day'));
    $user->transfer_enable = 1099511627776;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;          // 走邮件分支，避免 IM::send 在测试中报 TypeError
    $user->contact_method = 1;
    $user->save();

    return $user;
}

function makeStripeSub(int $userId, string $status, string $stripeStatus, ?string $stripeSubId): Subscription
{
    $today = Carbon::today()->format('Y-m-d');
    $s = new Subscription();
    $s->user_id = $userId;
    $s->product_id = 1;
    $s->product_content = '{}';
    $s->billing_cycle = 'month';
    $s->renewal_price = 10;
    $s->start_date = $today;
    $s->end_date = $today;
    $s->reset_day = 1;
    $s->last_reset_date = $today;
    $s->status = $status;
    $s->billing_provider = 'stripe';
    $s->stripe_subscription_id = $stripeSubId;
    $s->stripe_status = $stripeStatus;
    $s->created_at = '2026-01-01 00:00:00';
    $s->updated_at = '2026-01-01 00:00:00';
    $s->save();

    return $s;
}

it('does not downgrade a past_due stripe user with stale class_expire', function () {
    $user = makeExpiredPaidUser(3);
    // local status expired, but stripe still owns the truth
    makeStripeSub($user->id, 'expired', 'past_due', 'sub_123');

    ob_start();
    Cron::expirePaidUserAccount();
    ob_get_clean();

    $user->refresh();
    expect((int) $user->class)->toBe(3);
});

it('does not downgrade an active stripe user with stale class_expire', function () {
    $user = makeExpiredPaidUser(3);
    makeStripeSub($user->id, 'expired', 'active', 'sub_active');

    ob_start();
    Cron::expirePaidUserAccount();
    ob_get_clean();

    $user->refresh();
    expect((int) $user->class)->toBe(3);
});

it('still downgrades a user with no protecting subscription', function () {
    $user = makeExpiredPaidUser(3);

    ob_start();
    Cron::expirePaidUserAccount();
    ob_get_clean();

    $user->refresh();
    expect((int) $user->class)->toBe(0);
});

it('still downgrades a canceled stripe user (no protection)', function () {
    $user = makeExpiredPaidUser(3);
    makeStripeSub($user->id, 'expired', 'canceled', 'sub_999');

    ob_start();
    Cron::expirePaidUserAccount();
    ob_get_clean();

    $user->refresh();
    expect((int) $user->class)->toBe(0);
});

it('still downgrades when stripe_subscription_id is null even if stripe_status looks live', function () {
    $user = makeExpiredPaidUser(3);
    // defensive: a live-looking stripe_status with no actual stripe sub id must not protect
    makeStripeSub($user->id, 'expired', 'active', null);

    ob_start();
    Cron::expirePaidUserAccount();
    ob_get_clean();

    $user->refresh();
    expect((int) $user->class)->toBe(0);
});
