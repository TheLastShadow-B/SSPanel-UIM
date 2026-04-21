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
use function in_array;
use function json_decode;
use function json_encode;
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
        $userEmail = $user !== null ? $user->email : '用户已删除';

        return $response->write(
            $this->view()
                ->assign('subscription', $subscription)
                ->assign('user', $user)
                ->assign('userEmail', $userEmail)
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
        $newEndDate = $request->getParam('end_date');

        if ($newPrice < 0) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '价格不能为负数',
            ]);
        }

        $subscription->renewal_price = $newPrice;

        // 更新到期日期
        if ($newEndDate !== null && $newEndDate !== '' && $newEndDate !== $subscription->end_date) {
            $parsedDate = Carbon::parse($newEndDate);

            if ($parsedDate->lt(Carbon::today())) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '到期日期不能早于今天',
                ]);
            }

            $subscription->end_date = $parsedDate->toDateString();

            // 同步更新用户的 class_expire
            $user = (new User())->find($subscription->user_id);
            if ($user !== null) {
                $user->class_expire = $parsedDate->toDateString() . ' 23:59:59';
                $user->save();
            }
        }

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
            'msg' => '订阅信息更新成功',
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

        if (in_array($subscription->status, ['cancelled', 'expired'])) {
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
