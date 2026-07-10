<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Services\Stripe\PriceResolver;
use App\Services\Stripe\StripeService;
use App\Utils\Tools;
use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function ceil;
use function date;
use function in_array;
use function json_decode;
use function json_encode;
use function min;
use function round;
use function time;
use const PHP_EOL;

final class SubscriptionService
{
    /**
     * 自建引擎只处理的计费提供方（正向匹配，规避 NULL 三值逻辑）
     */
    public const SELF_MANAGED = ['manual', 'balance'];

    private static function balanceAutoRenewEnabled(): bool
    {
        return (bool) Config::obtain('balance_auto_renew_enabled');
    }

    private static function stripeAutoBillingEnabled(): bool
    {
        return (bool) Config::obtain('stripe_auto_billing_enabled');
    }

    private static function autoRenewEngineEnabled(): bool
    {
        return self::balanceAutoRenewEnabled() || self::stripeAutoBillingEnabled();
    }

    /** 计费周期中文名(邮件展示用) */
    private static function billingCycleText(string $cycle): string
    {
        return match ($cycle) {
            'month' => '月付',
            'quarter' => '季付',
            'year' => '年付',
            default => $cycle,
        };
    }

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

    /**
     * 把套餐内容(product_content)中的会员权益写入用户。
     * 共享给:新订阅激活、续期、Stripe webhook、换套餐。
     */
    public static function grantMembershipFromContent(User $user, object $content, string $classExpire): void
    {
        $user->u = 0;
        $user->d = 0;
        $user->transfer_today = 0;
        $user->transfer_enable = Tools::gbToB($content->bandwidth);
        $user->class = $content->class;
        $user->class_expire = $classExpire;
        $user->node_group = $content->node_group;
        $user->node_speedlimit = $content->speed_limit;
        $user->node_iplimit = $content->ip_limit;
        $user->save();
    }

    /**
     * 推进一个订阅到下一计费周期（自动续费成功 / 手动续费激活共用）。
     *
     * newStart = end_date + 1 天；newEnd = calculateEndDate(newStart, cycle)。
     * 更新订阅起止日、置 status='active'、清空 grace_until；用户 class_expire 对齐到
     * newEnd 23:59:59。
     *
     * 续期只推进周期与有效期，绝不在此重置流量：generateRenewalOrder 会在到期前最多
     * subscription_renewal_days（默认 7）天就生成续费账单，若在此提前重置流量，提前付款的
     * 用户会立刻获得额外带宽，且 last_reset_date 未推进会导致当周期 reset_day 再被
     * resetSubscriptionBandwidth 重置一次。本周期流量归 resetSubscriptionBandwidth 在
     * reset_day 当天唯一负责。
     */
    public static function advanceRenewedPeriod(Subscription $sub, User $user): void
    {
        $newStart = Carbon::parse($sub->end_date)->addDay();
        $newEnd = self::calculateEndDate($newStart, $sub->billing_cycle);

        $sub->start_date = $newStart->format('Y-m-d');
        $sub->end_date = $newEnd->format('Y-m-d');
        $sub->status = 'active';
        $sub->grace_until = null;
        $sub->updated_at = Carbon::now()->format('Y-m-d H:i:s');
        $sub->save();

        $user->class_expire = $newEnd->format('Y-m-d') . ' 23:59:59';
        $user->save();
    }

    /**
     * 订阅失效后降级用户：清零会员等级、套餐流量与限速等权益。
     * 由 expireSubscription（自然过期）与 terminateLapsed（宽限超时）共用。
     */
    private static function downgradeUser(User $user): void
    {
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

    /**
     * 重置订阅流量（每日执行）
     */
    public static function resetSubscriptionBandwidth(): void
    {
        $subscriptions = (new Subscription())->where('status', 'active')
            ->whereIn('billing_provider', self::SELF_MANAGED)
            ->get();
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
            $user->transfer_today = 0;
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
            ->whereIn('billing_provider', self::SELF_MANAGED)
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
                    'subscription_renewal.tpl',
                    [
                        'plan_name' => $content->name ?? null,
                        'billing_cycle_text' => self::billingCycleText($subscription->billing_cycle),
                        'amount' => $subscription->renewal_price,
                        'end_date' => $subscription->end_date,
                        'order_id' => $order->id,
                        'invoice_url' => $_ENV['baseUrl'] . '/user/invoice/' . $invoice->id . '/view',
                    ]
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

        // 如果提醒日与首次通知日相同，跳过（避免同一天发两封邮件）
        if ($reminderDays === $renewalDays) {
            echo Tools::toDateTime(time()) . ' 订阅续费二次提醒跳过（与首次通知日相同）' . PHP_EOL;
            return;
        }

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

            $content = json_decode($subscription->product_content);
            $invoice = (new Invoice())->where('order_id', $unpaidOrder->id)->first();

            try {
                Notification::notifyUser(
                    $user,
                    $_ENV['appName'] . '-订阅续费二次提醒',
                    '你好，你的订阅续费订单仍未支付，请尽快完成支付以避免服务到期后中断。',
                    'subscription_reminder.tpl',
                    [
                        'plan_name' => $content->name ?? null,
                        'billing_cycle_text' => self::billingCycleText($subscription->billing_cycle),
                        'amount' => $subscription->renewal_price,
                        'end_date' => $subscription->end_date,
                        'order_id' => $unpaidOrder->id,
                        'invoice_url' => $invoice !== null
                            ? $_ENV['baseUrl'] . '/user/invoice/' . $invoice->id . '/view'
                            : null,
                    ]
                );
            } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            echo "订阅 #{$subscription->id} 已发送二次续费提醒" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅续费二次提醒完成' . PHP_EOL;
    }

    /**
     * 续费扣款成功后的原子收尾（必须在结算事务内调用）。
     *
     * 把对应续费订单置为终态 activated，并推进订阅周期。订单一旦与账单结算落在同一次提交
     * 中变为 activated，每 5 分钟的 processPendingOrder → processRenewalActivation 激活链
     * 就再也选不中它（该链只取 pending_payment / pending_activation），从而保证“一次成功扣款
     * 只推进一个周期”（exactly-once），且不会出现“已扣款未推进”的中间态。
     */
    private static function finalizeRenewal(Subscription $sub, Invoice $invoice, User $user): void
    {
        $order = (new Order())->find($invoice->order_id);

        if ($order !== null && $order->status !== 'activated') {
            $order->status = 'activated';
            $order->update_time = time();
            $order->save();
        }

        self::advanceRenewedPeriod($sub, $user);
    }

    /**
     * 用站内余额结算一张续费账单。
     *
     * 在数据库事务内对账单加行锁后复查：仅当账单仍为 unpaid 且用户余额足额时才扣款，
     * 写 UserMoneyLog（扣款额记为负数，镜像 InvoiceController 的余额支付），并把账单标记为
     * paid_balance + pay_time。扣款成功后在“同一事务内”原子收尾（订单置 activated + 推进周期，
     * 见 finalizeRenewal）。成功返回 true，否则（账单已支付/余额不足/账单不存在）返回 false。
     */
    public static function payRenewalFromBalance(Subscription $sub, Invoice $invoice): bool
    {
        return (bool) DB::transaction(static function () use ($sub, $invoice): bool {
            $locked = (new Invoice())->where('id', $invoice->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'unpaid') {
                return false;
            }

            $user = (new User())->where('id', $locked->user_id)->lockForUpdate()->first();

            if ($user === null || (float) $user->money < (float) $locked->price) {
                return false;
            }

            $moneyBefore = (float) $user->money;
            $moneyAfter = $moneyBefore - (float) $locked->price;

            $user->money = $moneyAfter;
            $user->save();

            (new UserMoneyLog())->add(
                (int) $user->id,
                $moneyBefore,
                $moneyAfter,
                -(float) $locked->price,
                '订阅续费扣款 账单 #' . $locked->id
            );

            $locked->status = 'paid_balance';
            $locked->pay_time = time();
            $locked->update_time = time();
            $locked->save();

            // 与结算同一事务内推进，保证 exactly-once。
            self::finalizeRenewal($sub, $locked, $user);

            return true;
        });
    }

    /**
     * 续费账单被某次扣款“认领”后的过期判定（秒）。短事务把 unpaid → processing 占位；占位方在
     * processing 与 settle/revert 之间崩溃（进程被杀/部署）会遗留一行 processing。超过本阈值即视为
     * “上一次尝试已崩溃”，允许下一次以同一幂等键 renew_inv_{id} 重新认领并扣款（Stripe 去重为单笔）。
     */
    private const RENEWAL_CLAIM_STALE_SECONDS = 600;

    /**
     * 余额不足时的兜底：用 Stripe 存档卡 off-session 扣款结算续费账单。
     *
     * 关键不变量（修复 P1-4 双扣窗口）：扣款“之前”先用一笔短事务对账单加行锁“认领”——仅当账单仍为
     * unpaid 时把它置为哨兵态 processing 并落库；若已非 unpaid 一律不扣款返回 false。这样并发的手动
     * 余额支付/其它网关一旦先结算，本路径就再也认领不到、绝不触发存档卡扣款（renew_inv_{id} 幂等键
     * 只能去重两次 cron 扣款，挡不住并发手动支付，故认领是唯一正确防线）。唯一例外：processing 行
     * 若 update_time 已超过 RENEWAL_CLAIM_STALE_SECONDS（上一次认领后崩溃），允许重新认领并以同一
     * 幂等键重扣（Stripe 去重为单笔，安全）。
     *
     * 流程：先取客户默认卡（无卡直接 false，未认领、不触 FX/网络）→ 校验 billing_cycle 可推进
     * （非法周期会让 calculateEndDate 的 match 抛错；advanceRenewedPeriod 已不再读取 bandwidth，故
     * 不再要求 product_content 携带 bandwidth）→ 认领 → CNY renewal_price 经 Exchange 换成
     * stripe_currency 转最小单位、带幂等键扣款。PI status==='succeeded' → 在一个事务内对账单加锁、
     * 复核仍为 processing 后标 paid_gateway、记 subscription.stripe_amount/stripe_currency、并原子收尾
     * （订单置 activated + 推进周期，见 finalizeRenewal），返回 true。失败一律返回 false（捕获 \Throwable
     * 绝不抛出，以便上层据此进入宽限期），但 processing 认领是否回滚分两类（修复「扣款成功却被回滚」）：
     *   - 确定性失败（已知未扣款）→ 把账单从 processing 回滚为 unpaid，宽限内用户仍可自行支付：
     *     ① 在 chargeOffSession 之前抛出的任何异常（FX 抛 GuzzleException/RedisException、取卡失败）；
     *     ② Stripe 明确拒付（CardException）；③ PI 返回非 succeeded（如 requires_action）。
     *   - 模糊失败（扣款可能已成功）→ 绝不回滚，保留 processing：ApiConnectionException 或 chargeOffSession
     *     处/之后抛出的其它 ApiErrorException/\Throwable，扣款可能已在幂等键 renew_inv_{id} 下成功落地，
     *     留给 stale-processing 重认领路径稍后以同一幂等键重试（Stripe 去重为单笔），避免「已扣款却进宽限」。
     */
    public static function chargeRenewalToCard(Subscription $sub, Invoice $invoice): bool
    {
        $user = (new User())->find($sub->user_id);

        if ($user === null) {
            return false;
        }

        $stripe = StripeService::getInstance();
        $claimed = false;
        $chargeAttempted = false;

        try {
            $customerId = $stripe->ensureCustomer($user);
            $paymentMethodId = $stripe->getDefaultPaymentMethod($customerId);

            // No stored card -> bail out before claiming or any FX/charge work (invoice untouched).
            if ($paymentMethodId === null) {
                return false;
            }

            // PRE-VALIDATE before claiming/charging. advanceRenewedPeriod's calculateEndDate match
            // has no default arm, so a corrupt billing_cycle would throw UnhandledMatchError in the
            // post-charge settle = card debited, nothing recorded. Bail to false (-> grace) WITHOUT
            // touching the invoice if it cannot complete. (Fix-1 follow-up: advanceRenewedPeriod no
            // longer reads `bandwidth`, so product_content is no longer required to carry it.)
            if (! in_array($sub->billing_cycle, ['month', 'quarter', 'year'], true)) {
                return false;
            }

            // CLAIM-BEFORE-CHARGE (P1-4): a short, row-locked transaction that flips unpaid ->
            // processing so a concurrent settler (manual payBalance / another gateway) cannot slip
            // a second settlement in between this check and the off-session charge. If the invoice
            // is no longer unpaid we do NOT charge — EXCEPT a STALE 'processing' row (a prior
            // attempt that crashed before settle/revert) is re-claimable: re-charging reuses the
            // renew_inv_{id} idempotency key so Stripe dedupes to a SINGLE charge.
            $claimed = (bool) DB::transaction(static function () use ($invoice): bool {
                $locked = (new Invoice())->where('id', $invoice->id)->lockForUpdate()->first();

                if ($locked === null) {
                    return false;
                }

                $isFreshClaim = $locked->status === 'unpaid';
                $isStaleReclaim = $locked->status === 'processing'
                    && (int) $locked->update_time < time() - self::RENEWAL_CLAIM_STALE_SECONDS;

                if (! $isFreshClaim && ! $isStaleReclaim) {
                    return false;
                }

                $locked->status = 'processing';
                $locked->update_time = time();
                $locked->save();

                return true;
            });

            if (! $claimed) {
                return false;
            }

            $currency = (string) Config::obtain('stripe_currency');
            $fxAmount = Exchange::getInstance()->exchange((float) $sub->renewal_price, 'CNY', $currency);
            $amountMinor = PriceResolver::toMinorUnits((float) $fxAmount, $currency);

            // From here on a charge has been ATTEMPTED: a failure thrown at/after this point may have
            // left a SUCCEEDED PaymentIntent on Stripe's side (idempotency key renew_inv_{id}), so the
            // catch must NOT blindly revert the claim. Anything thrown BEFORE here (FX/Redis/Guzzle,
            // customer/payment-method lookup) cannot have charged, so reverting there is safe.
            $chargeAttempted = true;

            $paymentIntent = $stripe->chargeOffSession(
                $customerId,
                $paymentMethodId,
                $amountMinor,
                $currency,
                'renew_inv_' . $invoice->id,
                ['invoice_id' => (string) $invoice->id]
            );

            if ($paymentIntent->status !== 'succeeded') {
                // Not charged (or not captured) -> release the claim so the user can still pay.
                self::releaseRenewalClaim((int) $invoice->id);

                return false;
            }

            // Settle + record + claim order + advance — atomically, so the 5-min activation chain
            // can never re-select a paid renewal (exactly-once). Re-load under a row lock and only
            // settle if the invoice is still OUR 'processing' claim. If a concurrent actor already
            // settled it (e.g. the line-190 seam flips it to paid_balance mid-charge), no-op here:
            // that actor owns the advance, so we neither re-record the charge nor double-advance.
            DB::transaction(static function () use ($sub, $invoice, $user, $amountMinor, $currency): void {
                $locked = (new Invoice())->where('id', $invoice->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== 'processing') {
                    return;
                }

                $locked->status = 'paid_gateway';
                $locked->pay_time = time();
                $locked->update_time = time();
                $locked->save();

                $sub->stripe_amount = $amountMinor;
                $sub->stripe_currency = $currency;
                $sub->updated_at = Carbon::now()->format('Y-m-d H:i:s');
                $sub->save();

                self::finalizeRenewal($sub, $locked, $user);
            });

            return true;
        } catch (\Throwable $e) {
            // Release the claim (processing -> unpaid) ONLY when we KNOW no charge stands, so the
            // renewal stays payable as the waterfall falls through to grace. That is true in exactly
            // two cases:
            //   1. the failure happened BEFORE the charge was attempted (FX/Redis/Guzzle, customer or
            //      payment-method lookup) — no PaymentIntent was ever created; or
            //   2. Stripe DEFINITIVELY declined the card (CardException) — the charge did not go through.
            // For an AMBIGUOUS post-attempt failure — an ApiConnectionException, or any other
            // ApiErrorException/\Throwable thrown at/after chargeOffSession — the charge MAY have
            // succeeded under the renew_inv_{id} idempotency key, so we must NOT revert: leaving the
            // invoice 'processing' lets the stale-processing reclaim path retry later under the SAME
            // key (Stripe dedupes to a single charge) instead of entering grace on a charged renewal.
            if ($claimed && (! $chargeAttempted || $e instanceof \Stripe\Exception\CardException)) {
                self::releaseRenewalClaim((int) $invoice->id);
            }

            echo $e->getMessage() . PHP_EOL;

            return false;
        }
    }

    /**
     * 回滚一次失败的存档卡扣款认领：在事务内对账单加行锁，仅当它仍为本次认领留下的 processing 时
     * 回退为 unpaid（宽限内用户仍可自行支付）。回滚自身的异常一律吞掉——它运行在失败/异常收尾路径，
     * 绝不可二次抛出连累上层瀑布。
     */
    private static function releaseRenewalClaim(int $invoiceId): void
    {
        try {
            DB::transaction(static function () use ($invoiceId): void {
                $locked = (new Invoice())->where('id', $invoiceId)->lockForUpdate()->first();

                if ($locked !== null && $locked->status === 'processing') {
                    $locked->status = 'unpaid';
                    $locked->update_time = time();
                    $locked->save();
                }
            });
        } catch (\Throwable $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * 余额与存档卡都失败时进入 N 天宽限期（服务不断）。
     *
     * 先把状态落库：grace_until = end_date + grace_days（默认 3，'Y-m-d H:i:s'）、订阅置
     * pending_renewal、用户 class_expire 延到 grace_until 保活——状态变更绝不被发信影响。
     * 状态保存后再尝试发送续费失败邮件（正文附上本订阅未支付续费账单的支付链接，找不到账单时
     * 优雅省略），模板缺失/渠道异常一律被 try/catch 吞掉。
     */
    public static function enterGrace(Subscription $sub, User $user): void
    {
        $graceDays = (int) Config::obtain('stripe_grace_days');

        if ($graceDays <= 0) {
            $graceDays = 3;
        }

        $graceUntil = Carbon::parse($sub->end_date)->addDays($graceDays)->format('Y-m-d H:i:s');

        // STATE FIRST: persist grace before attempting any notification.
        $sub->status = 'pending_renewal';
        $sub->grace_until = $graceUntil;
        $sub->updated_at = Carbon::now()->format('Y-m-d H:i:s');
        $sub->save();

        // 保活：把会员有效期延到宽限截止，不降级。
        $user->class_expire = $graceUntil;
        $user->save();

        // 查找本订阅当前未支付的续费账单，给邮件正文附上支付链接，引导用户在宽限期内完成续费。
        // 纯只读查询，绝不影响上面已落库的宽限状态；订单/账单缺失时优雅省略链接。
        $payUrl = null;
        $renewalOrder = (new Order())
            ->where('subscription_id', $sub->id)
            ->where('product_type', 'subscription')
            ->whereNotIn('status', ['cancelled', 'expired', 'activated'])
            ->orderByDesc('id')
            ->first();

        if ($renewalOrder !== null) {
            $unpaidInvoice = (new Invoice())
                ->where('order_id', $renewalOrder->id)
                ->where('status', 'unpaid')
                ->first();

            if ($unpaidInvoice !== null) {
                $payUrl = $_ENV['baseUrl'] . '/user/invoice/' . $unpaidInvoice->id . '/view';
            }
        }

        $msg = '你好，本次自动续费扣款未能成功。为避免服务中断，已为你延长 ' . $graceDays
            . ' 天宽限期，服务将持续至 ' . $graceUntil . '。';
        $msg .= $payUrl !== null
            ? '请在宽限期内 <a href="' . $payUrl . '">完成续费支付</a>，逾期订阅将到期失效。'
            : '请在宽限期内登录账户完成续费支付，逾期订阅将到期失效。';

        try {
            Notification::notifyUser(
                $user,
                $_ENV['appName'] . '-订阅续费失败',
                $msg,
                'subscription_renewal_failed.tpl',
                [
                    'plan_name' => json_decode($sub->product_content)->name ?? null,
                    'billing_cycle_text' => self::billingCycleText($sub->billing_cycle),
                    'amount' => $sub->renewal_price,
                    'grace_until' => $graceUntil,
                    'invoice_url' => $payUrl,
                ]
            );
        } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * 自动续费瀑布主流程（每日执行）。
     *
     * 选取自管(SELF_MANAGED)、auto_renew=1、已到期(end_date <= today，'<=' 兼容漏跑当日)、
     * 状态为 active/pending_renewal 且存在「可处理」续费账单(unpaid / partially_paid / 陈旧 processing，
     * 排除新鲜 processing)的订阅；对每个依次尝试：
     *   1) 若余额自动续费开启，尝试余额扣款成功 → 结算并原子推进周期
     *   2) 若 Stripe 自动扣费开启，尝试存档卡扣款成功 → 结算并原子推进周期
     *   3) 都失败 → 进入宽限期
     * 扣款方法在“结算的同一事务内”把续费订单置 activated 并 advanceRenewedPeriod 推进
     * （见 finalizeRenewal），因此本方法成功分支无需再动订单/周期。每个订阅各自包裹 try/catch：
     * 单个订阅的意外异常只记录并 continue，绝不连累整批每日任务。
     */
    public static function processAutoRenew(): void
    {
        $balanceEnabled = self::balanceAutoRenewEnabled();
        $stripeEnabled = self::stripeAutoBillingEnabled();

        // 两条扣款腿都关闭时整条自动续费引擎熔断：不扣余额、不走存档卡、不进宽限。
        // 到期的 auto_renew=1 订阅改由 expireSubscription 一并自然过期兜底，不会被卡死。
        if (! $balanceEnabled && ! $stripeEnabled) {
            echo Tools::toDateTime(time()) . ' 自动续费扣款方式均已关闭，跳过自动续费处理' . PHP_EOL;
            return;
        }

        $today = Carbon::today()->format('Y-m-d');

        $subscriptions = (new Subscription())
            ->whereIn('status', ['active', 'pending_renewal'])
            ->where('end_date', '<=', $today)
            ->where('auto_renew', 1)
            ->whereIn('billing_provider', self::SELF_MANAGED)
            // D7 (宽限内不自动重试)/D8 (single failure email): a sub already IN its grace window
            // (grace_until set to a future datetime by enterGrace) must NOT be re-selected on the
            // daily run, or it re-runs the whole waterfall + re-sends the failure email every day.
            // advanceRenewedPeriod clears grace_until=null on success; terminateLapsed owns
            // grace_until < now. A freshly-due sub has grace_until=NULL, so missed-cron still works.
            ->whereNull('grace_until')
            ->orderBy('id')
            ->get();

        foreach ($subscriptions as $subscription) {
            try {
                $user = (new User())->find($subscription->user_id);

                if ($user === null) {
                    continue;
                }

                // 该订阅当前未取消/未过期/未激活的续费订单
                $order = (new Order())
                    ->where('subscription_id', $subscription->id)
                    ->where('product_type', 'subscription')
                    ->whereNotIn('status', ['cancelled', 'expired', 'activated'])
                    ->orderByDesc('id')
                    ->first();

                if ($order === null) {
                    continue;
                }

                // 订单对应的「可处理」续费账单：unpaid（正常瀑布）/ partially_paid（部分付款后两条腿都
                // 要求 unpaid 故必失败 → 进宽限 → terminateLapsed 终止）/ 陈旧 processing（上一次认领后
                // 在 settle 前崩溃，update_time 已超过 RENEWAL_CLAIM_STALE_SECONDS → chargeRenewalToCard
                // 重认领并以同一幂等键重扣）。但要 EXCLUDE 新鲜 processing（另一并发 worker 正在扣款，归其所有）。
                $staleBefore = time() - self::RENEWAL_CLAIM_STALE_SECONDS;

                $invoice = (new Invoice())
                    ->where('order_id', $order->id)
                    ->where(static function ($query) use ($staleBefore): void {
                        $query->whereIn('status', ['unpaid', 'partially_paid'])
                            ->orWhere(static function ($sub) use ($staleBefore): void {
                                $sub->where('status', 'processing')
                                    ->where('update_time', '<', $staleBefore);
                            });
                    })
                    ->first();

                if ($invoice === null) {
                    continue;
                }

                // 瀑布：按管理员开关决定可用扣款腿。余额优先，不足再走存档卡。两者均在
                // 结算事务内原子完成订单 activated + 推进周期（exactly-once）。
                $renewed = false;

                if ($balanceEnabled) {
                    $renewed = self::payRenewalFromBalance($subscription, $invoice);
                }

                if (! $renewed && $stripeEnabled) {
                    $renewed = self::chargeRenewalToCard($subscription, $invoice);
                }

                if ($renewed) {
                    echo "订阅 #{$subscription->id} 自动续费成功" . PHP_EOL;
                    continue;
                }

                // 余额与存档卡都失败 → 进入宽限期。
                self::enterGrace($subscription, $user);
                echo "订阅 #{$subscription->id} 自动续费失败，进入宽限期" . PHP_EOL;
            } catch (\Throwable $e) {
                // 单个订阅出错不得中断整批：记录并继续下一个。
                echo "订阅 #{$subscription->id} 自动续费异常：" . $e->getMessage() . PHP_EOL;

                continue;
            }
        }

        echo Tools::toDateTime(time()) . ' 订阅自动续费处理完成' . PHP_EOL;
    }

    /**
     * 自然过期处理（每日执行）。
     *
     * 处理已到期(end_date <= today，'<=' 兼容漏跑当日)的自管 pending_renewal 订阅：
     *   - 任一扣款腿开启：仅处理用户已取消自动续费(auto_renew=0)的订阅；auto_renew=1 的到期订阅交由
     *     processAutoRenew / 宽限期 / terminateLapsed 处理。
     *   - 两条扣款腿都关闭：processAutoRenew 已熔断，故 auto_renew=1 的到期订阅若不在此兜底会被
     *     永久卡死。此时放开 auto_renew 过滤，连同 auto_renew=1 一并自然过期，避免管理员关闭功能后
     *     遗留一批悬空订阅。
     */
    public static function expireSubscription(): void
    {
        $today = Carbon::today()->format('Y-m-d');

        $query = (new Subscription())->where('status', 'pending_renewal')
            ->where('end_date', '<=', $today)
            ->whereIn('billing_provider', self::SELF_MANAGED);

        // 任一扣款腿开启时只过期 auto_renew=0；全部关闭时不加 auto_renew 过滤。
        if (self::autoRenewEngineEnabled()) {
            $query->where('auto_renew', 0);
        }

        $subscriptions = $query->get();

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
                self::downgradeUser($user);

                // 发送过期通知
                try {
                    Notification::notifyUser(
                        $user,
                        $_ENV['appName'] . '-订阅已过期',
                        '你好，你的订阅已过期，账户服务已被停止。如需继续使用，请重新购买订阅。',
                        'subscription_expired.tpl',
                        [
                            'plan_name' => json_decode($subscription->product_content)->name ?? null,
                            'end_date' => $subscription->end_date,
                        ]
                    );
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }
            }

            echo "订阅 #{$subscription->id} 已过期" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 订阅过期处理完成' . PHP_EOL;
    }

    /**
     * 宽限期超时终止（每日执行）。
     *
     * 处理 auto_renew=1、自管、状态 pending_renewal、grace_until 已过且续费账单仍 unpaid 的
     * 订阅：把续费订单与账单置 status='cancelled'、订阅置 expired、降级用户、发送“已失效”邮件。
     * 作废后该账单不再可支付由 InvoiceController::payBalance 的顶部守卫强制保证（仅 status==='unpaid'
     * 的账单可走余额支付，cancelled 一律拒绝、不扣余额）。若账单已在宽限内被付掉(paid_*)则跳过，
     * 交由正常激活链处理。
     */
    public static function terminateLapsed(): void
    {
        $now = Carbon::now()->format('Y-m-d H:i:s');

        $subscriptions = (new Subscription())->where('status', 'pending_renewal')
            ->where('auto_renew', 1)
            ->whereIn('billing_provider', self::SELF_MANAGED)
            ->whereNotNull('grace_until')
            ->where('grace_until', '<', $now)
            ->get();

        foreach ($subscriptions as $subscription) {
            $order = (new Order())
                ->where('subscription_id', $subscription->id)
                ->where('product_type', 'subscription')
                ->whereNotIn('status', ['cancelled', 'expired', 'activated'])
                ->orderByDesc('id')
                ->first();

            if ($order === null) {
                continue;
            }

            $invoice = (new Invoice())->where('order_id', $order->id)->first();

            // 宽限内已“足额”支付 → 跳过，留给正常激活链（这些订阅已是 active，不在本选择器内）。
            // unpaid 与 partially_paid 均视为仍欠费：partially_paid 也必须终止，否则部分付款即可
            // 永久白嫖服务；按站点不退款政策，已付部分作废。
            if ($invoice === null || ! in_array($invoice->status, ['unpaid', 'partially_paid'], true)) {
                continue;
            }

            // 作废订单与账单；不可再支付由 InvoiceController::payBalance 的状态守卫强制保证。
            $order->status = 'cancelled';
            $order->update_time = time();
            $order->save();

            $invoice->status = 'cancelled';
            $invoice->update_time = time();
            $invoice->save();

            // 订阅过期
            $subscription->status = 'expired';
            $subscription->updated_at = Carbon::now()->format('Y-m-d H:i:s');
            $subscription->save();

            // 降级用户
            $user = (new User())->find($subscription->user_id);

            if ($user !== null) {
                self::downgradeUser($user);

                try {
                    Notification::notifyUser(
                        $user,
                        $_ENV['appName'] . '-订阅已过期',
                        '你好，你的订阅已超过宽限期仍未完成续费，账户服务已被停止。如需继续使用，请重新购买订阅。',
                        'subscription_expired.tpl',
                        [
                            'plan_name' => json_decode($subscription->product_content)->name ?? null,
                            'end_date' => $subscription->end_date,
                        ]
                    );
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }
            }

            echo "订阅 #{$subscription->id} 宽限期已过，已终止" . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 宽限期终止处理完成' . PHP_EOL;
    }
}
