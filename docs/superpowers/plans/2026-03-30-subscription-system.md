# Subscription System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a subscription-based billing system to SSPanel-UIM where users purchase traffic plans with monthly/quarterly/yearly billing cycles, monthly traffic reset, and automated renewal billing.

**Architecture:** New `subscription` table as the single source of truth for subscription state. New `subscription` product type in existing Product table. Reuse existing Order/Invoice/Payment flow for both new purchases and renewals. Cron service extended with subscription lifecycle tasks (activation, renewal billing, notifications, traffic reset, expiration).

**Tech Stack:** PHP 8.2+, Slim 4, Illuminate/Eloquent ORM, Smarty 5 templates, Tabler UI, Carbon for date math

**Design spec:** `docs/superpowers/specs/2026-03-30-subscription-system-design.md`

---

## File Structure

### New Files
- `db/migrations/2026033000-add-subscription-system.php` — Migration: subscription table + order.subscription_id + config entries
- `src/Models/Subscription.php` — Subscription Eloquent model
- `src/Services/SubscriptionService.php` — Subscription business logic (activation, renewal, reset, expiration)
- `src/Controllers/User/SubscriptionController.php` — User subscription management page
- `src/Controllers/Admin/SubscriptionController.php` — Admin subscription management
- `resources/views/tabler/user/subscription.tpl` — User "My Subscription" page
- `resources/views/tabler/admin/subscription/index.tpl` — Admin subscription list
- `resources/views/tabler/admin/subscription/edit.tpl` — Admin subscription edit (renewal price)
- `resources/email/subscription_renewal.tpl` — First renewal notice email
- `resources/email/subscription_reminder.tpl` — Second reminder email
- `resources/email/subscription_expired.tpl` — Expiration notice email

### Modified Files
- `src/Models/Product.php` — Add `subscription` to type() method
- `src/Models/Order.php` — Add `subscription` to productType(), add subscription_id property
- `src/Controllers/User/ProductController.php` — Add subscription products, hide TABP/Time
- `src/Controllers/User/OrderController.php` — Add subscription order creation with billing cycle, purchase restrictions
- `src/Controllers/Admin/ProductController.php` — Add subscription type to create/edit/validation
- `src/Services/Cron.php` — Add subscription lifecycle methods
- `src/Command/Cron.php` — Wire new cron methods into scheduler
- `app/routes.php` — Add user/admin subscription routes
- `resources/views/tabler/user/product.tpl` — Redesign with subscription cards, hide old types
- `resources/views/tabler/user/order/create.tpl` — Add billing cycle selector for subscriptions
- `resources/views/tabler/admin/product/create.tpl` — Add subscription type form fields
- `resources/views/tabler/admin/product/edit.tpl` — Add subscription type form fields

---

### Task 1: Database Migration

**Files:**
- Create: `db/migrations/2026033000-add-subscription-system.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $pdo = DB::getPdo();

        // Create subscription table
        $pdo->exec("
            CREATE TABLE subscription (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id         INT UNSIGNED NOT NULL,
                product_id      INT UNSIGNED NOT NULL,
                product_content JSON NOT NULL,
                billing_cycle   ENUM('month','quarter','year') NOT NULL,
                renewal_price   DECIMAL(12,2) NOT NULL,
                start_date      DATE NOT NULL,
                end_date        DATE NOT NULL,
                reset_day       TINYINT UNSIGNED NOT NULL,
                last_reset_date DATE NOT NULL,
                status          ENUM('active','pending_renewal','expired','cancelled') NOT NULL DEFAULT 'active',
                created_at      DATETIME NOT NULL,
                updated_at      DATETIME NOT NULL,
                INDEX idx_user (user_id),
                INDEX idx_status (status),
                INDEX idx_end_date (end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add subscription_id to order table
        $pdo->exec("ALTER TABLE `order` ADD COLUMN `subscription_id` INT UNSIGNED NULL AFTER `product_content`");

        // Add subscription_renewal_days config
        $pdo->exec("
            INSERT INTO config (item, value, class, is_public, type, `default`, mark)
            VALUES ('subscription_renewal_days', '7', 'cron', 0, 'int', '7', '订阅到期前X天生成续费账单')
        ");

        return 2026033000;
    }

    public function down(): int
    {
        $pdo = DB::getPdo();

        $pdo->exec("DROP TABLE IF EXISTS subscription");
        $pdo->exec("ALTER TABLE `order` DROP COLUMN `subscription_id`");
        $pdo->exec("DELETE FROM config WHERE item = 'subscription_renewal_days'");

        return 2025111300;
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php xcat Migration latest`
Expected: Migration applies successfully, subscription table created.

- [ ] **Step 3: Verify the migration**

Run: `php xcat Migration status`
Expected: Shows 2026033000 as latest applied migration.

- [ ] **Step 4: Commit**

```bash
git add db/migrations/2026033000-add-subscription-system.php
git commit -m "feat: add subscription system database migration"
```

---

### Task 2: Subscription Model

**Files:**
- Create: `src/Models/Subscription.php`
- Modify: `src/Models/Product.php`
- Modify: `src/Models/Order.php`

- [ ] **Step 1: Create the Subscription model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Query\Builder;

/**
 * @property int    $id
 * @property int    $user_id
 * @property int    $product_id
 * @property string $product_content
 * @property string $billing_cycle
 * @property float  $renewal_price
 * @property string $start_date
 * @property string $end_date
 * @property int    $reset_day
 * @property string $last_reset_date
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin Builder
 */
final class Subscription extends Model
{
    protected $connection = 'default';
    protected $table = 'subscription';

    /**
     * 订阅状态
     */
    public function status(): string
    {
        return match ($this->status) {
            'active' => '活跃',
            'pending_renewal' => '待续费',
            'expired' => '已过期',
            'cancelled' => '已取消',
            default => '未知',
        };
    }

    /**
     * 账单周期
     */
    public function billingCycle(): string
    {
        return match ($this->billing_cycle) {
            'month' => '月付',
            'quarter' => '季付',
            'year' => '年付',
            default => '未知',
        };
    }
}
```

- [ ] **Step 2: Update Product model — add subscription type**

In `src/Models/Product.php`, update the `type()` method to include subscription:

```php
    public function type(): string
    {
        return match ($this->type) {
            'tabp' => '时间流量包',
            'time' => '时间包',
            'bandwidth' => '流量包',
            'subscription' => '订阅套餐',
            default => '其他',
        };
    }
```

- [ ] **Step 3: Update Order model — add subscription type and subscription_id property**

In `src/Models/Order.php`, add `subscription_id` to the docblock and update `productType()`:

Update the docblock to add after `product_content`:
```php
 * @property int|null $subscription_id 关联订阅ID
```

Update the `productType()` method:
```php
    public function productType(): string
    {
        return match ($this->product_type) {
            'tabp' => '时间流量包',
            'time' => '时间包',
            'bandwidth' => '流量包',
            'topup' => '充值',
            'subscription' => '订阅套餐',
            default => '其他',
        };
    }
```

- [ ] **Step 4: Commit**

```bash
git add src/Models/Subscription.php src/Models/Product.php src/Models/Order.php
git commit -m "feat: add Subscription model and update Product/Order models"
```

---

### Task 3: Subscription Service — Core Logic

**Files:**
- Create: `src/Services/SubscriptionService.php`

- [ ] **Step 1: Create SubscriptionService with date calculation and activation logic**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Utils\Tools;
use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function json_decode;
use function json_encode;
use function time;
use const PHP_EOL;

final class SubscriptionService
{
    /**
     * 计算订阅结束日期
     *
     * 使用 addMonthsNoOverflow 处理月末溢出，确保到期日与重置日对齐
     * 例如：1月31日 + 1个月 = 2月28日（而非3月3日）
     */
    public static function calculateEndDate(Carbon $startDate, string $billingCycle): Carbon
    {
        $months = match ($billingCycle) {
            'month' => 1,
            'quarter' => 3,
            'year' => 12,
            default => 1,
        };

        return $startDate->copy()->addMonthsNoOverflow($months)->subDay();
    }

    /**
     * 计算订阅周期价格
     */
    public static function calculateCyclePrice(float $monthlyPrice, string $billingCycle, object $content): float
    {
        $discount = match ($billingCycle) {
            'quarter' => (float) ($content->discount->quarter ?? 1.0),
            'year' => (float) ($content->discount->year ?? 1.0),
            default => 1.0,
        };

        $months = match ($billingCycle) {
            'month' => 1,
            'quarter' => 3,
            'year' => 12,
            default => 1,
        };

        return round($monthlyPrice * $months * $discount, 2);
    }

    /**
     * 激活新订阅（Cron 调用）
     */
    public static function processNewSubscriptionActivation(): void
    {
        $orders = (new Order())->where('status', 'pending_activation')
            ->where('product_type', 'subscription')
            ->whereNull('subscription_id')
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            $user = (new User())->find($order->user_id);

            if ($user === null) {
                continue;
            }

            // 检查用户是否已有活跃订阅
            $existingSub = (new Subscription())->where('user_id', $user->id)
                ->whereIn('status', ['active', 'pending_renewal'])
                ->first();

            if ($existingSub !== null) {
                echo "用户 #{$user->id} 已有活跃订阅，跳过订单 #{$order->id}" . PHP_EOL;
                continue;
            }

            $content = json_decode($order->product_content);
            $now = Carbon::today();

            // 创建订阅记录
            $subscription = new Subscription();
            $subscription->user_id = $user->id;
            $subscription->product_id = $order->product_id;
            $subscription->product_content = $order->product_content;
            $subscription->billing_cycle = $content->billing_cycle_selected;
            $subscription->renewal_price = $order->price;
            $subscription->start_date = $now->toDateString();
            $subscription->end_date = self::calculateEndDate($now, $content->billing_cycle_selected)->toDateString();
            $subscription->reset_day = $now->day;
            $subscription->last_reset_date = $now->toDateString();
            $subscription->status = 'active';
            $subscription->created_at = $now->toDateTimeString();
            $subscription->updated_at = $now->toDateTimeString();
            $subscription->save();

            // 更新用户属性
            $user->u = 0;
            $user->d = 0;
            $user->transfer_today = 0;
            $user->transfer_enable = Tools::gbToB($content->bandwidth);
            $user->class = $content->class;
            $user->class_expire = $subscription->end_date . ' 23:59:59';
            $user->node_group = $content->node_group;
            $user->node_speedlimit = $content->speed_limit;
            $user->node_iplimit = $content->ip_limit;
            $user->save();

            // 更新订单状态
            $order->status = 'activated';
            $order->update_time = time();
            $order->save();

            echo "订阅订单 #{$order->id} 已激活，订阅 #{$subscription->id} 已创建" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅新购激活处理完成' . PHP_EOL;
    }

    /**
     * 处理续费订单激活（Cron 调用）
     */
    public static function processRenewalActivation(): void
    {
        $orders = (new Order())->where('status', 'pending_activation')
            ->where('product_type', 'subscription')
            ->whereNotNull('subscription_id')
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            $subscription = (new Subscription())->find($order->subscription_id);

            if ($subscription === null) {
                echo "续费订单 #{$order->id} 关联的订阅不存在，跳过" . PHP_EOL;
                continue;
            }

            $user = (new User())->find($order->user_id);

            if ($user === null) {
                continue;
            }

            // 续期：新周期从旧 end_date + 1 天开始
            $newStart = Carbon::parse($subscription->end_date)->addDay();
            $newEnd = self::calculateEndDate($newStart, $subscription->billing_cycle);

            $subscription->start_date = $newStart->toDateString();
            $subscription->end_date = $newEnd->toDateString();
            $subscription->status = 'active';
            $subscription->updated_at = Carbon::now()->toDateTimeString();
            $subscription->save();

            // 更新用户 class_expire
            $user->class_expire = $newEnd->toDateString() . ' 23:59:59';
            $user->save();

            // 更新订单状态
            $order->status = 'activated';
            $order->update_time = time();
            $order->save();

            echo "续费订单 #{$order->id} 已激活，订阅 #{$subscription->id} 已续期至 {$newEnd->toDateString()}" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅续费激活处理完成' . PHP_EOL;
    }

    /**
     * 月度流量重置（每日 Cron 调用）
     */
    public static function resetSubscriptionBandwidth(): void
    {
        $subscriptions = (new Subscription())->where('status', 'active')->get();
        $today = Carbon::today();

        foreach ($subscriptions as $sub) {
            $daysInMonth = $today->daysInMonth;
            $resetDay = min($sub->reset_day, $daysInMonth);

            if ($today->day !== $resetDay) {
                continue;
            }

            // 检查本月是否已重置
            $lastReset = Carbon::parse($sub->last_reset_date);
            if ($lastReset->month === $today->month && $lastReset->year === $today->year) {
                continue;
            }

            $user = (new User())->find($sub->user_id);
            if ($user === null) {
                continue;
            }

            $content = json_decode($sub->product_content);
            $user->u = 0;
            $user->d = 0;
            $user->transfer_enable = Tools::gbToB($content->bandwidth);
            $user->save();

            $sub->last_reset_date = $today->toDateString();
            $sub->updated_at = Carbon::now()->toDateTimeString();
            $sub->save();

            echo "订阅 #{$sub->id} 用户 #{$user->id} 流量已重置为 {$content->bandwidth}GB" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅用户月度流量重置完成' . PHP_EOL;
    }

    /**
     * 生成续费账单（每日 Cron 调用）
     */
    public static function generateRenewalOrder(): void
    {
        $renewalDays = Config::obtain('subscription_renewal_days');
        $targetDate = Carbon::today()->addDays((int) $renewalDays)->toDateString();

        $subscriptions = (new Subscription())->where('status', 'active')
            ->where('end_date', $targetDate)
            ->get();

        foreach ($subscriptions as $sub) {
            // 检查是否已经有未取消的续费订单
            $existingOrder = (new Order())->where('subscription_id', $sub->id)
                ->where('product_type', 'subscription')
                ->whereNotIn('status', ['cancelled', 'expired', 'activated'])
                ->first();

            if ($existingOrder !== null) {
                continue;
            }

            $user = (new User())->find($sub->user_id);
            if ($user === null) {
                continue;
            }

            $content = json_decode($sub->product_content);

            // 创建续费订单
            $order = new Order();
            $order->user_id = $sub->user_id;
            $order->product_id = $sub->product_id;
            $order->product_type = 'subscription';
            $order->product_name = '续费 - ' . ($content->name ?? '订阅套餐');
            $order->product_content = $sub->product_content;
            $order->subscription_id = $sub->id;
            $order->coupon = '';
            $order->price = $sub->renewal_price;
            $order->status = $sub->renewal_price === 0.0 ? 'pending_activation' : 'pending_payment';
            $order->create_time = time();
            $order->update_time = time();
            $order->save();

            // 创建续费账单
            $invoiceContent = [];
            $invoiceContent[] = [
                'content_id' => 0,
                'name' => '续费 - ' . ($content->name ?? '订阅套餐') . ' (' . $sub->billingCycle() . ')',
                'price' => $sub->renewal_price,
            ];

            $invoice = new Invoice();
            $invoice->user_id = $sub->user_id;
            $invoice->order_id = $order->id;
            $invoice->content = json_encode($invoiceContent);
            $invoice->price = $sub->renewal_price;
            $invoice->status = $sub->renewal_price === 0.0 ? 'paid_gateway' : 'unpaid';
            $invoice->create_time = time();
            $invoice->update_time = time();
            $invoice->pay_time = 0;
            $invoice->type = 'product';
            $invoice->save();

            // 更新订阅状态
            $sub->status = 'pending_renewal';
            $sub->updated_at = Carbon::now()->toDateTimeString();
            $sub->save();

            // 发送首次续费通知
            $daysLeft = Carbon::today()->diffInDays(Carbon::parse($sub->end_date));
            $text = "你好，你的订阅套餐将在 {$daysLeft} 天后到期。" .
                "续费金额为 {$sub->renewal_price} 元，请及时完成支付以保持服务不中断。" .
                "你可以前往账单页面完成支付。";

            try {
                Notification::notifyUser(
                    $user,
                    $_ENV['appName'] . '-订阅续费通知',
                    $text,
                    'subscription_renewal.tpl'
                );
            } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            echo "已为订阅 #{$sub->id} 生成续费订单 #{$order->id} 和账单 #{$invoice->id}" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅续费账单生成完成' . PHP_EOL;
    }

    /**
     * 发送二次续费提醒（每日 Cron 调用）
     */
    public static function sendSecondRenewalNotification(): void
    {
        $renewalDays = (int) Config::obtain('subscription_renewal_days');
        $reminderDays = (int) ceil($renewalDays / 2);
        $targetDate = Carbon::today()->addDays($reminderDays)->toDateString();

        $subscriptions = (new Subscription())->where('status', 'pending_renewal')
            ->where('end_date', $targetDate)
            ->get();

        foreach ($subscriptions as $sub) {
            // 检查续费账单是否仍未支付
            $unpaidOrder = (new Order())->where('subscription_id', $sub->id)
                ->where('status', 'pending_payment')
                ->first();

            if ($unpaidOrder === null) {
                continue;
            }

            $user = (new User())->find($sub->user_id);
            if ($user === null) {
                continue;
            }

            $text = "你好，你的订阅套餐将在 {$reminderDays} 天后到期，续费账单仍未支付。" .
                "续费金额为 {$sub->renewal_price} 元，请尽快完成支付，避免服务中断。";

            try {
                Notification::notifyUser(
                    $user,
                    $_ENV['appName'] . '-订阅续费提醒',
                    $text,
                    'subscription_reminder.tpl'
                );
            } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            echo "已发送二次续费提醒至用户 #{$user->id}，订阅 #{$sub->id}" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅二次续费提醒发送完成' . PHP_EOL;
    }

    /**
     * 订阅到期处理（每日 Cron 调用）
     */
    public static function expireSubscription(): void
    {
        $today = Carbon::today()->toDateString();

        $subscriptions = (new Subscription())->where('status', 'pending_renewal')
            ->where('end_date', $today)
            ->get();

        foreach ($subscriptions as $sub) {
            // 检查续费订单是否已支付
            $unpaidOrder = (new Order())->where('subscription_id', $sub->id)
                ->whereIn('status', ['pending_payment'])
                ->first();

            // 如果续费订单已支付（pending_activation 或 activated），跳过
            if ($unpaidOrder === null) {
                continue;
            }

            $user = (new User())->find($sub->user_id);
            if ($user === null) {
                continue;
            }

            // 取消未付款的续费订单和账单
            $unpaidOrder->status = 'cancelled';
            $unpaidOrder->update_time = time();
            $unpaidOrder->save();

            $invoice = (new Invoice())->where('order_id', $unpaidOrder->id)->first();
            if ($invoice !== null && $invoice->status === 'unpaid') {
                $invoice->status = 'cancelled';
                $invoice->update_time = time();
                $invoice->save();
            }

            // 订阅过期
            $sub->status = 'expired';
            $sub->updated_at = Carbon::now()->toDateTimeString();
            $sub->save();

            // 降级用户
            $user->class = 0;
            $user->transfer_enable = 0;
            $user->node_group = 0;
            $user->node_speedlimit = 0;
            $user->node_iplimit = 0;
            $user->u = 0;
            $user->d = 0;
            $user->transfer_today = 0;
            $user->save();

            // 发送到期终止通知
            $text = '你好，你的订阅套餐已到期且续费账单未支付，服务已终止。如需继续使用，请重新购买套餐。';

            try {
                Notification::notifyUser(
                    $user,
                    $_ENV['appName'] . '-订阅到期通知',
                    $text,
                    'subscription_expired.tpl'
                );
            } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            echo "订阅 #{$sub->id} 已过期，用户 #{$user->id} 已降级" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅到期处理完成' . PHP_EOL;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Services/SubscriptionService.php
git commit -m "feat: add SubscriptionService with full lifecycle logic"
```

---

### Task 4: Wire Cron Tasks

**Files:**
- Modify: `src/Services/Cron.php`
- Modify: `src/Command/Cron.php`

- [ ] **Step 1: Add import to `src/Services/Cron.php`**

At the top of `src/Services/Cron.php`, after the existing use statements (around line 33), the SubscriptionService will be called directly from Command/Cron.php, so no changes needed in Services/Cron.php itself.

- [ ] **Step 2: Update `src/Command/Cron.php` — add subscription tasks to the 5-minute job**

In `src/Command/Cron.php`, add the import at the top:

```php
use App\Services\SubscriptionService;
```

After line 40 (`$jobs->processTopupOrderActivation();`), add:

```php
        // Run subscription related jobs
        SubscriptionService::processNewSubscriptionActivation();
        SubscriptionService::processRenewalActivation();
```

- [ ] **Step 3: Update `src/Command/Cron.php` — add subscription daily tasks**

Inside the daily job block (after line 81 `$jobs->resetTodayBandwidth();`), add:

```php
            // Subscription daily jobs (order matters)
            SubscriptionService::expireSubscription();
            SubscriptionService::generateRenewalOrder();
            SubscriptionService::sendSecondRenewalNotification();
            SubscriptionService::resetSubscriptionBandwidth();
```

- [ ] **Step 4: Commit**

```bash
git add src/Command/Cron.php
git commit -m "feat: wire subscription cron tasks into scheduler"
```

---

### Task 5: Email Templates

**Files:**
- Create: `resources/email/subscription_renewal.tpl`
- Create: `resources/email/subscription_reminder.tpl`
- Create: `resources/email/subscription_expired.tpl`

- [ ] **Step 1: Create subscription_renewal.tpl**

```smarty
{include file='header.tpl'}

<body style="background-color:#EEEEEE;">
    <div style="text-align: center">
        <div border="0" cellpadding="0" cellspacing="0" width="100%" style="padding-top:30px;table-layout:fixed;background-color:#EEEEEE;">
            <div align="center" valign="top" style="padding-right:10px;padding-left:10px;">
                <div border="0" cellpadding="0" cellspacing="0" style="background-color:#FFFFFF;max-width:600px;text-align:center;" width="100%">
                    <div align="center" valign="top">
                        <div border="0" cellpadding="0" cellspacing="0" width="100%">
                            <div align="center" valign="middle" style="padding-top:60px;padding-bottom:60px;">
                                <h2 class="bigTitle">
                                    订阅续费通知
                                </h2>
                            </div>
                        </div>
                        <div border="0" cellpadding="0" cellspacing="0" style="background-color:#FFFFFF" width="100%">
                            <div align="center" valign="top" style="padding-bottom:60px;padding-left:20px;padding-right:20px;">
                                <p class="midText">
                                    {$text}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

{include file='footer.tpl'}
```

- [ ] **Step 2: Create subscription_reminder.tpl**

Same structure as above but with title "订阅续费提醒":

```smarty
{include file='header.tpl'}

<body style="background-color:#EEEEEE;">
    <div style="text-align: center">
        <div border="0" cellpadding="0" cellspacing="0" width="100%" style="padding-top:30px;table-layout:fixed;background-color:#EEEEEE;">
            <div align="center" valign="top" style="padding-right:10px;padding-left:10px;">
                <div border="0" cellpadding="0" cellspacing="0" style="background-color:#FFFFFF;max-width:600px;text-align:center;" width="100%">
                    <div align="center" valign="top">
                        <div border="0" cellpadding="0" cellspacing="0" width="100%">
                            <div align="center" valign="middle" style="padding-top:60px;padding-bottom:60px;">
                                <h2 class="bigTitle">
                                    订阅续费提醒
                                </h2>
                            </div>
                        </div>
                        <div border="0" cellpadding="0" cellspacing="0" style="background-color:#FFFFFF" width="100%">
                            <div align="center" valign="top" style="padding-bottom:60px;padding-left:20px;padding-right:20px;">
                                <p class="midText">
                                    {$text}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

{include file='footer.tpl'}
```

- [ ] **Step 3: Create subscription_expired.tpl**

Same structure with title "订阅到期通知":

```smarty
{include file='header.tpl'}

<body style="background-color:#EEEEEE;">
    <div style="text-align: center">
        <div border="0" cellpadding="0" cellspacing="0" width="100%" style="padding-top:30px;table-layout:fixed;background-color:#EEEEEE;">
            <div align="center" valign="top" style="padding-right:10px;padding-left:10px;">
                <div border="0" cellpadding="0" cellspacing="0" style="background-color:#FFFFFF;max-width:600px;text-align:center;" width="100%">
                    <div align="center" valign="top">
                        <div border="0" cellpadding="0" cellspacing="0" width="100%">
                            <div align="center" valign="middle" style="padding-top:60px;padding-bottom:60px;">
                                <h2 class="bigTitle">
                                    订阅到期通知
                                </h2>
                            </div>
                        </div>
                        <div border="0" cellpadding="0" cellspacing="0" style="background-color:#FFFFFF" width="100%">
                            <div align="center" valign="top" style="padding-bottom:60px;padding-left:20px;padding-right:20px;">
                                <p class="midText">
                                    {$text}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

{include file='footer.tpl'}
```

- [ ] **Step 4: Commit**

```bash
git add resources/email/subscription_renewal.tpl resources/email/subscription_reminder.tpl resources/email/subscription_expired.tpl
git commit -m "feat: add subscription email notification templates"
```

---

### Task 6: Admin Product Controller — Subscription Type Support

**Files:**
- Modify: `src/Controllers/Admin/ProductController.php`
- Modify: `resources/views/tabler/admin/product/create.tpl`
- Modify: `resources/views/tabler/admin/product/edit.tpl`

- [ ] **Step 1: Update Admin ProductController — add subscription fields to $update_field**

In `src/Controllers/Admin/ProductController.php`, update the `$update_field` array (line 35-50) to add subscription-specific fields:

```php
    private static array $update_field = [
        'type',
        'name',
        'price',
        'status',
        'stock',
        'time',
        'bandwidth',
        'class',
        'class_time',
        'node_group',
        'speed_limit',
        'ip_limit',
        'class_required',
        'node_group_required',
        'billing_cycle_month',
        'billing_cycle_quarter',
        'billing_cycle_year',
        'discount_quarter',
        'discount_year',
    ];
```

- [ ] **Step 2: Update Admin ProductController — add() method subscription validation**

In `src/Controllers/Admin/ProductController.php`, in the `add()` method, after the bandwidth `elseif` block (after line 179) and before the `else` block (line 180), add subscription handling:

```php
        } elseif ($type === 'subscription') {
            if ($bandwidth <= 0) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => self::$invalid_data_msg,
                ]);
            }

            $billingCycleMonth = $request->getParam('billing_cycle_month') === 'true';
            $billingCycleQuarter = $request->getParam('billing_cycle_quarter') === 'true';
            $billingCycleYear = $request->getParam('billing_cycle_year') === 'true';

            if (! $billingCycleMonth && ! $billingCycleQuarter && ! $billingCycleYear) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '请至少选择一个账单周期',
                ]);
            }

            $discountQuarter = (float) ($request->getParam('discount_quarter') ?? 1.0);
            $discountYear = (float) ($request->getParam('discount_year') ?? 1.0);

            if ($discountQuarter <= 0 || $discountQuarter > 1 || $discountYear <= 0 || $discountYear > 1) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '折扣比例必须在 0-1 之间',
                ]);
            }

            $content = [
                'bandwidth' => $bandwidth,
                'class' => $class,
                'node_group' => $node_group,
                'speed_limit' => $speed_limit,
                'ip_limit' => $ip_limit,
                'billing_cycle' => [
                    'month' => $billingCycleMonth,
                    'quarter' => $billingCycleQuarter,
                    'year' => $billingCycleYear,
                ],
                'discount' => [
                    'quarter' => $discountQuarter,
                    'year' => $discountYear,
                ],
            ];
```

- [ ] **Step 3: Update Admin ProductController — update() method subscription validation**

In the `update()` method, add the same subscription handling block after the bandwidth `elseif` (after line 285) and before the `else` block:

```php
        } elseif ($type === 'subscription') {
            if ($bandwidth <= 0) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => self::$invalid_data_msg,
                ]);
            }

            $billingCycleMonth = $request->getParam('billing_cycle_month') === 'true';
            $billingCycleQuarter = $request->getParam('billing_cycle_quarter') === 'true';
            $billingCycleYear = $request->getParam('billing_cycle_year') === 'true';

            if (! $billingCycleMonth && ! $billingCycleQuarter && ! $billingCycleYear) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '请至少选择一个账单周期',
                ]);
            }

            $discountQuarter = (float) ($request->getParam('discount_quarter') ?? 1.0);
            $discountYear = (float) ($request->getParam('discount_year') ?? 1.0);

            if ($discountQuarter <= 0 || $discountQuarter > 1 || $discountYear <= 0 || $discountYear > 1) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '折扣比例必须在 0-1 之间',
                ]);
            }

            $content = [
                'bandwidth' => $bandwidth,
                'class' => $class,
                'node_group' => $node_group,
                'speed_limit' => $speed_limit,
                'ip_limit' => $ip_limit,
                'billing_cycle' => [
                    'month' => $billingCycleMonth,
                    'quarter' => $billingCycleQuarter,
                    'year' => $billingCycleYear,
                ],
                'discount' => [
                    'quarter' => $discountQuarter,
                    'year' => $discountYear,
                ],
            ];
```

- [ ] **Step 4: Update Admin ProductController — edit() method defaults for subscription**

In the `edit()` method (around line 88-94), add defaults for subscription fields:

```php
        $content->billing_cycle = $content->billing_cycle ?? (object) ['month' => true, 'quarter' => false, 'year' => false];
        $content->discount = $content->discount ?? (object) ['quarter' => 1.0, 'year' => 1.0];
```

- [ ] **Step 5: Update admin product create template**

In `resources/views/tabler/admin/product/create.tpl`, add `subscription` option to the type selector (around line 65-69):

```html
                                    <select id="type" class="col form-select">
                                        <option value="subscription">订阅套餐</option>
                                        <option value="bandwidth">流量包</option>
                                        <option value="tabp">时间流量包(旧)</option>
                                        <option value="time">时间包(旧)</option>
                                    </select>
```

After the purchase limits section (after line 151), add subscription-specific fields:

```html
                            <div class="hr-text">
                                <span>订阅设置</span>
                            </div>
                            <div id="billing_cycle_option">
                                <div class="mb-3">
                                    <label class="form-label">可用账单周期</label>
                                    <div>
                                        <label class="form-check form-check-inline">
                                            <input id="billing_cycle_month" class="form-check-input" type="checkbox" checked>
                                            <span class="form-check-label">月付</span>
                                        </label>
                                        <label class="form-check form-check-inline">
                                            <input id="billing_cycle_quarter" class="form-check-input" type="checkbox">
                                            <span class="form-check-label">季付</span>
                                        </label>
                                        <label class="form-check form-check-inline">
                                            <input id="billing_cycle_year" class="form-check-input" type="checkbox">
                                            <span class="form-check-label">年付</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="form-group mb-3 row">
                                    <label class="form-label col-3 col-form-label">季付折扣 (0-1)</label>
                                    <div class="col">
                                        <input id="discount_quarter" type="text" class="form-control" value="1.0">
                                    </div>
                                </div>
                                <div class="form-group mb-3 row">
                                    <label class="form-label col-3 col-form-label">年付折扣 (0-1)</label>
                                    <div class="col">
                                        <input id="discount_year" type="text" class="form-control" value="1.0">
                                    </div>
                                </div>
                            </div>
```

Update the JavaScript `$("#type").on("change")` handler to add a `subscription` case that shows: bandwidth, class, node_group, speed_limit, ip_limit, billing_cycle_option, and hides: time, class_time.

Add to the JS data object in the AJAX call:

```js
                    billing_cycle_month: $("#billing_cycle_month").is(":checked"),
                    billing_cycle_quarter: $("#billing_cycle_quarter").is(":checked"),
                    billing_cycle_year: $("#billing_cycle_year").is(":checked"),
                    discount_quarter: $("#discount_quarter").val(),
                    discount_year: $("#discount_year").val(),
```

- [ ] **Step 6: Update admin product edit template similarly**

Apply the same changes to `resources/views/tabler/admin/product/edit.tpl` — add subscription type option, billing cycle checkboxes and discount fields with pre-filled values from `{$content}`.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/Admin/ProductController.php resources/views/tabler/admin/product/create.tpl resources/views/tabler/admin/product/edit.tpl
git commit -m "feat: add subscription product type to admin product management"
```

---

### Task 7: User Product Page — Show Subscription Cards

**Files:**
- Modify: `src/Controllers/User/ProductController.php`
- Modify: `resources/views/tabler/user/product.tpl`

- [ ] **Step 1: Update User ProductController to fetch subscriptions and check active subscription**

Replace the entire `index()` method in `src/Controllers/User/ProductController.php`:

```php
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $subscriptions = (new Product())->where('status', '1')
            ->where('type', 'subscription')
            ->orderBy('id')
            ->get();

        $bandwidths = (new Product())->where('status', '1')
            ->where('type', 'bandwidth')
            ->orderBy('id')
            ->get();

        foreach ($subscriptions as $sub) {
            $sub->content = json_decode($sub->content);
        }

        foreach ($bandwidths as $bandwidth) {
            $bandwidth->content = json_decode($bandwidth->content);
        }

        // 检查用户是否有活跃订阅
        $hasActiveSubscription = (new \App\Models\Subscription())
            ->where('user_id', $this->user->id)
            ->whereIn('status', ['active', 'pending_renewal'])
            ->exists();

        return $response->write(
            $this->view()
                ->assign('subscriptions', $subscriptions)
                ->assign('bandwidths', $bandwidths)
                ->assign('hasActiveSubscription', $hasActiveSubscription)
                ->fetch('user/product.tpl')
        );
    }
```

- [ ] **Step 2: Rewrite user product template**

Replace the entire `resources/views/tabler/user/product.tpl` with a new layout that shows subscription cards with discount badges, and a separate bandwidth section. Hide TABP/Time tabs entirely.

The subscription cards should show:
- Product name
- Monthly price with "起" suffix
- Bandwidth (GB/月)
- Class level
- Speed limit / IP limit
- Discount badges (e.g., "季付9折", "年付8折") based on `content->discount`
- Purchase button that links to `/user/order/create?product_id={$sub->id}`
- If `$hasActiveSubscription` is true, show disabled button with "您已有活跃订阅"

The bandwidth section remains similar to current but only shows when user has an active subscription.

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/User/ProductController.php resources/views/tabler/user/product.tpl
git commit -m "feat: redesign user product page with subscription cards"
```

---

### Task 8: User Order Creation — Subscription with Billing Cycle

**Files:**
- Modify: `src/Controllers/User/OrderController.php`
- Modify: `resources/views/tabler/user/order/create.tpl`

- [ ] **Step 1: Update OrderController::create() to pass subscription data**

In `src/Controllers/User/OrderController.php`, update the `create()` method to also pass subscription-specific data to the view. After line 69 (`$product->content = json_decode($product->content);`), add:

```php
        $isSubscription = $product->type === 'subscription';
        $hasActiveSubscription = false;

        if ($isSubscription) {
            $hasActiveSubscription = (new \App\Models\Subscription())
                ->where('user_id', $this->user->id)
                ->whereIn('status', ['active', 'pending_renewal'])
                ->exists();
        }
```

Pass these to the view:
```php
                ->assign('isSubscription', $isSubscription)
                ->assign('hasActiveSubscription', $hasActiveSubscription)
```

- [ ] **Step 2: Update OrderController::process() to handle subscription type**

In the `process()` method (line 112-122), add `'subscription'` routing:

```php
    public function process(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return match ($request->getParam('type')) {
            'product' => $this->product($request, $response, $args),
            'subscription' => $this->subscription($request, $response, $args),
            'topup' => $this->topup($request, $response, $args),
            default => $response->withJson([
                'ret' => 0,
                'msg' => '未知订单类型',
            ]),
        };
    }
```

- [ ] **Step 3: Add subscription() method to OrderController**

Add a new method after the `product()` method:

```php
    public function subscription(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $couponCode = $this->antiXss->xss_clean($request->getParam('coupon'));
        $productId = $this->antiXss->xss_clean($request->getParam('product_id'));
        $billingCycle = $this->antiXss->xss_clean($request->getParam('billing_cycle'));

        $product = (new Product())->find($productId);

        if ($product === null || $product->stock === 0 || $product->type !== 'subscription') {
            return $response->withJson([
                'ret' => 0,
                'msg' => '商品不存在或库存不足',
            ]);
        }

        $user = $this->user;

        if ($user->is_shadow_banned) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '商品不存在或库存不足',
            ]);
        }

        // 检查是否已有活跃订阅
        $hasActiveSubscription = (new \App\Models\Subscription())
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'pending_renewal'])
            ->exists();

        if ($hasActiveSubscription) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '你已有活跃的订阅，无法购买新的订阅',
            ]);
        }

        $content = json_decode($product->content);

        // 验证账单周期
        if (! in_array($billingCycle, ['month', 'quarter', 'year'], true)) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '无效的账单周期',
            ]);
        }

        $cycleKey = $billingCycle;
        if (! ($content->billing_cycle->$cycleKey ?? false)) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '该套餐不支持此账单周期',
            ]);
        }

        // 计算周期价格
        $cyclePrice = \App\Services\SubscriptionService::calculateCyclePrice(
            $product->price,
            $billingCycle,
            $content
        );

        $buyPrice = $cyclePrice;
        $discount = 0;
        $couponService = null;

        // 优惠券验证
        if ($couponCode !== '') {
            $couponService = new Coupon();

            if (! $couponService->validate($couponCode, $product, $user)) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => $couponService->getError(),
                ]);
            }

            // 基于周期价格重新计算折扣
            $discount = $couponService->getDiscount();
            $buyPrice = max(0, $cyclePrice - $discount);
        }

        // 购买限制验证
        $productLimit = json_decode($product->limit);

        if ($productLimit->class_required !== '' && $user->class < (int) $productLimit->class_required) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '你的账户等级不足，无法购买此商品',
            ]);
        }

        if ($productLimit->node_group_required !== ''
            && $user->node_group !== (int) $productLimit->node_group_required) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '你所在的用户组无法购买此商品',
            ]);
        }

        if ($productLimit->new_user_required !== 0) {
            $orderCount = (new Order())->where('user_id', $user->id)->count();
            if ($orderCount > 0) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '此商品仅限新用户购买',
                ]);
            }
        }

        // 在 product_content 中记录用户选择的周期和产品名称
        $orderContent = (array) $content;
        $orderContent['billing_cycle_selected'] = $billingCycle;
        $orderContent['name'] = $product->name;

        // 创建订单
        $order = new Order();
        $order->user_id = $user->id;
        $order->product_id = $product->id;
        $order->product_type = 'subscription';
        $order->product_name = $product->name;
        $order->product_content = json_encode($orderContent);
        $order->subscription_id = null;
        $order->coupon = $couponCode;
        $order->price = $buyPrice;
        $order->status = $buyPrice === 0.0 ? 'pending_activation' : 'pending_payment';
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        // 创建账单
        $cycleName = match ($billingCycle) {
            'month' => '月付',
            'quarter' => '季付',
            'year' => '年付',
        };

        $invoiceContent = [];
        $invoiceContent[] = [
            'content_id' => 0,
            'name' => $product->name . ' (' . $cycleName . ')',
            'price' => $cyclePrice,
        ];

        if ($couponCode !== '') {
            $invoiceContent[] = [
                'content_id' => 1,
                'name' => '优惠码 ' . $couponCode,
                'price' => '-' . $discount,
            ];
        }

        $invoice = new Invoice();
        $invoice->user_id = $user->id;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode($invoiceContent);
        $invoice->price = $buyPrice;
        $invoice->status = $buyPrice === 0.0 ? 'paid_gateway' : 'unpaid';
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->pay_time = 0;
        $invoice->type = 'product';
        $invoice->save();

        if ($product->stock > 0) {
            $product->stock -= 1;
        }

        $product->sale_count += 1;
        $product->save();

        if ($couponService !== null) {
            $couponService->incrementUseCount();
        }

        return $response->withHeader('HX-Redirect', '/user/invoice/' . $invoice->id . '/view');
    }
```

- [ ] **Step 4: Add bandwidth purchase restriction in product() method**

In the `product()` method, after the shadow ban check (after line 147), add a check for bandwidth products:

```php
        if ($product->type === 'bandwidth') {
            $hasActiveSubscription = (new \App\Models\Subscription())
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            if (! $hasActiveSubscription) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '你需要先购买订阅套餐才能购买流量包',
                ]);
            }
        }
```

- [ ] **Step 5: Update order create template for subscription**

Update `resources/views/tabler/user/order/create.tpl` to show billing cycle selector when the product is a subscription. Add a radio group for month/quarter/year with calculated prices. The form should submit `type: "subscription"` and `billing_cycle: selectedValue`.

When `$hasActiveSubscription` is true, show an error message and disable the submit button.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/User/OrderController.php resources/views/tabler/user/order/create.tpl
git commit -m "feat: add subscription order creation with billing cycle selection"
```

---

### Task 9: User Subscription Management Page

**Files:**
- Create: `src/Controllers/User/SubscriptionController.php`
- Create: `resources/views/tabler/user/subscription.tpl`
- Modify: `app/routes.php`

- [ ] **Step 1: Create User SubscriptionController**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Utils\Tools;
use Carbon\Carbon;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function json_decode;

final class SubscriptionController extends BaseController
{
    /**
     * @throws Exception
     */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $subscription = (new Subscription())->where('user_id', $this->user->id)
            ->whereIn('status', ['active', 'pending_renewal'])
            ->first();

        $pendingInvoice = null;

        if ($subscription !== null) {
            $subscription->status_text = $subscription->status();
            $subscription->billing_cycle_text = $subscription->billingCycle();
            $subscription->content = json_decode($subscription->product_content);

            // 计算下次流量重置日
            $today = Carbon::today();
            $daysInMonth = $today->daysInMonth;
            $resetDay = min($subscription->reset_day, $daysInMonth);
            $nextResetDate = Carbon::create($today->year, $today->month, $resetDay);
            if ($nextResetDate->lte($today)) {
                $nextResetDate->addMonthNoOverflow();
                $resetDay = min($subscription->reset_day, $nextResetDate->daysInMonth);
                $nextResetDate->day($resetDay);
            }
            $subscription->next_reset_date = $nextResetDate->toDateString();

            // 查找待支付的续费账单
            $renewalOrder = (new Order())->where('subscription_id', $subscription->id)
                ->where('status', 'pending_payment')
                ->first();

            if ($renewalOrder !== null) {
                $pendingInvoice = (new Invoice())->where('order_id', $renewalOrder->id)
                    ->where('status', 'unpaid')
                    ->first();
            }
        }

        return $response->write(
            $this->view()
                ->assign('subscription', $subscription)
                ->assign('pendingInvoice', $pendingInvoice)
                ->fetch('user/subscription.tpl')
        );
    }
}
```

- [ ] **Step 2: Create user subscription template**

Create `resources/views/tabler/user/subscription.tpl` showing:
- If no active subscription: message to visit the shop
- If active: card with subscription details (name, cycle, bandwidth, class, dates, next reset, renewal price, next billing date)
- If pending_renewal with unpaid invoice: prominent payment button linking to `/user/invoice/{$pendingInvoice->id}/view`

- [ ] **Step 3: Add route in app/routes.php**

In `app/routes.php`, inside the `/user` group (before the product route, around line 89), add:

```php
        // 订阅管理
        $group->get('/subscription', App\Controllers\User\SubscriptionController::class . ':index');
```

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/User/SubscriptionController.php resources/views/tabler/user/subscription.tpl app/routes.php
git commit -m "feat: add user subscription management page"
```

---

### Task 10: Admin Subscription Management

**Files:**
- Create: `src/Controllers/Admin/SubscriptionController.php`
- Create: `resources/views/tabler/admin/subscription/index.tpl`
- Create: `resources/views/tabler/admin/subscription/edit.tpl`
- Modify: `app/routes.php`

- [ ] **Step 1: Create Admin SubscriptionController**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Utils\Tools;
use Carbon\Carbon;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function json_decode;
use function time;

final class SubscriptionController extends BaseController
{
    private static array $details = [
        'field' => [
            'op' => '操作',
            'id' => '订阅ID',
            'user_id' => '用户ID',
            'product_name' => '套餐名称',
            'billing_cycle' => '账单周期',
            'renewal_price' => '续费价格',
            'start_date' => '开始日期',
            'end_date' => '到期日期',
            'status' => '状态',
        ],
    ];

    /**
     * @throws Exception
     */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $response->write(
            $this->view()
                ->assign('details', self::$details)
                ->fetch('admin/subscription/index.tpl')
        );
    }

    /**
     * @throws Exception
     */
    public function edit(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $id = $args['id'];
        $subscription = (new Subscription())->find($id);

        if ($subscription === null) {
            return $response->withRedirect('/admin/subscription');
        }

        $subscription->status_text = $subscription->status();
        $subscription->billing_cycle_text = $subscription->billingCycle();
        $subscription->content = json_decode($subscription->product_content);

        $user = (new User())->find($subscription->user_id);

        return $response->write(
            $this->view()
                ->assign('subscription', $subscription)
                ->assign('user', $user)
                ->fetch('admin/subscription/edit.tpl')
        );
    }

    public function updatePrice(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $id = $args['id'];
        $subscription = (new Subscription())->find($id);

        if ($subscription === null) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '订阅不存在',
            ]);
        }

        $newPrice = (float) $request->getParam('renewal_price');

        if ($newPrice < 0) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '价格不能为负数',
            ]);
        }

        $subscription->renewal_price = $newPrice;
        $subscription->updated_at = Carbon::now()->toDateTimeString();
        $subscription->save();

        // 如果有未付款的续费账单，同步更新金额
        $unpaidOrder = (new Order())->where('subscription_id', $subscription->id)
            ->where('status', 'pending_payment')
            ->first();

        if ($unpaidOrder !== null) {
            $unpaidOrder->price = $newPrice;
            $unpaidOrder->update_time = time();
            $unpaidOrder->save();

            $invoice = (new Invoice())->where('order_id', $unpaidOrder->id)
                ->where('status', 'unpaid')
                ->first();

            if ($invoice !== null) {
                $content = json_decode($invoice->content, true);
                if (isset($content[0])) {
                    $content[0]['price'] = $newPrice;
                }
                $invoice->content = json_encode($content);
                $invoice->price = $newPrice;
                $invoice->update_time = time();
                $invoice->save();
            }
        }

        return $response->withJson([
            'ret' => 1,
            'msg' => '续费价格更新成功',
        ]);
    }

    public function cancel(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $id = $args['id'];
        $subscription = (new Subscription())->find($id);

        if ($subscription === null) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '订阅不存在',
            ]);
        }

        if ($subscription->status === 'cancelled' || $subscription->status === 'expired') {
            return $response->withJson([
                'ret' => 0,
                'msg' => '订阅已经处于终止状态',
            ]);
        }

        // 取消订阅
        $subscription->status = 'cancelled';
        $subscription->updated_at = Carbon::now()->toDateTimeString();
        $subscription->save();

        // 取消未付款的续费订单
        $pendingOrders = (new Order())->where('subscription_id', $subscription->id)
            ->whereIn('status', ['pending_payment', 'pending_activation'])
            ->get();

        foreach ($pendingOrders as $order) {
            $order->status = 'cancelled';
            $order->update_time = time();
            $order->save();

            $invoice = (new Invoice())->where('order_id', $order->id)
                ->whereIn('status', ['unpaid', 'partially_paid'])
                ->first();

            if ($invoice !== null) {
                $invoice->status = 'cancelled';
                $invoice->update_time = time();
                $invoice->save();
            }
        }

        // 降级用户
        $user = (new User())->find($subscription->user_id);
        if ($user !== null) {
            $user->class = 0;
            $user->transfer_enable = 0;
            $user->node_group = 0;
            $user->node_speedlimit = 0;
            $user->node_iplimit = 0;
            $user->u = 0;
            $user->d = 0;
            $user->transfer_today = 0;
            $user->save();
        }

        return $response->withJson([
            'ret' => 1,
            'msg' => '订阅已取消，用户已降级',
        ]);
    }

    public function ajax(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $subscriptions = (new Subscription())->orderBy('id', 'desc')->get();

        foreach ($subscriptions as $sub) {
            $content = json_decode($sub->product_content);
            $sub->product_name = $content->name ?? '订阅套餐';
            $sub->op = '<a class="btn btn-primary" href="/admin/subscription/' . $sub->id . '/edit">编辑</a>';

            if (in_array($sub->status, ['active', 'pending_renewal'])) {
                $sub->op .= ' <button class="btn btn-red" onclick="cancelSubscription(' . $sub->id . ')">取消</button>';
            }

            $sub->billing_cycle = $sub->billingCycle();
            $sub->status = $sub->status();
        }

        return $response->withJson([
            'subscriptions' => $subscriptions,
        ]);
    }
}
```

- [ ] **Step 2: Create admin subscription index template**

Create `resources/views/tabler/admin/subscription/index.tpl` — a standard admin list page with AJAX datatable showing subscription list, filter controls, and cancel button with JS handler.

- [ ] **Step 3: Create admin subscription edit template**

Create `resources/views/tabler/admin/subscription/edit.tpl` — shows subscription details (read-only) and an editable renewal_price field with save button.

- [ ] **Step 4: Add admin routes in app/routes.php**

In `app/routes.php`, inside the `/admin` group (before the product routes, around line 302), add:

```php
        // 订阅管理
        $group->get('/subscription', App\Controllers\Admin\SubscriptionController::class . ':index');
        $group->get('/subscription/{id:[0-9]+}/edit', App\Controllers\Admin\SubscriptionController::class . ':edit');
        $group->put('/subscription/{id:[0-9]+}/price', App\Controllers\Admin\SubscriptionController::class . ':updatePrice');
        $group->post('/subscription/{id:[0-9]+}/cancel', App\Controllers\Admin\SubscriptionController::class . ':cancel');
        $group->post('/subscription/ajax', App\Controllers\Admin\SubscriptionController::class . ':ajax');
```

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/Admin/SubscriptionController.php resources/views/tabler/admin/subscription/ app/routes.php
git commit -m "feat: add admin subscription management page"
```

---

### Task 11: Admin Cron Settings — Add subscription_renewal_days

**Files:**
- Modify: `resources/views/tabler/admin/setting/cron.tpl` (add input field for subscription_renewal_days)

- [ ] **Step 1: Find and update the cron settings template**

Add a form field for `subscription_renewal_days` to the cron settings page. This field is already in the config table from the migration, and the `CronController` auto-saves all fields in the `cron` class, so no controller changes needed.

The template needs a new input group:

```html
<div class="form-group mb-3 row">
    <label class="form-label col-3 col-form-label">订阅到期前X天生成续费账单</label>
    <div class="col">
        <input id="subscription_renewal_days" name="subscription_renewal_days" type="number"
               class="form-control" value="{$settings['subscription_renewal_days']}" min="1" max="30">
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/tabler/admin/setting/cron.tpl
git commit -m "feat: add subscription_renewal_days to admin cron settings"
```

---

### Task 12: Integration Verification & Edge Cases

**Files:**
- Verify all modified files work together

- [ ] **Step 1: Verify migration can be applied and rolled back**

Run: `php xcat Migration latest`
Run: `php xcat Migration rollback`
Run: `php xcat Migration latest`

- [ ] **Step 2: Verify admin can create a subscription product**

Manually test in browser:
1. Go to `/admin/product/create`
2. Select type "订阅套餐"
3. Fill in name, monthly price, bandwidth, class, etc.
4. Enable month + quarter billing cycles
5. Set quarter discount to 0.9
6. Save — should succeed

- [ ] **Step 3: Verify user can purchase subscription**

1. Go to `/user/product` — should see subscription cards with discount badges
2. Click purchase — should go to order create page with billing cycle selector
3. Select quarter, verify calculated price (monthly * 3 * 0.9)
4. Apply coupon — verify discount applied
5. Submit — should create order and redirect to invoice

- [ ] **Step 4: Verify purchase restrictions**

1. With active subscription, try to purchase another — should be blocked
2. Without active subscription, try to purchase bandwidth pack — should be blocked
3. With active subscription, purchase bandwidth pack — should succeed

- [ ] **Step 5: Verify cron lifecycle**

1. Activate subscription via cron or payment
2. Check user attributes updated correctly
3. Verify monthly reset logic with test dates
4. Verify renewal order generation at X days before expiry
5. Verify expiration downgrades user

- [ ] **Step 6: Verify admin subscription management**

1. Go to `/admin/subscription` — should show subscription list
2. Edit renewal price — should update, and if unpaid invoice exists, update that too
3. Cancel subscription — should downgrade user

- [ ] **Step 7: Final commit**

```bash
git add -A
git commit -m "feat: complete subscription system integration"
```
