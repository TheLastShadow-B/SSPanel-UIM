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
            ->whereIn('billing_provider', self::SELF_MANAGED)
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
            $subscription->billing_provider = 'manual';
            $subscription->created_at = $today->format('Y-m-d H:i:s');
            $subscription->updated_at = $today->format('Y-m-d H:i:s');
            $subscription->save();

            // 更新用户信息
            self::grantMembershipFromContent($user, $content, $endDate->format('Y-m-d') . ' 23:59:59');

            // 更新订单状态
            $order->status = 'activated';
            $order->update_time = time();
            $order->save();

            echo "订阅订单 #{$order->id} 已激活，创建订阅 #{$subscription->id}" . PHP_EOL;
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
     * newEnd 23:59:59，并按 product_content 重置本周期流量（u=d=transfer_today=0,
     * transfer_enable=gbToB(bandwidth)）。
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

        $content = json_decode($sub->product_content);

        $user->u = 0;
        $user->d = 0;
        $user->transfer_today = 0;
        $user->transfer_enable = Tools::gbToB($content->bandwidth);
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
     * 处理续费订阅激活（每5分钟执行）
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

            // 推进周期、对齐 class_expire 并按套餐重置流量（与自动续费共用 DRY）
            self::advanceRenewedPeriod($subscription, $user);

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
     * 余额不足时的兜底：用 Stripe 存档卡 off-session 扣款结算续费账单。
     *
     * 先取客户默认卡：无卡直接返回 false（此分支在 FX 换算之前，不触 Redis/网络）。有卡则在
     * 扣款“之前”重读账单一次：若已非 unpaid（并发被结算）则直接返回 false 不扣款——幂等键无法
     * 防住此种重复扣款。随后把 CNY renewal_price 经 Exchange 换成 stripe_currency 再转最小单位，
     * 带幂等键 renew_inv_{invoiceId} 扣款。PI status==='succeeded' → 在一个事务内把账单标
     * paid_gateway、记 subscription.stripe_amount/stripe_currency、并原子收尾（订单置 activated +
     * 推进周期，见 finalizeRenewal），返回 true。任何失败（卡被拒、Stripe API、FX 抛
     * GuzzleException/RedisException 等）一律按“未扣款 → false”处理：捕获 \Throwable，绝不抛出，
     * 以便上层据此进入宽限期。
     */
    public static function chargeRenewalToCard(Subscription $sub, Invoice $invoice): bool
    {
        $user = (new User())->find($sub->user_id);

        if ($user === null) {
            return false;
        }

        $stripe = StripeService::getInstance();

        try {
            $customerId = $stripe->ensureCustomer($user);
            $paymentMethodId = $stripe->getDefaultPaymentMethod($customerId);

            // No stored card -> bail out before any FX/charge work.
            if ($paymentMethodId === null) {
                return false;
            }

            // Re-load fresh right before charging: a concurrent actor may have settled
            // this invoice (e.g. the user paid it manually). The idempotency key does
            // NOT protect this case, so refuse to charge anything that is no longer unpaid.
            $fresh = (new Invoice())->find($invoice->id);

            if ($fresh === null || $fresh->status !== 'unpaid') {
                return false;
            }

            $currency = (string) Config::obtain('stripe_currency');
            $fxAmount = Exchange::getInstance()->exchange((float) $sub->renewal_price, 'CNY', $currency);
            $amountMinor = PriceResolver::toMinorUnits((float) $fxAmount, $currency);

            // PRE-VALIDATE before the irreversible off-session charge. chargeOffSession runs
            // BEFORE the DB transaction that records the charge + advances the period; if that
            // transaction were to throw DETERMINISTICALLY the card would be debited yet nothing
            // recorded (invoice stays unpaid -> grace) = customer charged for no service. The two
            // known deterministic faults: a corrupt billing_cycle makes advanceRenewedPeriod's
            // calculateEndDate match throw UnhandledMatchError; a product_content that does not
            // decode to an object carrying `bandwidth` breaks the membership/quota reset. Verify
            // the renewal can complete and bail to false (-> grace) WITHOUT charging if it cannot.
            // (Transient DB faults remain safe to retry via the renew_inv_{id} idempotency key.)
            $renewalContent = json_decode((string) $sub->product_content);

            if (! in_array($sub->billing_cycle, ['month', 'quarter', 'year'], true)
                || ! $renewalContent instanceof \stdClass
                || ! isset($renewalContent->bandwidth)
            ) {
                return false;
            }

            $paymentIntent = $stripe->chargeOffSession(
                $customerId,
                $paymentMethodId,
                $amountMinor,
                $currency,
                'renew_inv_' . $invoice->id,
                ['invoice_id' => (string) $invoice->id]
            );

            if ($paymentIntent->status !== 'succeeded') {
                return false;
            }

            // Settle + record + claim order + advance — atomically, so the 5-min
            // activation chain can never re-select a paid renewal (exactly-once).
            // Mirror payRenewalFromBalance: re-load the invoice under a row lock and re-assert it
            // is still unpaid before settling. Under genuinely parallel daily-cron processes both
            // can pass the pre-charge guard and charge once (the renew_inv_{id} idempotency key
            // dedupes to a SINGLE charge), but only ONE may run the settle + advanceRenewedPeriod.
            // If a concurrent actor already settled it, no-op here (that actor owns the advance);
            // otherwise the period double-advances by one free cycle.
            DB::transaction(static function () use ($sub, $invoice, $user, $amountMinor, $currency): void {
                $locked = (new Invoice())->where('id', $invoice->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== 'unpaid') {
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
            // ANY failure (card declined, Stripe API, FX/Redis/Guzzle) -> no charge applied
            // from the caller's standpoint -> false, so the waterfall falls through to grace.
            echo $e->getMessage() . PHP_EOL;

            return false;
        }
    }

    /**
     * 余额与存档卡都失败时进入 N 天宽限期（服务不断）。
     *
     * 先把状态落库：grace_until = end_date + grace_days（默认 3，'Y-m-d H:i:s'）、订阅置
     * pending_renewal、用户 class_expire 延到 grace_until 保活——状态变更绝不被发信影响。
     * 状态保存后再尝试发送续费失败邮件，模板缺失/渠道异常一律被 try/catch 吞掉。
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

        try {
            Notification::notifyUser(
                $user,
                $_ENV['appName'] . '-订阅续费失败',
                '你好，本次自动续费未能成功，请在 ' . $graceDays . ' 天内完成支付，否则订阅将到期失效。',
                'subscription_renewal_failed.tpl'
            );
        } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * 自动续费瀑布主流程（每日执行）。
     *
     * 选取自管(SELF_MANAGED)、auto_renew=1、已到期(end_date <= today，'<=' 兼容漏跑当日)、
     * 状态为 active/pending_renewal 且存在 unpaid 续费账单的订阅；对每个依次尝试：
     *   1) 余额扣款成功 → 结算并原子推进周期
     *   2) 否则存档卡扣款成功 → 结算并原子推进周期
     *   3) 都失败 → 进入宽限期
     * 扣款方法在“结算的同一事务内”把续费订单置 activated 并 advanceRenewedPeriod 推进
     * （见 finalizeRenewal），因此本方法成功分支无需再动订单/周期。每个订阅各自包裹 try/catch：
     * 单个订阅的意外异常只记录并 continue，绝不连累整批每日任务。
     */
    public static function processAutoRenew(): void
    {
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

                // 订单对应的 unpaid 续费账单
                $invoice = (new Invoice())
                    ->where('order_id', $order->id)
                    ->where('status', 'unpaid')
                    ->first();

                if ($invoice === null) {
                    continue;
                }

                // 瀑布：余额优先，不足再走存档卡。两者均在结算事务内原子完成
                // 订单 activated + 推进周期（exactly-once），此处无需再处理。
                if (self::payRenewalFromBalance($subscription, $invoice)
                    || self::chargeRenewalToCard($subscription, $invoice)) {
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
     * 仅处理用户已取消自动续费(auto_renew=0)、已到期(end_date <= today，'<=' 兼容漏跑当日)的
     * pending_renewal 订阅；auto_renew=1 的到期订阅交由 processAutoRenew / 宽限期 / terminateLapsed 处理。
     */
    public static function expireSubscription(): void
    {
        $today = Carbon::today()->format('Y-m-d');

        $subscriptions = (new Subscription())->where('status', 'pending_renewal')
            ->where('end_date', '<=', $today)
            ->where('auto_renew', 0)
            ->whereIn('billing_provider', self::SELF_MANAGED)
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
                self::downgradeUser($user);

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

            // 宽限内已支付 → 跳过，留给正常激活链。
            if ($invoice === null || $invoice->status !== 'unpaid') {
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
                        'subscription_expired.tpl'
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
