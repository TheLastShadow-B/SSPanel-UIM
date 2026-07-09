<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserMoneyLog;
use Carbon\Carbon;
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
                $order->product_type === 'subscription' && $order->subscription_id === null
                    => self::activateNewSubscription($order),
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
}
