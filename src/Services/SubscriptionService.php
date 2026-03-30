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
use function ceil;
use function date;
use function json_decode;
use function json_encode;
use function min;
use function round;
use function time;
use const PHP_EOL;

final class SubscriptionService
{
    /**
     * 计算订阅结束日期
     */
    public static function calculateEndDate(Carbon $startDate, string $billingCycle): Carbon
    {
        return match ($billingCycle) {
            'month' => $startDate->copy()->addMonthsNoOverflow(1)->subDay(),
            'quarter' => $startDate->copy()->addMonthsNoOverflow(3)->subDay(),
            'year' => $startDate->copy()->addMonthsNoOverflow(12)->subDay(),
        };
    }

    /**
     * 计算账单周期价格
     */
    public static function calculateCyclePrice(float $monthlyPrice, string $billingCycle, object $content): float
    {
        $price = match ($billingCycle) {
            'month' => $monthlyPrice * 1 * 1.0,
            'quarter' => $monthlyPrice * 3 * ($content->discount->quarter ?? 1.0),
            'year' => $monthlyPrice * 12 * ($content->discount->year ?? 1.0),
            default => $monthlyPrice,
        };

        return round($price, 2);
    }

    /**
     * 处理新订阅激活（每5分钟执行）
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
                echo "订阅订单 #{$order->id} 用户不存在，已跳过" . PHP_EOL;
                continue;
            }

            // 检查用户是否已有活跃或待续费的订阅
            $existingSubscription = (new Subscription())
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'pending_renewal'])
                ->first();

            if ($existingSubscription !== null) {
                echo "用户 #{$user->id} 已有活跃/待续费订阅，跳过订单 #{$order->id}" . PHP_EOL;
                continue;
            }

            $content = json_decode($order->product_content);
            $billingCycle = $content->billing_cycle_selected;
            $today = Carbon::today();
            $endDate = self::calculateEndDate($today, $billingCycle);

            // 创建订阅记录
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
            $subscription->created_at = $today->format('Y-m-d H:i:s');
            $subscription->updated_at = $today->format('Y-m-d H:i:s');
            $subscription->save();

            // 更新用户信息
            $user->u = 0;
            $user->d = 0;
            $user->transfer_today = 0;
            $user->transfer_enable = Tools::gbToB($content->bandwidth);
            $user->class = $content->class;
            $user->class_expire = $endDate->format('Y-m-d') . ' 23:59:59';
            $user->node_group = $content->node_group;
            $user->node_speedlimit = $content->speed_limit;
            $user->node_iplimit = $content->ip_limit;
            $user->save();

            // 更新订单状态
            $order->status = 'activated';
            $order->update_time = time();
            $order->save();

            echo "订阅订单 #{$order->id} 已激活，创建订阅 #{$subscription->id}" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 新订阅激活处理完成' . PHP_EOL;
    }

    /**
     * 处理续费订阅激活（每5分钟执行）
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
                echo "续费订单 #{$order->id} 关联订阅不存在，已跳过" . PHP_EOL;
                continue;
            }

            $user = (new User())->find($order->user_id);

            if ($user === null) {
                echo "续费订单 #{$order->id} 用户不存在，已跳过" . PHP_EOL;
                continue;
            }

            // 计算新的日期
            $newStart = Carbon::parse($subscription->end_date)->addDay();
            $newEnd = self::calculateEndDate($newStart, $subscription->billing_cycle);

            // 更新订阅
            $subscription->start_date = $newStart->format('Y-m-d');
            $subscription->end_date = $newEnd->format('Y-m-d');
            $subscription->status = 'active';
            $subscription->updated_at = Carbon::now()->format('Y-m-d H:i:s');
            $subscription->save();

            // 更新用户 class_expire
            $user->class_expire = $newEnd->format('Y-m-d') . ' 23:59:59';
            $user->save();

            // 更新订单状态
            $order->status = 'activated';
            $order->update_time = time();
            $order->save();

            echo "续费订单 #{$order->id} 已激活，订阅 #{$subscription->id} 已续期" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 续费订阅激活处理完成' . PHP_EOL;
    }

    /**
     * 重置订阅流量（每日执行）
     */
    public static function resetSubscriptionBandwidth(): void
    {
        $subscriptions = (new Subscription())->where('status', 'active')->get();
        $today = Carbon::today();

        foreach ($subscriptions as $subscription) {
            $daysInMonth = (int) $today->format('t');
            $resetDay = min($subscription->reset_day, $daysInMonth);

            if ((int) $today->format('d') !== $resetDay) {
                continue;
            }

            $lastReset = Carbon::parse($subscription->last_reset_date);

            if ($lastReset->month === $today->month && $lastReset->year === $today->year) {
                continue;
            }

            $user = (new User())->find($subscription->user_id);

            if ($user === null) {
                continue;
            }

            $content = json_decode($subscription->product_content);

            $user->u = 0;
            $user->d = 0;
            $user->transfer_enable = Tools::gbToB($content->bandwidth);
            $user->save();

            $subscription->last_reset_date = $today->format('Y-m-d');
            $subscription->updated_at = Carbon::now()->format('Y-m-d H:i:s');
            $subscription->save();

            echo "订阅 #{$subscription->id} 用户 #{$user->id} 流量已重置" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅流量重置完成' . PHP_EOL;
    }

    /**
     * 生成续费订单（每日执行）
     */
    public static function generateRenewalOrder(): void
    {
        $renewalDays = (int) Config::obtain('subscription_renewal_days');
        $targetDate = Carbon::today()->addDays($renewalDays)->format('Y-m-d');

        $subscriptions = (new Subscription())->where('status', 'active')
            ->where('end_date', $targetDate)
            ->get();

        foreach ($subscriptions as $subscription) {
            // 检查是否已有未取消/未过期/未激活的续费订单
            $existingOrder = (new Order())
                ->where('subscription_id', $subscription->id)
                ->where('product_type', 'subscription')
                ->whereNotIn('status', ['cancelled', 'expired', 'activated'])
                ->first();

            if ($existingOrder !== null) {
                echo "订阅 #{$subscription->id} 已有续费订单，已跳过" . PHP_EOL;
                continue;
            }

            $user = (new User())->find($subscription->user_id);

            if ($user === null) {
                continue;
            }

            $content = json_decode($subscription->product_content);

            // 创建续费订单
            $order = new Order();
            $order->user_id = $subscription->user_id;
            $order->product_id = $subscription->product_id;
            $order->product_type = 'subscription';
            $order->product_name = $content->name ?? '';
            $order->product_content = $subscription->product_content;
            $order->subscription_id = $subscription->id;
            $order->coupon = '';
            $order->price = $subscription->renewal_price;
            $order->status = 'pending_payment';
            $order->create_time = time();
            $order->update_time = time();
            $order->save();

            // 创建账单
            $invoice = new Invoice();
            $invoice->type = 'product';
            $invoice->user_id = $subscription->user_id;
            $invoice->order_id = $order->id;
            $invoice->content = json_encode([
                [
                    'content_id' => 0,
                    'name' => $content->name ?? '',
                    'price' => $subscription->renewal_price,
                ],
            ]);
            $invoice->price = $subscription->renewal_price;
            $invoice->status = 'unpaid';
            $invoice->create_time = time();
            $invoice->update_time = time();
            $invoice->save();

            // 更新订阅状态
            $subscription->status = 'pending_renewal';
            $subscription->updated_at = Carbon::now()->format('Y-m-d H:i:s');
            $subscription->save();

            // 发送续费通知
            try {
                Notification::notifyUser(
                    $user,
                    $_ENV['appName'] . '-订阅续费提醒',
                    '你好，你的订阅即将到期，系统已为你生成续费订单，请及时支付以避免服务中断。',
                    'subscription_renewal.tpl'
                );
            } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            echo "订阅 #{$subscription->id} 已生成续费订单 #{$order->id}" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 续费订单生成完成' . PHP_EOL;
    }

    /**
     * 发送第二次续费提醒（每日执行）
     */
    public static function sendSecondRenewalNotification(): void
    {
        $renewalDays = (int) Config::obtain('subscription_renewal_days');
        $reminderDays = (int) ceil($renewalDays / 2);
        $targetDate = Carbon::today()->addDays($reminderDays)->format('Y-m-d');

        $subscriptions = (new Subscription())->where('status', 'pending_renewal')
            ->where('end_date', $targetDate)
            ->get();

        foreach ($subscriptions as $subscription) {
            // 检查是否有未支付的续费订单
            $unpaidOrder = (new Order())
                ->where('subscription_id', $subscription->id)
                ->where('product_type', 'subscription')
                ->where('status', 'pending_payment')
                ->first();

            if ($unpaidOrder === null) {
                continue;
            }

            $user = (new User())->find($subscription->user_id);

            if ($user === null) {
                continue;
            }

            try {
                Notification::notifyUser(
                    $user,
                    $_ENV['appName'] . '-订阅续费二次提醒',
                    '你好，你的订阅续费订单仍未支付，请尽快完成支付以避免服务到期后中断。',
                    'subscription_reminder.tpl'
                );
            } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            echo "订阅 #{$subscription->id} 已发送二次续费提醒" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅续费二次提醒完成' . PHP_EOL;
    }

    /**
     * 过期订阅处理（每日执行）
     */
    public static function expireSubscription(): void
    {
        $today = Carbon::today()->format('Y-m-d');

        $subscriptions = (new Subscription())->where('status', 'pending_renewal')
            ->where('end_date', $today)
            ->get();

        foreach ($subscriptions as $subscription) {
            // 检查续费订单是否仍未支付
            $unpaidOrder = (new Order())
                ->where('subscription_id', $subscription->id)
                ->where('product_type', 'subscription')
                ->where('status', 'pending_payment')
                ->first();

            if ($unpaidOrder !== null) {
                // 取消未支付的订单和账单
                $unpaidOrder->status = 'cancelled';
                $unpaidOrder->update_time = time();
                $unpaidOrder->save();

                $invoice = (new Invoice())->where('order_id', $unpaidOrder->id)->first();

                if ($invoice !== null) {
                    $invoice->status = 'cancelled';
                    $invoice->update_time = time();
                    $invoice->save();
                }

                echo "已取消订阅 #{$subscription->id} 的未支付订单 #{$unpaidOrder->id}" . PHP_EOL;
            }

            // 设置订阅状态为过期
            $subscription->status = 'expired';
            $subscription->updated_at = Carbon::now()->format('Y-m-d H:i:s');
            $subscription->save();

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

                // 发送过期通知
                try {
                    Notification::notifyUser(
                        $user,
                        $_ENV['appName'] . '-订阅已过期',
                        '你好，你的订阅已过期，账户服务已被停止。如需继续使用，请重新购买订阅。',
                        'subscription_expired.tpl'
                    );
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }
            }

            echo "订阅 #{$subscription->id} 已过期" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅过期处理完成' . PHP_EOL;
    }
}
