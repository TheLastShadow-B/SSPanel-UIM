<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserMoneyLog;
use Carbon\Carbon;
use Throwable;
use function in_array;
use function json_decode;
use function time;

/**
 * 支付完成后即时激活订单(幂等)。
 *
 * 由支付网关回调(Gateway/Base::postPayment)、余额支付路径与 5 分钟 cron 兜底共用:
 * 激活在「订单行锁 + 状态复查」事务里进行,重复/并发调用只成功一次。仅处理自管
 * 订单类型(topup / subscription 新购 / subscription 续费);tabp、bandwidth、time
 * 遗留商店类型返回 false,留给 cron 原有循环。事务内保持静默(web 请求路径公用,
 * 不得 echo);开通/续费成功的用户通知由 tryActivate 在事务提交后尽力而为地发出。
 */
final class OrderActivation
{
    /**
     * 尝试激活一个订单。true = 本次调用完成激活;false = 无需/无法激活
     * (未支付、已激活、类型不支持或被业务门槛拦下,留给 cron 兜底)。
     */
    public static function tryActivate(int $orderId): bool
    {
        /** @var array{user: User, title: string, text: string, template: string, extra: array}|null $notification */
        $notification = null;

        $activated = (bool) DB::transaction(static function () use ($orderId, &$notification): bool {
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
                $order->product_type === 'subscription' && $order->subscription_id === null
                    => self::activateNewSubscription($order, $notification),
                $order->product_type === 'subscription' && $order->subscription_id !== null
                    => self::activateRenewal($order, $notification),
                default => false,
            };
        });

        // 通知在激活事务提交之后才发:IM 分支是同步网络调用,不能拖在事务里拉长锁;
        // 通知任何失败(队列写入/IM 网络)都不得影响已完成的激活结果,静默吞掉。
        if ($activated && $notification !== null) {
            try {
                Notification::notifyUser(
                    $notification['user'],
                    $notification['title'],
                    $notification['text'],
                    $notification['template'],
                    $notification['extra']
                );
            } catch (Throwable) {
                // 尽力而为:激活已成功,通知丢失可接受
            }
        }

        return $activated;
    }

    /**
     * tryActivate 的 web 安全版:吞掉一切激活异常。支付已入账的请求绝不能因激活
     * 失败而 500(事务已回滚,订单留在 cron 可处理状态,体验退回「等 cron」)。
     */
    public static function tryActivateQuietly(int $orderId): bool
    {
        try {
            return self::tryActivate($orderId);
        } catch (Throwable) {
            return false;
        }
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

    /**
     * 新购订阅激活:建 Subscription(auto_renew=1 opt-out)+ 发放会员权益。
     * 已有 active/pending_renewal 订阅时不重复开通,留给 cron 在旧订阅结束后处理。
     * 激活成功时填充 $notification(开通成功邮件),由 tryActivate 在事务提交后发出。
     */
    private static function activateNewSubscription(Order $order, ?array &$notification): bool
    {
        if (! in_array($order->billing_provider, SubscriptionService::SELF_MANAGED, true)) {
            return false;
        }

        $user = (new User())->where('id', $order->user_id)->lockForUpdate()->first();

        if ($user === null) {
            return false;
        }

        // 行锁当前读:普通一致性读在 REPEATABLE READ 下用的是事务快照,两笔并发结算
        // 可能互相看不见对方刚提交的订阅,给同一用户开出两个 active 订阅。
        $existing = (new Subscription())
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'pending_renewal'])
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return false;
        }

        $content = json_decode($order->product_content);

        if (! is_object($content)) {
            return false;
        }

        $billingCycle = $content->billing_cycle_selected ?? null;

        // 内容缺失/非法计费周期:不激活也不抛异常 —— 抛出会让网关回调 500 进入重试风暴
        // (账单此刻已标记已付),cron 走同一入口也会同样崩溃。返回 false 让订单留在
        // pending_activation,cron 日志每轮可见「本轮未激活」,交由人工修数据。
        if (! in_array($billingCycle, ['month', 'quarter', 'year'], true)) {
            return false;
        }

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

        $notification = [
            'user' => $user,
            'title' => $_ENV['appName'] . '-订阅开通成功',
            'text' => '你好，你的订阅已开通并立即生效，以下是订阅详情。',
            'template' => 'subscription_activated.tpl',
            'extra' => [
                'plan_name' => $content->name ?? null,
                'billing_cycle_text' => SubscriptionService::billingCycleText($billingCycle),
                'amount' => $order->price,
                'start_date' => $today->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
        ];

        return true;
    }

    /**
     * 续费订单激活:推进订阅周期(newStart = end_date + 1 天,提前付款不吃亏)。
     * 流量重置归 resetSubscriptionBandwidth 在 reset_day 负责,此处绝不重置。
     * 激活成功时填充 $notification(续费成功邮件),由 tryActivate 在事务提交后发出。
     */
    private static function activateRenewal(Order $order, ?array &$notification): bool
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

        // advanceRenewedPeriod 已把 $subscription 推进到新周期,载荷里的 end_date 即新到期日。
        $paymentMethodText = match ((new Invoice())->where('order_id', $order->id)->first()?->status) {
            'paid_balance' => '账户余额',
            'paid_gateway' => '在线支付',
            'paid_admin' => '管理员操作',
            default => null,
        };
        $notification = ['user' => $user]
            + SubscriptionService::renewalSuccessMailPayload($subscription, $paymentMethodText);

        return true;
    }
}
