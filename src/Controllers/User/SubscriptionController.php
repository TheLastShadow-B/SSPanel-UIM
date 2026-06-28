<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
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
                $nextMonth = $today->copy()->addMonthNoOverflow();
                $resetDay = min($subscription->reset_day, $nextMonth->daysInMonth);
                $nextResetDate = Carbon::create($nextMonth->year, $nextMonth->month, $resetDay);
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

    /**
     * 用户取消自动续费（opt-out）。仅作用于调用者本人 active/pending_renewal 的订阅，
     * 绝不接收请求传入的订阅 id。取消后订阅会跑完当前周期再由 expireSubscription 自然过期。
     */
    public function cancelAutoRenew(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $this->setAutoRenew($response, 0, '已取消自动续费，当前订阅周期结束后将不再续费');
    }

    /**
     * 用户重新开启自动续费。仅作用于调用者本人 active/pending_renewal 的订阅。
     */
    public function enableAutoRenew(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $this->setAutoRenew($response, 1, '已开启自动续费');
    }

    /**
     * 将调用者本人当前订阅的 auto_renew 置为给定值。无可操作订阅时优雅返回 ret:0。
     */
    private function setAutoRenew(Response $response, int $autoRenew, string $okMsg): ResponseInterface
    {
        $subscription = (new Subscription())->where('user_id', $this->user->id)
            ->whereIn('status', ['active', 'pending_renewal'])
            ->first();

        if ($subscription === null) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '未找到可操作的订阅',
            ]);
        }

        $subscription->auto_renew = $autoRenew;
        $subscription->save();

        return $response->withJson([
            'ret' => 1,
            'msg' => $okMsg,
        ]);
    }
}
