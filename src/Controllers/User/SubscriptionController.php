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
}
