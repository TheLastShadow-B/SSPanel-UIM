# 支付即时激活 + 账单页轮询 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 用户付款(网关回调 / 余额 / 免费单)后订单立即激活生效,不再等 5 分钟 cron;账单页自动轮询刷新,消除感知延迟。

**Architecture:** 新建幂等激活服务 `App\Services\OrderActivation`(订单行锁 + 状态复查事务),把 cron 中 topup / 新订阅 / 续费三类订单的"单笔激活"逻辑收拢进来;cron 循环改为委托该服务(兜底不变),支付网关回调 `postPayment`、余额支付两条路径付款成功后同步调用它。前端在账单详情页对未支付账单每 3 秒轮询新增的 JSON 状态端点,支付落账后自动整页刷新。

**Tech Stack:** PHP 8 (Slim 4 + Eloquent + Smarty),Pest 3 测试(DB-backed 测试跑真实 MariaDB),前端原生 JS(页面已有 htmx,轮询用 fetch 即可)。

## Global Constraints

- 所有 PHP 文件 `declare(strict_types=1)`,服务类 `final`,遵循所在文件现有代码风格。
- **Web 请求路径绝不 `echo`**:`OrderActivation` 必须完全静默;进度输出只留在 cron 循环里。
- **cron 仍是完整兜底**:同步激活任何失败/被门槛拦下,订单必须留在 `pending_payment`/`pending_activation`,cron 能照常处理。事务回滚天然满足;不允许把订单置成 cron 认不得的状态。
- 激活范围只覆盖本站在售自管类型:`topup`、`subscription` 新购(subscription_id null)、`subscription` 续费(subscription_id 非空);`tabp`/`bandwidth`/`time` 遗留商店类型返回 false 留给 cron 原有循环,**不改动**它们的 cron 逻辑。
- 锁顺序统一:先锁 `order` 行,再锁 `user` 行,避免死锁。
- DB-backed 测试模板:`beforeEach` 里 `require BASE_PATH . '/config/.config.test.php';` + `TestDatabase::init();`,`afterEach` 里 `TestDatabase::dropTables();`(镜像 `tests/Unit/Controllers/SubscriptionPurchaseTest.php`)。测试助手一律 `function_exists` 守卫。cron 输出用 `ob_start()/ob_get_clean()` 缓冲。
- 测试命令:`./vendor/bin/pest <file>`(本机已有 `config/.config.test.php`,勿提交该文件)。
- 不新增 composer 依赖。
- 提交信息结尾:`Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`。

## 背景速览(实现者必读)

现状链路:下单(`order.status='pending_payment'`,`invoice.status='unpaid'`)→ 支付成功时**只把账单标为已付**(网关回调 `src/Services/Gateway/Base.php::postPayment` 置 `paid_gateway`;余额路径置 `paid_balance`)→ 每 5 分钟 `php xcat Cron`:`Cron::processPendingOrder()`(src/Services/Cron.php:417)把已付订单 `pending_payment → pending_activation`,随后 `SubscriptionService::processNewSubscriptionActivation()`(src/Services/SubscriptionService.php:82)/`processRenewalActivation()`(:216)/`Cron::processTopupOrderActivation()`(src/Services/Cron.php:386)真正发权益。本计划把"单笔激活"抽为服务供支付路径同步调用,cron 循环委托同一份代码。

已有共享助手(直接复用,不要复制实现):

- `SubscriptionService::calculateEndDate(Carbon $startDate, string $billingCycle): Carbon`(public)
- `SubscriptionService::grantMembershipFromContent(User $user, object $content, string $classExpire): void`(public)
- `SubscriptionService::advanceRenewedPeriod(Subscription $sub, User $user): void`(public)
- `SubscriptionService::SELF_MANAGED = ['manual', 'balance']`(public const)
- 测试工厂:`tests/Unit/Services/AutoRenew/AutoRenewHelpers.php` 的 `makeUserWithMoney()`、`makeSub()`、`ensureUserMoneyLogTable()`、`dropUserMoneyLogTable()`(`require_once` 引入)

---

### Task 1: OrderActivation 服务(调度器 + topup)+ cron 充值循环委托

**Files:**
- Create: `src/Services/OrderActivation.php`
- Modify: `src/Services/Cron.php:383-415`(processTopupOrderActivation)
- Test: `tests/Unit/Services/OrderActivationTopupTest.php`

**Interfaces:**
- Consumes: `App\Services\DB::transaction`、`App\Models\{Order,Invoice,User,UserMoneyLog}`
- Produces: `OrderActivation::tryActivate(int $orderId): bool` — 后续所有 Task 都调它。true = 本次调用完成激活;false = 无需/无法激活(未支付、已激活、类型不支持、被业务门槛拦下)。

- [ ] **Step 1: 写失败测试**

创建 `tests/Unit/Services/OrderActivationTopupTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Cron;
use App\Services\OrderActivation;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * OrderActivation::tryActivate — 支付即时激活的幂等入口(topup 部分)。
 *
 * 语义:订单行锁 + 状态复查;pending_payment 且账单已结清则原地推进到
 * pending_activation(镜像 Cron::processPendingOrder)再激活;重复调用只成功一次;
 * 不支持的遗留类型返回 false 留给 cron。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
    ensureUserMoneyLogTable();
});

afterEach(function () {
    dropUserMoneyLogTable();
    TestDatabase::dropTables();
});

if (! function_exists('activationMakeTopupOrder')) {
    /**
     * @return array{0: Order, 1: Invoice}
     */
    function activationMakeTopupOrder(
        int $userId,
        float $amount,
        string $orderStatus,
        string $invoiceStatus
    ): array {
        $order = new Order();
        $order->user_id = $userId;
        $order->product_id = 0;
        $order->product_type = 'topup';
        $order->product_name = '余额充值';
        $order->product_content = json_encode(['amount' => $amount]);
        $order->subscription_id = null;
        $order->coupon = '';
        $order->price = $amount;
        $order->status = $orderStatus;
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'topup';
        $invoice->user_id = $userId;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => '余额充值', 'price' => $amount]]);
        $invoice->price = $amount;
        $invoice->status = $invoiceStatus;
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return [$order, $invoice];
    }
}

it('activates a paid pending_activation topup order and credits money once', function () {
    $user = makeUserWithMoney(5.0);
    [$order] = activationMakeTopupOrder($user->id, 30.0, 'pending_activation', 'paid_gateway');

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();

    expect((float) (new User())->find($user->id)->money)->toBe(35.0);
    expect((new Order())->find($order->id)->status)->toBe('activated');
});

it('flips a pending_payment order with a settled invoice, then activates (webhook beats cron)', function () {
    $user = makeUserWithMoney(0.0);
    [$order] = activationMakeTopupOrder($user->id, 10.0, 'pending_payment', 'paid_gateway');

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();

    expect((float) (new User())->find($user->id)->money)->toBe(10.0);
    expect((new Order())->find($order->id)->status)->toBe('activated');
});

it('refuses when the invoice is still unpaid and changes nothing', function () {
    $user = makeUserWithMoney(0.0);
    [$order] = activationMakeTopupOrder($user->id, 10.0, 'pending_payment', 'unpaid');

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();

    expect((float) (new User())->find($user->id)->money)->toBe(0.0);
    expect((new Order())->find($order->id)->status)->toBe('pending_payment');
});

it('is idempotent: the second call is a no-op', function () {
    $user = makeUserWithMoney(0.0);
    [$order] = activationMakeTopupOrder($user->id, 10.0, 'pending_activation', 'paid_balance');

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();
    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();

    expect((float) (new User())->find($user->id)->money)->toBe(10.0);
});

it('returns false for a missing order id', function () {
    expect(OrderActivation::tryActivate(424242))->toBeFalse();
});

it('leaves legacy product types to the cron loops', function () {
    $user = makeUserWithMoney(0.0);
    [$order] = activationMakeTopupOrder($user->id, 10.0, 'pending_activation', 'paid_gateway');
    $order->product_type = 'bandwidth';
    $order->save();

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new Order())->find($order->id)->status)->toBe('pending_activation');
});

it('keeps Cron::processTopupOrderActivation working through delegation', function () {
    $user = makeUserWithMoney(0.0);
    [$order] = activationMakeTopupOrder($user->id, 20.0, 'pending_activation', 'paid_gateway');

    ob_start();
    Cron::processTopupOrderActivation();
    ob_get_clean();

    expect((float) (new User())->find($user->id)->money)->toBe(20.0);
    expect((new Order())->find($order->id)->status)->toBe('activated');
});
```

- [ ] **Step 2: 跑测试确认失败**

Run: `./vendor/bin/pest tests/Unit/Services/OrderActivationTopupTest.php`
Expected: FAIL,`Class "App\Services\OrderActivation" not found`

- [ ] **Step 3: 实现服务(最小可过)**

创建 `src/Services/OrderActivation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\UserMoneyLog;
use function in_array;
use function json_decode;
use function time;

/**
 * 支付完成后即时激活订单(幂等)。
 *
 * 由支付网关回调(Gateway/Base::postPayment)、余额支付路径与 5 分钟 cron 兜底共用:
 * 激活在「订单行锁 + 状态复查」事务里进行,重复/并发调用只成功一次。仅处理自管
 * 订单类型(topup / subscription 新购 / subscription 续费);tabp、bandwidth、time
 * 遗留商店类型返回 false,留给 cron 原有循环。必须保持静默(web 请求路径公用)。
 */
final class OrderActivation
{
    /**
     * 尝试激活一个订单。true = 本次调用完成激活;false = 无需/无法激活
     * (未支付、已激活、类型不支持或被业务门槛拦下,留给 cron 兜底)。
     */
    public static function tryActivate(int $orderId): bool
    {
        return (bool) DB::transaction(static function () use ($orderId): bool {
            $order = (new Order())->where('id', $orderId)->lockForUpdate()->first();

            if ($order === null) {
                return false;
            }

            // 支付回调先于 cron 到达时订单还停在 pending_payment:复核账单已结清后
            // 原地推进到 pending_activation(镜像 Cron::processPendingOrder 的判定)。
            if ($order->status === 'pending_payment') {
                $invoice = (new Invoice())->where('order_id', $order->id)->first();

                if ($invoice === null
                    || ! in_array($invoice->status, ['paid_gateway', 'paid_balance', 'paid_admin'], true)
                ) {
                    return false;
                }

                $order->status = 'pending_activation';
                $order->update_time = time();
                $order->save();
            }

            if ($order->status !== 'pending_activation') {
                return false;
            }

            return match (true) {
                $order->product_type === 'topup' => self::activateTopup($order),
                default => false,
            };
        });
    }

    private static function activateTopup(Order $order): bool
    {
        $user = (new User())->where('id', $order->user_id)->lockForUpdate()->first();

        if ($user === null) {
            return false;
        }

        $content = json_decode($order->product_content);
        $money_before = (float) $user->money;
        $user->money = $money_before + (float) $content->amount;
        $user->save();

        $order->status = 'activated';
        $order->update_time = time();
        $order->save();

        (new UserMoneyLog())->add(
            (int) $user->id,
            $money_before,
            (float) $user->money,
            (float) $content->amount,
            "充值订单 #{$order->id}"
        );

        return true;
    }
}
```

修改 `src/Services/Cron.php` 的 `processTopupOrderActivation()`(原 386-415 行),整个函数体替换为委托:

```php
    /**
     * @throws Exception
     */
    public static function processTopupOrderActivation(): void
    {
        // 获取等待激活的充值订单,允许同时处理多个充值订单
        $orders = (new Order())->where('status', 'pending_activation')
            ->where('product_type', 'topup')
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            if (OrderActivation::tryActivate((int) $order->id)) {
                echo "充值订单 #{$order->id} 已激活。\n";
            }
        }

        echo Tools::toDateTime(time()) . ' 充值订单激活处理完成' . PHP_EOL;
    }
```

同时在 `src/Services/Cron.php` 顶部 `use` 区加入 `use App\Services\OrderActivation;`(若同 namespace 可省略——Cron 在 `App\Services` 下,**省略 use,直接引用 `OrderActivation`**)。确认删除该函数原先直接操作 `$user->money`/`UserMoneyLog` 的旧实现,不留两份逻辑。检查文件内 `UserMoneyLog` 的 use 是否仍被其他函数引用,若无则移除。

- [ ] **Step 4: 跑测试确认通过**

Run: `./vendor/bin/pest tests/Unit/Services/OrderActivationTopupTest.php`
Expected: PASS(7 tests)

- [ ] **Step 5: 回归相邻测试**

Run: `./vendor/bin/pest tests/Unit/Services/CronProcessPendingOrderTest.php tests/Unit/Services/AutoRenew`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Services/OrderActivation.php src/Services/Cron.php tests/Unit/Services/OrderActivationTopupTest.php
git commit -m "feat(activation): idempotent OrderActivation service; topup activates on demand

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: 新订阅激活收拢 + cron 新订阅循环委托

**Files:**
- Modify: `src/Services/OrderActivation.php`(加 match 分支 + activateNewSubscription)
- Modify: `src/Services/SubscriptionService.php:82-147`(processNewSubscriptionActivation)
- Test: `tests/Unit/Services/OrderActivationSubscriptionTest.php`

**Interfaces:**
- Consumes: `SubscriptionService::{SELF_MANAGED, calculateEndDate, grantMembershipFromContent}`、Task 1 的 `tryActivate`
- Produces: `tryActivate` 现在能激活 `product_type='subscription'` 且 `subscription_id IS NULL` 的订单(创建 Subscription + 发放会员权益)

- [ ] **Step 1: 写失败测试**

创建 `tests/Unit/Services/OrderActivationSubscriptionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderActivation;
use App\Services\SubscriptionService;
use App\Utils\Tools;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * OrderActivation — subscription 新购(subscription_id null)。
 *
 * 逻辑与 SubscriptionService::processNewSubscriptionActivation 单笔体一致:
 * SELF_MANAGED 才处理;已有 active/pending_renewal 订阅则跳过(留给 cron 在旧订阅
 * 结束后处理);激活 = 建 Subscription(auto_renew=1) + grantMembershipFromContent
 * + 订单置 activated。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

if (! function_exists('activationMakeSubOrder')) {
    function activationMakeSubOrder(
        int $userId,
        string $orderStatus = 'pending_activation',
        string $invoiceStatus = 'paid_balance',
        string $billingProvider = 'manual'
    ): Order {
        $order = new Order();
        $order->user_id = $userId;
        $order->product_id = 1;
        $order->product_type = 'subscription';
        $order->product_name = 'Pro';
        $order->product_content = json_encode([
            'class' => 1,
            'bandwidth' => 100,
            'node_group' => 0,
            'speed_limit' => 0,
            'ip_limit' => 0,
            'billing_cycle' => ['month' => true],
            'billing_cycle_selected' => 'month',
            'name' => 'Pro',
        ]);
        $order->subscription_id = null;
        $order->coupon = '';
        $order->price = 10.0;
        $order->status = $orderStatus;
        $order->billing_provider = $billingProvider;
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'product';
        $invoice->user_id = $userId;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => 'Pro (月付)', 'price' => 10.0]]);
        $invoice->price = 10.0;
        $invoice->status = $invoiceStatus;
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return $order;
    }
}

it('creates the subscription and grants membership instantly', function () {
    $user = makeUserWithMoney(0.0, class: 0, classExpire: date('Y-m-d H:i:s', strtotime('-1 day')));
    $order = activationMakeSubOrder($user->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();

    $sub = (new Subscription())->where('user_id', $user->id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->status)->toBe('active');
    expect((int) $sub->auto_renew)->toBe(1);
    expect($sub->billing_provider)->toBe('manual');
    expect($sub->billing_cycle)->toBe('month');

    $fresh = (new User())->find($user->id);
    expect((int) $fresh->class)->toBe(1);
    expect((int) $fresh->transfer_enable)->toBe(Tools::gbToB(100));

    expect((new Order())->find($order->id)->status)->toBe('activated');
});

it('skips when the user already has an active subscription (left for cron)', function () {
    $user = makeUserWithMoney(0.0);
    makeSub($user, status: 'active');
    $order = activationMakeSubOrder($user->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new Order())->find($order->id)->status)->toBe('pending_activation');
    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(1);
});

it('skips non-self-managed billing providers', function () {
    $user = makeUserWithMoney(0.0);
    $order = activationMakeSubOrder($user->id, billingProvider: 'stripe');

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(0);
});

it('is idempotent: one subscription row after two calls', function () {
    $user = makeUserWithMoney(0.0, class: 0, classExpire: date('Y-m-d H:i:s', strtotime('-1 day')));
    $order = activationMakeSubOrder($user->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();
    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();

    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(1);
});

it('keeps the cron loop working through delegation', function () {
    $user = makeUserWithMoney(0.0, class: 0, classExpire: date('Y-m-d H:i:s', strtotime('-1 day')));
    $order = activationMakeSubOrder($user->id);

    ob_start();
    SubscriptionService::processNewSubscriptionActivation();
    ob_get_clean();

    expect((new Order())->find($order->id)->status)->toBe('activated');
    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(1);
});
```

注意:`makeSub(User $user, ...)` 第一个参数是 **User 对象**(不是 id),命名参数含 `status:`/`endDate:`/`billingProvider:`,默认月付、今日到期、`pending_renewal`(签名见 `AutoRenewHelpers.php:97`)。

- [ ] **Step 2: 跑测试确认失败**

Run: `./vendor/bin/pest tests/Unit/Services/OrderActivationSubscriptionTest.php`
Expected: 前 4 个 FAIL(tryActivate 对 subscription 返回 false);cron 委托用例此时 PASS(旧实现仍在)——没问题,继续。

- [ ] **Step 3: 实现**

`src/Services/OrderActivation.php`:

1. `use` 区加入:

```php
use App\Models\Subscription;
use Carbon\Carbon;
```

2. match 表达式改为:

```php
            return match (true) {
                $order->product_type === 'topup' => self::activateTopup($order),
                $order->product_type === 'subscription' && $order->subscription_id === null
                    => self::activateNewSubscription($order),
                default => false,
            };
```

3. 类尾新增方法(逻辑逐行来自 `SubscriptionService::processNewSubscriptionActivation` 的循环体,src/Services/SubscriptionService.php:91-143):

```php
    /**
     * 新购订阅激活:建 Subscription(auto_renew=1 opt-out)+ 发放会员权益。
     * 已有 active/pending_renewal 订阅时不重复开通,留给 cron 在旧订阅结束后处理。
     */
    private static function activateNewSubscription(Order $order): bool
    {
        if (! in_array($order->billing_provider, SubscriptionService::SELF_MANAGED, true)) {
            return false;
        }

        $user = (new User())->where('id', $order->user_id)->lockForUpdate()->first();

        if ($user === null) {
            return false;
        }

        $existing = (new Subscription())
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'pending_renewal'])
            ->first();

        if ($existing !== null) {
            return false;
        }

        $content = json_decode($order->product_content);
        $billingCycle = $content->billing_cycle_selected;
        $today = Carbon::today();
        $endDate = SubscriptionService::calculateEndDate($today, $billingCycle);

        $subscription = new Subscription();
        $subscription->user_id = $user->id;
        $subscription->product_id = $order->product_id;
        $subscription->product_content = $order->product_content;
        $subscription->billing_cycle = $billingCycle;
        $subscription->renewal_price = $order->price;
        $subscription->start_date = $today->format('Y-m-d');
        $subscription->end_date = $endDate->format('Y-m-d');
        $subscription->reset_day = (int) $today->format('d');
        $subscription->last_reset_date = $today->format('Y-m-d');
        $subscription->status = 'active';
        $subscription->billing_provider = 'manual';
        // 自动续费默认开启(opt-out):由自建引擎在到期时按「余额优先 → 存档卡 → 宽限」
        // 续费,用户可在订阅页主动取消。
        $subscription->auto_renew = 1;
        $subscription->created_at = $today->format('Y-m-d H:i:s');
        $subscription->updated_at = $today->format('Y-m-d H:i:s');
        $subscription->save();

        SubscriptionService::grantMembershipFromContent($user, $content, $endDate->format('Y-m-d') . ' 23:59:59');

        $order->status = 'activated';
        $order->update_time = time();
        $order->save();

        return true;
    }
```

`src/Services/SubscriptionService.php` 的 `processNewSubscriptionActivation()`(82-147 行)整体替换为委托(查询条件不变,循环体换成调用):

```php
    /**
     * 处理新订阅激活(每5分钟兜底;支付路径已即时调用 OrderActivation)
     */
    public static function processNewSubscriptionActivation(): void
    {
        $orders = (new Order())->where('status', 'pending_activation')
            ->where('product_type', 'subscription')
            ->whereNull('subscription_id')
            ->whereIn('billing_provider', self::SELF_MANAGED)
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            if (OrderActivation::tryActivate((int) $order->id)) {
                echo "订阅订单 #{$order->id} 已激活" . PHP_EOL;
            } else {
                echo "订阅订单 #{$order->id} 本轮未激活(用户不存在或已有活跃/待续费订阅)" . PHP_EOL;
            }
        }

        echo Tools::toDateTime(time()) . ' 新订阅激活处理完成' . PHP_EOL;
    }
```

删除原循环体;`SubscriptionService` 与 `OrderActivation` 同在 `App\Services`,无需 use。检查 `SubscriptionService` 文件头部 use 的 `Carbon`、`Subscription` 等是否仍被其余方法使用(是——续费/重置等大量使用,保留)。

- [ ] **Step 4: 跑测试确认通过**

Run: `./vendor/bin/pest tests/Unit/Services/OrderActivationSubscriptionTest.php`
Expected: PASS(5 tests)

- [ ] **Step 5: 回归**

Run: `./vendor/bin/pest tests/Unit/Controllers/SubscriptionPurchaseTest.php tests/Unit/Services/SubscriptionServiceGrantTest.php tests/Unit/Services/SubscriptionServiceProviderFilterTest.php`
Expected: PASS(此时购买路径还没接同步激活,原断言 `pending_activation` 仍成立)

- [ ] **Step 6: Commit**

```bash
git add src/Services/OrderActivation.php src/Services/SubscriptionService.php tests/Unit/Services/OrderActivationSubscriptionTest.php
git commit -m "feat(activation): move new-subscription activation into OrderActivation; cron delegates

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: 续费激活收拢 + cron 续费循环委托

**Files:**
- Modify: `src/Services/OrderActivation.php`(加 match 分支 + activateRenewal)
- Modify: `src/Services/SubscriptionService.php:216-252`(processRenewalActivation)
- Test: `tests/Unit/Services/OrderActivationRenewalTest.php`

**Interfaces:**
- Consumes: `SubscriptionService::advanceRenewedPeriod`、`makeSub()` 测试工厂
- Produces: `tryActivate` 现在能激活 `subscription_id` 非空的续费订单(推进订阅周期)

- [ ] **Step 1: 写失败测试**

创建 `tests/Unit/Services/OrderActivationRenewalTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderActivation;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * OrderActivation — subscription 续费(subscription_id 非空)。
 * 逻辑与 SubscriptionService::processRenewalActivation 单笔体一致:
 * advanceRenewedPeriod 推进周期 + 订单置 activated。提前付款语义不变:
 * newStart = end_date + 1 天,提前激活不吃亏。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

if (! function_exists('activationMakeRenewalOrder')) {
    function activationMakeRenewalOrder(int $userId, int $subscriptionId): Order
    {
        $order = new Order();
        $order->user_id = $userId;
        $order->product_id = 1;
        $order->product_type = 'subscription';
        $order->product_name = 'Pro';
        $order->product_content = json_encode(['name' => 'Pro', 'billing_cycle_selected' => 'month']);
        $order->subscription_id = $subscriptionId;
        $order->coupon = '';
        $order->price = 10.0;
        $order->status = 'pending_activation';
        $order->billing_provider = 'manual';
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'product';
        $invoice->user_id = $userId;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => 'Pro 续费', 'price' => 10.0]]);
        $invoice->price = 10.0;
        $invoice->status = 'paid_balance';
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return $order;
    }
}

it('advances the subscription period and activates the renewal order', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user);
    $endBefore = $sub->end_date;
    $order = activationMakeRenewalOrder($user->id, (int) $sub->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();

    $freshSub = (new Subscription())->find($sub->id);
    $expectedStart = Carbon::parse($endBefore)->addDay()->format('Y-m-d');
    expect($freshSub->start_date)->toBe($expectedStart);
    expect($freshSub->status)->toBe('active');
    expect($freshSub->grace_until)->toBeNull();

    expect((new Order())->find($order->id)->status)->toBe('activated');

    $freshUser = (new User())->find($user->id);
    expect($freshUser->class_expire)->toBe($freshSub->end_date . ' 23:59:59');
});

it('returns false when the linked subscription is missing', function () {
    $user = makeUserWithMoney(0.0);
    $order = activationMakeRenewalOrder($user->id, 999999);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new Order())->find($order->id)->status)->toBe('pending_activation');
});

it('is idempotent: the period advances exactly once', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user);
    $order = activationMakeRenewalOrder($user->id, (int) $sub->id);

    expect(OrderActivation::tryActivate((int) $order->id))->toBeTrue();
    $endAfterFirst = (new Subscription())->find($sub->id)->end_date;

    expect(OrderActivation::tryActivate((int) $order->id))->toBeFalse();
    expect((new Subscription())->find($sub->id)->end_date)->toBe($endAfterFirst);
});

it('keeps the cron renewal loop working through delegation', function () {
    $user = makeUserWithMoney(0.0);
    $sub = makeSub($user);
    $order = activationMakeRenewalOrder($user->id, (int) $sub->id);

    ob_start();
    SubscriptionService::processRenewalActivation();
    ob_get_clean();

    expect((new Order())->find($order->id)->status)->toBe('activated');
});
```

(同样先核对 `makeSub()` 实际签名/默认值,`status`、`end_date` 参数名以 helper 为准。)

- [ ] **Step 2: 跑测试确认失败**

Run: `./vendor/bin/pest tests/Unit/Services/OrderActivationRenewalTest.php`
Expected: 前 3 个 FAIL(subscription_id 非空落入 default → false);cron 委托用例 PASS(旧实现仍在)

- [ ] **Step 3: 实现**

`src/Services/OrderActivation.php` match 加分支:

```php
            return match (true) {
                $order->product_type === 'topup' => self::activateTopup($order),
                $order->product_type === 'subscription' && $order->subscription_id === null
                    => self::activateNewSubscription($order),
                $order->product_type === 'subscription' && $order->subscription_id !== null
                    => self::activateRenewal($order),
                default => false,
            };
```

类尾新增(逻辑来自 SubscriptionService.php:225-248 循环体):

```php
    /**
     * 续费订单激活:推进订阅周期(newStart = end_date + 1 天,提前付款不吃亏)。
     * 流量重置归 resetSubscriptionBandwidth 在 reset_day 负责,此处绝不重置。
     */
    private static function activateRenewal(Order $order): bool
    {
        if (! in_array($order->billing_provider, SubscriptionService::SELF_MANAGED, true)) {
            return false;
        }

        $subscription = (new Subscription())->find($order->subscription_id);

        if ($subscription === null) {
            return false;
        }

        $user = (new User())->where('id', $order->user_id)->lockForUpdate()->first();

        if ($user === null) {
            return false;
        }

        SubscriptionService::advanceRenewedPeriod($subscription, $user);

        $order->status = 'activated';
        $order->update_time = time();
        $order->save();

        return true;
    }
```

`src/Services/SubscriptionService.php` 的 `processRenewalActivation()`(216-252 行)替换为委托:

```php
    /**
     * 处理续费订阅激活(每5分钟兜底;支付路径已即时调用 OrderActivation)
     */
    public static function processRenewalActivation(): void
    {
        $orders = (new Order())->where('status', 'pending_activation')
            ->where('product_type', 'subscription')
            ->whereNotNull('subscription_id')
            ->whereIn('billing_provider', self::SELF_MANAGED)
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            if (OrderActivation::tryActivate((int) $order->id)) {
                echo "续费订单 #{$order->id} 已激活,订阅 #{$order->subscription_id} 已续期" . PHP_EOL;
            } else {
                echo "续费订单 #{$order->id} 本轮未激活(关联订阅或用户不存在)" . PHP_EOL;
            }
        }

        echo Tools::toDateTime(time()) . ' 续费订阅激活处理完成' . PHP_EOL;
    }
```

- [ ] **Step 4: 跑测试确认通过**

Run: `./vendor/bin/pest tests/Unit/Services/OrderActivationRenewalTest.php`
Expected: PASS(4 tests)

- [ ] **Step 5: 回归自动续费全套**

Run: `./vendor/bin/pest tests/Unit/Services/AutoRenew tests/Unit/Controllers/SubscriptionAutoRenewTest.php`
Expected: PASS(processAutoRenew 的原子收尾路径不经过 processRenewalActivation,不受影响;若有失败,先读失败用例再动手,不许猜)

- [ ] **Step 6: Commit**

```bash
git add src/Services/OrderActivation.php src/Services/SubscriptionService.php tests/Unit/Services/OrderActivationRenewalTest.php
git commit -m "feat(activation): renewal activation via OrderActivation; cron delegates

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: 网关回调 postPayment 即时激活

**Files:**
- Modify: `src/Services/Gateway/Base.php:53-91`(postPayment 末尾)
- Test: `tests/Unit/Services/GatewayPostPaymentActivationTest.php`

**Interfaces:**
- Consumes: `OrderActivation::tryActivate`
- Produces: 所有走 `postPayment` 的网关(AlipayF2F/Epay/Cryptomus/PayPal/Smogate/Stripe 一次性支付)回调即激活

- [ ] **Step 1: 写失败测试**

创建 `tests/Unit/Services/GatewayPostPaymentActivationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Paylist;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Gateway\Base;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * Gateway/Base::postPayment 现在应在标记账单已付后同步激活订单:
 * 网关回调一到,订阅/充值立即生效,不再等 5 分钟 cron。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
    ensureUserMoneyLogTable();
});

afterEach(function () {
    dropUserMoneyLogTable();
    TestDatabase::dropTables();
});

if (! function_exists('postPaymentTestGateway')) {
    function postPaymentTestGateway(): Base
    {
        return new class extends Base {
            public function purchase(ServerRequest $request, Response $response, array $args): ResponseInterface
            {
                throw new RuntimeException('unused in test');
            }

            public function notify(ServerRequest $request, Response $response, array $args): ResponseInterface
            {
                throw new RuntimeException('unused in test');
            }

            public static function _name(): string
            {
                return 'testgw';
            }

            public static function _enable(): bool
            {
                return false;
            }

            public static function _readableName(): string
            {
                return 'Test Gateway';
            }

            public static function getPurchaseHTML(): string
            {
                return '';
            }
        };
    }
}

if (! function_exists('postPaymentMakePending')) {
    /**
     * 一套「网关待支付」组合:pending_payment 订单 + unpaid 账单 + status=0 paylist。
     *
     * @return array{0: Order, 1: Invoice, 2: string} 订单、账单、tradeno
     */
    function postPaymentMakePending(User $user, string $productType, array $content, float $price): array
    {
        $order = new Order();
        $order->user_id = $user->id;
        $order->product_id = 1;
        $order->product_type = $productType;
        $order->product_name = 'Pro';
        $order->product_content = json_encode($content);
        $order->subscription_id = null;
        $order->coupon = '';
        $order->price = $price;
        $order->status = 'pending_payment';
        $order->billing_provider = 'manual';
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = $productType === 'topup' ? 'topup' : 'product';
        $invoice->user_id = $user->id;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => 'Pro', 'price' => $price]]);
        $invoice->price = $price;
        $invoice->status = 'unpaid';
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        $tradeno = 'testgw_' . bin2hex(random_bytes(6));
        $paylist = new Paylist();
        $paylist->userid = $user->id;
        $paylist->total = $price;
        $paylist->invoice_id = $invoice->id;
        $paylist->tradeno = $tradeno;
        $paylist->status = 0;
        $paylist->gateway = 'testgw';
        $paylist->save();

        return [$order, $invoice, $tradeno];
    }
}

it('activates a subscription order the moment the gateway callback lands', function () {
    $user = makeUserWithMoney(0.0, class: 0, classExpire: date('Y-m-d H:i:s', strtotime('-1 day')));
    [$order, $invoice, $tradeno] = postPaymentMakePending($user, 'subscription', [
        'class' => 1,
        'bandwidth' => 100,
        'node_group' => 0,
        'speed_limit' => 0,
        'ip_limit' => 0,
        'billing_cycle_selected' => 'month',
        'name' => 'Pro',
    ], 10.0);

    postPaymentTestGateway()->postPayment($tradeno);

    expect((new Invoice())->find($invoice->id)->status)->toBe('paid_gateway');
    expect((new Order())->find($order->id)->status)->toBe('activated');
    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(1);
    expect((int) (new User())->find($user->id)->class)->toBe(1);
});

it('credits a topup order the moment the gateway callback lands', function () {
    $user = makeUserWithMoney(5.0);
    [$order, , $tradeno] = postPaymentMakePending($user, 'topup', ['amount' => 30.0], 30.0);

    postPaymentTestGateway()->postPayment($tradeno);

    expect((float) (new User())->find($user->id)->money)->toBe(35.0);
    expect((new Order())->find($order->id)->status)->toBe('activated');
});

it('stays safe on duplicate webhook delivery', function () {
    $user = makeUserWithMoney(0.0);
    [$order, , $tradeno] = postPaymentMakePending($user, 'topup', ['amount' => 10.0], 10.0);

    $gateway = postPaymentTestGateway();
    $gateway->postPayment($tradeno);
    $gateway->postPayment($tradeno);

    expect((float) (new User())->find($user->id)->money)->toBe(10.0);
    expect((new Order())->find($order->id)->status)->toBe('activated');
});
```

(Paylist 列已核对 `tests/TestDatabase.php:228-238`:`userid`/`total`/`status`/`invoice_id`/`tradeno`/`gateway`/`datetime`,与上述工厂一致。)

- [ ] **Step 2: 跑测试确认失败**

Run: `./vendor/bin/pest tests/Unit/Services/GatewayPostPaymentActivationTest.php`
Expected: FAIL — 账单变 `paid_gateway` 但订单仍 `pending_payment`(现状:回调不激活)

- [ ] **Step 3: 实现**

`src/Services/Gateway/Base.php`:

1. `use` 区加入 `use App\Services\OrderActivation;`
2. `postPayment()` 函数体末尾(referral reward 块之后)追加:

```php
        // 回调即时激活:账单已结清则同步激活订单(幂等,5 分钟 cron 仍兜底)。
        // 网关重复投递安全:tryActivate 行锁 + 状态复查,只成功一次。
        if ($invoice !== null && $invoice->order_id !== null) {
            OrderActivation::tryActivate((int) $invoice->order_id);
        }
```

- [ ] **Step 4: 跑测试确认通过**

Run: `./vendor/bin/pest tests/Unit/Services/GatewayPostPaymentActivationTest.php`
Expected: PASS(3 tests)

- [ ] **Step 5: 回归网关相关**

Run: `./vendor/bin/pest tests/Feature/Gateway tests/Unit/Services/Stripe`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Services/Gateway/Base.php tests/Unit/Services/GatewayPostPaymentActivationTest.php
git commit -m "feat(activation): gateway callbacks activate orders instantly

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: 余额/免费购买路径即时激活 + 更新既有断言

**Files:**
- Modify: `src/Controllers/User/OrderController.php:461-513`(subscription() 余额结算后)
- Modify: `src/Controllers/User/InvoiceController.php:196-215`(payBalance() paid_full 后)
- Modify: `tests/Unit/Controllers/SubscriptionPurchaseTest.php`(断言从「pending_activation 等 cron」改为「即时 activated」)
- Test: 复用上述测试文件

**Interfaces:**
- Consumes: `OrderActivation::tryActivate`
- Produces: 余额购买、免费(0 元)购买、账单页余额支付三条路径即时生效

- [ ] **Step 1: 先改断言(红)**

`tests/Unit/Controllers/SubscriptionPurchaseTest.php` 四处更新:

1. 主用例(原 line 210 起「settles a subscription purchase from balance…」):
   - `expect($order->status)->toBe('pending_activation');`(原 236 行)改为 `expect($order->status)->toBe('activated');`
   - 原 246-263 行「cron 创建订阅」段改为「订阅已即时创建 + cron 复跑是幂等 no-op」:

```php
    // 订阅已在购买请求内即时创建(不再等 5 分钟 cron)。
    $sub = (new Subscription())->where('user_id', $user->id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->billing_provider)->toBe('manual');
    expect((int) $sub->auto_renew)->toBe(1);
    expect($sub->status)->toBe('active');

    // 会员权益同步发放(class 1, 100 GB)。
    $fresh = (new User())->find($user->id);
    expect((int) $fresh->class)->toBe(1);
    expect((int) $fresh->transfer_enable)->toBe(100 * 1024 ** 3);

    expect((new Order())->find($order->id)->status)->toBe('activated');

    // cron 兜底复跑必须幂等:不产生第二条订阅。
    ob_start();
    SubscriptionService::processNewSubscriptionActivation();
    ob_get_clean();
    expect((new Subscription())->where('user_id', $user->id)->count())->toBe(1);
```

2. 「settles when the balance exactly equals the price」(原 311 行):`->toBe('pending_activation')` → `->toBe('activated')`
3. 「keeps the zero-price activation path…」(原 314-330 行):`->toBe('pending_activation')` → `->toBe('activated')`,用例名改为 `'keeps the zero-price path: a free subscription activates instantly'`
4. 「ignores auto_renew_provider=stripe…」(原 381 行):`->toBe('pending_activation')` → `->toBe('activated')`
5. 文件头部注释块(原 22-40 行)中「lets the existing 5-min activation chain create the Subscription」一句改为「activates instantly via OrderActivation (cron remains the fallback)」。

- [ ] **Step 2: 跑测试确认失败**

Run: `./vendor/bin/pest tests/Unit/Controllers/SubscriptionPurchaseTest.php`
Expected: 上述四个用例 FAIL(订单仍 pending_activation)

- [ ] **Step 3: 实现**

`src/Controllers/User/OrderController.php` `subscription()`:

1. 文件头部 `use` 区加入 `use App\Services\OrderActivation;`
2. 余额结算 `DB::transaction(...)` 块(原 471-510 行)之后、`return $response->withHeader('HX-Redirect', ...)`(原 513 行)之前插入:

```php
        // 已结清订单(余额结算成功 / 0 元免费单)即时激活;网关待支付订单此调用是
        // 无害 no-op(账单未付,tryActivate 返回 false),cron 仍兜底一切。
        OrderActivation::tryActivate((int) $order->id);
```

`src/Controllers/User/InvoiceController.php` `payBalance()`:

1. `use` 区加入 `use App\Services\OrderActivation;`
2. `if ($outcome === 'paid_full') {`(原 210 行)块内、`return` 之前插入:

```php
            // 余额全额支付成功:即时激活关联订单(幂等,cron 兜底)。
            if ($invoice->order_id !== null) {
                OrderActivation::tryActivate((int) $invoice->order_id);
            }
```

- [ ] **Step 4: 跑测试确认通过**

Run: `./vendor/bin/pest tests/Unit/Controllers/SubscriptionPurchaseTest.php tests/Unit/Controllers/InvoicePayBalanceGuardTest.php`
Expected: PASS(guard 测试里 order 可能不存在 → tryActivate 对 null order 返回 false,已被 Task 1 用例覆盖)

- [ ] **Step 5: 全套控制器 + 服务回归**

Run: `./vendor/bin/pest tests/Unit/Controllers tests/Unit/Services`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/User/OrderController.php src/Controllers/User/InvoiceController.php tests/Unit/Controllers/SubscriptionPurchaseTest.php
git commit -m "feat(activation): balance and zero-price purchases activate instantly

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: 账单状态 JSON 端点(方案 C 后端)

**Files:**
- Modify: `app/routes.php:110`(旁边加一行路由)
- Modify: `src/Controllers/User/InvoiceController.php`(新增 status 方法)
- Test: `tests/Unit/Controllers/InvoiceStatusEndpointTest.php`

**Interfaces:**
- Consumes: 无新依赖
- Produces: `GET /user/invoice/{id}/status` → `{"ret":1,"invoice_status":"paid_balance","order_status":"activated"}`;非本人/不存在 → `{"ret":0}`。Task 7 前端轮询它。

- [ ] **Step 1: 写失败测试**

创建 `tests/Unit/Controllers/InvoiceStatusEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use App\Controllers\User\InvoiceController;
use App\Models\Invoice;
use App\Models\Order;
use Tests\TestDatabase;

require_once __DIR__ . '/../Services/AutoRenew/AutoRenewHelpers.php';

/*
 * ---------------------------------------------------------------------------
 * GET /user/invoice/{id}/status — 账单页轮询端点。
 * 只暴露本人账单的 invoice/order 状态;他人或不存在 → ret 0(镜像 detail 的
 * user_id 过滤,防 IDOR)。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    $GLOBALS['user'] = null;
    TestDatabase::dropTables();
});

if (! function_exists('statusEndpointRequest')) {
    function statusEndpointRequest(): \Slim\Http\ServerRequest
    {
        return (new \Slim\Http\Factory\DecoratedServerRequestFactory(new \GuzzleHttp\Psr7\HttpFactory()))
            ->createServerRequest('GET', '/user/invoice/1/status');
    }

    function statusEndpointResponse(): \Slim\Http\Response
    {
        return new \Slim\Http\Response(new \GuzzleHttp\Psr7\Response(), new \GuzzleHttp\Psr7\HttpFactory());
    }

    /**
     * @return array{0: Order, 1: Invoice}
     */
    function statusEndpointMakePair(int $userId, string $orderStatus, string $invoiceStatus): array
    {
        $order = new Order();
        $order->user_id = $userId;
        $order->product_id = 1;
        $order->product_type = 'subscription';
        $order->product_name = 'Pro';
        $order->product_content = json_encode(['name' => 'Pro']);
        $order->subscription_id = null;
        $order->coupon = '';
        $order->price = 10.0;
        $order->status = $orderStatus;
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->type = 'product';
        $invoice->user_id = $userId;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([['content_id' => 0, 'name' => 'Pro', 'price' => 10.0]]);
        $invoice->price = 10.0;
        $invoice->status = $invoiceStatus;
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return [$order, $invoice];
    }
}

it('returns invoice and order status for the owner', function () {
    $user = makeUserWithMoney(0.0);
    $GLOBALS['user'] = $user;
    [, $invoice] = statusEndpointMakePair($user->id, 'activated', 'paid_balance');

    $response = (new InvoiceController())->status(
        statusEndpointRequest(),
        statusEndpointResponse(),
        ['id' => (string) $invoice->id]
    );

    $json = json_decode((string) $response->getBody());
    expect($json->ret)->toBe(1);
    expect($json->invoice_status)->toBe('paid_balance');
    expect($json->order_status)->toBe('activated');
});

it('rejects another user\'s invoice (IDOR guard)', function () {
    $owner = makeUserWithMoney(0.0);
    [, $invoice] = statusEndpointMakePair($owner->id, 'pending_payment', 'unpaid');

    $intruder = makeUserWithMoney(0.0);
    $GLOBALS['user'] = $intruder;

    $response = (new InvoiceController())->status(
        statusEndpointRequest(),
        statusEndpointResponse(),
        ['id' => (string) $invoice->id]
    );

    expect(json_decode((string) $response->getBody())->ret)->toBe(0);
});

it('returns ret 0 for a missing invoice', function () {
    $user = makeUserWithMoney(0.0);
    $GLOBALS['user'] = $user;

    $response = (new InvoiceController())->status(
        statusEndpointRequest(),
        statusEndpointResponse(),
        ['id' => '999999']
    );

    expect(json_decode((string) $response->getBody())->ret)->toBe(0);
});
```

(先看 `SubscriptionPurchaseTest` 如何注入用户:它用 `$GLOBALS['user'] = $user;` + 直接 `new OrderController()`。`InvoiceController` 同属 `BaseController` 体系,同法可用;若 `$this->user` 取法不同,以 `detail()` 现状为准调整。)

- [ ] **Step 2: 跑测试确认失败**

Run: `./vendor/bin/pest tests/Unit/Controllers/InvoiceStatusEndpointTest.php`
Expected: FAIL,`Call to undefined method ...::status()`

- [ ] **Step 3: 实现**

`src/Controllers/User/InvoiceController.php` 在 `detail()` 之后新增:

```php
    /**
     * 账单页轮询端点:返回账单与关联订单的当前状态。
     * 支付落账(网关回调异步到达)后前端据此自动刷新页面。
     */
    public function status(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $id = $this->antiXss->xss_clean($args['id']);

        $invoice = (new Invoice())->where('user_id', $this->user->id)->where('id', $id)->first();

        if ($invoice === null) {
            return $response->withJson(['ret' => 0]);
        }

        $order = $invoice->order_id === null ? null : (new Order())->find($invoice->order_id);

        return $response->withJson([
            'ret' => 1,
            'invoice_status' => $invoice->status,
            'order_status' => $order?->status,
        ]);
    }
```

(`Order` 若未 use,在文件头部补 `use App\Models\Order;` — 先查,payBalance 已用到 Order,应已存在。)

`app/routes.php` 110 行 `/invoice/{id}/view` 之后加:

```php
        $group->get('/invoice/{id:[0-9]+}/status', App\Controllers\User\InvoiceController::class . ':status');
```

- [ ] **Step 4: 跑测试确认通过**

Run: `./vendor/bin/pest tests/Unit/Controllers/InvoiceStatusEndpointTest.php`
Expected: PASS(3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/routes.php src/Controllers/User/InvoiceController.php tests/Unit/Controllers/InvoiceStatusEndpointTest.php
git commit -m "feat(invoice): JSON status endpoint for payment polling

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: 账单页轮询 + 支付确认横幅(方案 C 前端)

**Files:**
- Modify: `resources/views/tabler/user/invoice/view.tpl`

**Interfaces:**
- Consumes: Task 6 的 `GET /user/invoice/{id}/status`
- Produces: 未支付账单页每 3 秒轮询;账单脱离 unpaid/partially_paid 即整页刷新(刷新后支付区自然消失、状态显示已支付);带 `?paid=1` 回跳(Stripe success_url 已带)显示「支付确认中」横幅。

- [ ] **Step 1: 修改模板**

`resources/views/tabler/user/invoice/view.tpl` 两处:

1. 右侧支付卡片(原 95 行 `{if $invoice->status === 'unpaid' || $invoice->status === 'partially_paid'}` 块内、`<div class="card">` 之前)加横幅:

```smarty
                {if isset($smarty.get.paid)}
                <div class="alert alert-info" role="alert">
                    <div class="d-flex align-items-center">
                        <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                        <div>支付确认中,通常数秒内生效,页面将自动刷新…</div>
                    </div>
                </div>
                {/if}
```

2. 文件末尾 `{include file='user/footer.tpl'}` 之前,仍在同一个 payable `{if}` 逻辑下加轮询脚本(注意:脚本要独立一个 `{if}` 块放在 row 结构外,避免破坏栅格):

```smarty
{if $invoice->status === 'unpaid' || $invoice->status === 'partially_paid'}
<script>
    window.invoiceStatusUrl = '/user/invoice/{$invoice->id}/status';
</script>
{literal}
<script>
    // 支付落账轮询:账单脱离待支付状态即整页刷新(网关回调通常数秒内到达)。
    // 上限 200 次(约 10 分钟)后停止,避免挂机页面空转。
    (function () {
        var polls = 0;
        var timer = setInterval(function () {
            polls += 1;
            if (polls > 200) {
                clearInterval(timer);
                return;
            }
            fetch(window.invoiceStatusUrl, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ret === 1
                        && data.invoice_status !== 'unpaid'
                        && data.invoice_status !== 'partially_paid') {
                        clearInterval(timer);
                        window.location.reload();
                    }
                })
                .catch(function () {});
        }, 3000);
    })();
</script>
{/literal}
{/if}
```

插入位置:原 152 行 `{/if}`(payable 右栏结束)之后、`</div></div></div>`(page-body 收尾)与 footer include 之间,即与 `{include file='user/footer.tpl'}` 相邻。

- [ ] **Step 2: 语法验证**

Smarty 模板无编译测试基建,用 CLI 渲染冒烟:确认模板编译不炸即可。

Run: `php -l public/index.php && ./vendor/bin/pest tests/Unit/Views 2>/dev/null || true` 后,再执行一次全站视图相关测试:`./vendor/bin/pest tests/Unit/Views`
Expected: 原有 Views 测试 PASS(模板语法错误会在任何渲染该页的测试/运行时暴露;若 Views 测试不覆盖此模板,靠 Step 3 人工验证)

- [ ] **Step 3: 人工验证(必做,verification-before-completion)**

本地起站(按项目惯例)或部署到测试环境后:

1. 用测试账号创建一笔订阅订单,进入账单页(unpaid)→ 开发者工具 Network 应看到每 3 秒一次 `/status` 请求。
2. 用余额支付 → HX-Redirect 到账单列表(此路径不依赖轮询,确认订阅立即生效:用户等级/流量已更新)。
3. 再创建一笔,后台手动把账单置 `paid_admin`(模拟网关回调)→ 3 秒内页面自动刷新,支付区消失,状态显示已支付;订单已激活。
4. 确认已支付账单页(view)不再发起轮询请求。

- [ ] **Step 4: 全量回归**

Run: `./vendor/bin/pest`
Expected: 全部 PASS(个别与本改动无关的既有失败,先确认 master 上同样失败再放行)

- [ ] **Step 5: Commit**

```bash
git add resources/views/tabler/user/invoice/view.tpl
git commit -m "feat(invoice): poll payment status and auto-refresh on settle

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## 上线备注(不属于代码任务)

- crontab 不需要改:cron 从主路径降级为兜底,频率维持 5 分钟即可。
- 观察点:上线后网关回调日志中若出现激活异常(事务回滚),订单会留在 pending_activation 由 cron 收尾——用户最多回到旧体验,不会更糟。
- 管理员手动标记账单已付(`paid_admin`)仍走 cron(最多 5 分钟),属可接受范围;如需即时,后续在 Admin/InvoiceController 同样补一行 `tryActivate` 即可。
