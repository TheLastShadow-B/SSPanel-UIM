<?php

declare(strict_types=1);

use App\Models\Order;
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
 * 与已合并的 P0.1/P0.2 测试一致，这里直接用 new User() 建行。
 */
function makeUser(int $class): User
{
    $user = new User();
    $user->email = 'sub_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'sub_test';
    $user->passwd = bin2hex(random_bytes(8));
    $user->class = $class;
    $user->transfer_enable = 1099511627776;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;          // 走邮件分支，避免 IM::send 在测试中报 TypeError
    $user->contact_method = 1;
    $user->save();

    return $user;
}

it('exposes SELF_MANAGED as manual+balance', function () {
    expect(SubscriptionService::SELF_MANAGED)->toBe(['manual', 'balance']);
});

it('stamps new subscription rows with billing_provider=manual', function () {
    $user = makeUser(0);
    $content = json_encode([
        'name' => 'Plan', 'bandwidth' => 100, 'class' => 2,
        'node_group' => 1, 'speed_limit' => 0, 'ip_limit' => 0,
        'billing_cycle_selected' => 'month',
    ]);

    $order = new Order();
    $order->user_id = $user->id;
    $order->product_id = 1;
    $order->product_type = 'subscription';
    $order->product_name = 'Plan';
    $order->product_content = $content;
    $order->price = 10;
    $order->status = 'pending_activation';
    $order->billing_provider = 'manual';
    $order->create_time = time();
    $order->update_time = time();
    $order->save();

    ob_start();
    SubscriptionService::processNewSubscriptionActivation();
    ob_get_clean();

    $sub = (new Subscription())->where('user_id', $user->id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->billing_provider)->toBe('manual');
});

it('expireSubscription never touches a stripe-provider subscription', function () {
    $user = makeUser(3);
    $today = Carbon::today()->format('Y-m-d');

    $sub = new Subscription();
    $sub->user_id = $user->id;
    $sub->product_id = 1;
    $sub->product_content = '{}';
    $sub->billing_cycle = 'month';
    $sub->renewal_price = 10;
    $sub->start_date = $today;
    $sub->end_date = $today;
    $sub->reset_day = 1;
    $sub->last_reset_date = $today;
    $sub->status = 'pending_renewal';
    $sub->billing_provider = 'stripe';
    $sub->created_at = '2026-01-01 00:00:00';
    $sub->updated_at = '2026-01-01 00:00:00';
    $sub->save();

    ob_start();
    SubscriptionService::expireSubscription();
    ob_get_clean();

    $sub->refresh();
    expect($sub->status)->toBe('pending_renewal'); // untouched
    $user->refresh();
    expect((int) $user->class)->toBe(3);            // not downgraded
});

it('expireSubscription still expires a manual subscription', function () {
    $user = makeUser(3);
    $today = Carbon::today()->format('Y-m-d');

    $sub = new Subscription();
    $sub->user_id = $user->id;
    $sub->product_id = 1;
    $sub->product_content = '{}';
    $sub->billing_cycle = 'month';
    $sub->renewal_price = 10;
    $sub->start_date = $today;
    $sub->end_date = $today;
    $sub->reset_day = 1;
    $sub->last_reset_date = $today;
    $sub->status = 'pending_renewal';
    $sub->billing_provider = 'manual';
    $sub->created_at = '2026-01-01 00:00:00';
    $sub->updated_at = '2026-01-01 00:00:00';
    $sub->save();

    ob_start();
    SubscriptionService::expireSubscription();
    ob_get_clean();

    $sub->refresh();
    expect($sub->status)->toBe('expired');
    $user->refresh();
    expect((int) $user->class)->toBe(0);
});
