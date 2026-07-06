<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Tests\TestDatabase;

beforeEach(function () {
    TestDatabase::init();
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
function makeResetUser(int $u): User
{
    $user = new User();
    $user->email = 'reset_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'reset_test';
    $user->passwd = bin2hex(random_bytes(8));
    $user->class = 1;
    $user->u = $u;
    $user->d = 0;
    $user->transfer_enable = 999;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

/**
 * 创建一条今日应当重置的活跃订阅（reset_day=今天, last_reset_date=上月）。
 */
function makeDueSubscription(int $userId, string $provider): Subscription
{
    $today = Carbon::today();

    $sub = new Subscription();
    $sub->user_id = $userId;
    $sub->product_id = 1;
    $sub->product_content = json_encode([
        'bandwidth' => 10, 'class' => 1, 'node_group' => 0, 'speed_limit' => 0, 'ip_limit' => 0,
    ]);
    $sub->billing_cycle = 'month';
    $sub->renewal_price = 10;
    $sub->start_date = $today->copy()->subMonth()->format('Y-m-d');
    $sub->end_date = $today->copy()->addMonth()->format('Y-m-d');
    $sub->reset_day = (int) $today->format('d');                          // due today
    $sub->last_reset_date = $today->copy()->subMonth()->format('Y-m-d');  // last reset prior month
    $sub->status = 'active';
    $sub->billing_provider = $provider;
    $sub->created_at = $today->format('Y-m-d H:i:s');
    $sub->updated_at = $today->format('Y-m-d H:i:s');
    $sub->save();

    return $sub;
}

it('does not reset bandwidth for stripe subscriptions', function () {
    $user = makeResetUser(100);
    makeDueSubscription($user->id, 'stripe');

    ob_start();
    SubscriptionService::resetSubscriptionBandwidth();
    ob_get_clean();

    // resetSubscriptionBandwidth 只处理 SELF_MANAGED(manual/balance)；billing_provider='stripe' 被跳过 → u 不变
    expect((int) (new User())->find($user->id)->u)->toBe(100);
});

it('still resets bandwidth for manual subscriptions', function () {
    $user = makeResetUser(100);
    $sub = makeDueSubscription($user->id, 'manual');

    ob_start();
    SubscriptionService::resetSubscriptionBandwidth();
    ob_get_clean();

    // manual 腿仍由每日 cron 重置 → u 归零，last_reset_date 推进到今天
    expect((int) (new User())->find($user->id)->u)->toBe(0);
    expect((new Subscription())->find($sub->id)->last_reset_date)->toBe(Carbon::today()->format('Y-m-d'));
});

it('still resets bandwidth for balance subscriptions', function () {
    $user = makeResetUser(100);
    makeDueSubscription($user->id, 'balance');

    ob_start();
    SubscriptionService::resetSubscriptionBandwidth();
    ob_get_clean();

    expect((int) (new User())->find($user->id)->u)->toBe(0);
});
