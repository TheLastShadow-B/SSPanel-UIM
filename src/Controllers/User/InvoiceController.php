<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Paylist;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Services\Coupon;
use App\Services\DB;
use App\Services\OrderActivation;
use App\Services\Payment;
use App\Utils\Tools;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function in_array;
use function json_decode;
use function json_encode;
use function time;

final class InvoiceController extends BaseController
{
    private static array $details = [
        'field' => [
            'op' => '操作',
            'id' => '账单ID',
            'order_id' => '订单ID',
            'price' => '账单金额',
            'status' => '账单状态',
            'create_time' => '创建时间',
            'update_time' => '更新时间',
            'pay_time' => '支付时间',
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
                ->fetch('user/invoice/index.tpl')
        );
    }

    /**
     * @throws Exception
     */
    public function detail(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $id = $this->antiXss->xss_clean($args['id']);

        $invoice = (new Invoice())->where('user_id', $this->user->id)->where('id', $id)->first();

        if ($invoice === null) {
            return $response->withRedirect('/user/invoice');
        }

        $paylist = [];

        if ($invoice->status === 'paid_gateway') {
            $paylist = (new Paylist())->where('invoice_id', $invoice->id)->where('status', 1)->first();
        }

        $invoice->status_text = $invoice->status();
        $invoice->create_time = Tools::toDateTime($invoice->create_time);
        $invoice->update_time = Tools::toDateTime($invoice->update_time);
        $invoice->pay_time = Tools::toDateTime($invoice->pay_time);
        $invoice_content = json_decode($invoice->content);

        return $response->write(
            $this->view()
                ->assign('invoice', $invoice)
                ->assign('invoice_content', $invoice_content)
                ->assign('paylist', $paylist)
                ->assign('payments', Payment::getPaymentsEnabled())
                ->fetch('user/invoice/view.tpl')
        );
    }

    public function payBalance(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $invoice_id = $this->antiXss->xss_clean($request->getParam('invoice_id'));

        $invoice = (new Invoice())->where('user_id', $this->user->id)->where('id', $invoice_id)->first();

        if ($invoice === null) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '账单不存在',
            ]);
        }

        // 仅“可支付”状态可走余额支付：unpaid 与 partially_paid（部分支付后用余额补齐余款，
        // view.tpl 为其渲染有效的余额支付按钮，Gateway/Base 与 Cron::processPendingOrder 也都
        // 视其为仍待支付）。其余状态（作废/已过期/已支付，含被 terminateLapsed 取消的失效续费
        // 账单）一律拒绝、绝不扣减余额——cancelled 仍被挡住，这正是“失效账单不可再支付”的强制点。
        if (! in_array($invoice->status, ['unpaid', 'partially_paid'], true)) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '该账单当前状态不支持余额支付',
            ]);
        }

        $user = $this->user;

        if ($user->is_shadow_banned) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '支付失败，请稍后再试',
            ]);
        }

        // 账单是否为充值
        if ($invoice->type === 'topup') {
            return $response->withJson([
                'ret' => 0,
                'msg' => '该账单不支持使用余额支付',
            ]);
        }

        // 检查订单使用的优惠码是否允许余额支付
        if ($invoice->order_id !== null && $invoice->order_id > 0) {
            $order = (new Order())->find($invoice->order_id);

            if ($order !== null && $order->coupon !== '' && ! Coupon::checkBalancePayAllowed($order->coupon)) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '使用的优惠码不支持余额支付，请使用其他支付方式',
                ]);
            }
        }

        // 组合支付：把扣款落在一笔行锁事务里——锁内对账单 lockForUpdate 复读并复查仍「可支付」
        // (unpaid/partially_paid) 后才扣款。修复 P1 双扣竞态：并发的存档卡自动续费 chargeRenewalToCard
        // 会先用行锁把账单 unpaid -> processing「认领」；若本请求在顶部无锁读时还看到 unpaid、却在加锁
        // 复查时账单已被认领(processing)或已结算，必须一分钱都不扣、直接报错返回(deduct nothing)。
        // 镜像 SubscriptionService::payRenewalFromBalance 的锁模式。
        $outcome = DB::transaction(function () use ($invoice): string {
            $locked = (new Invoice())->where('id', $invoice->id)->lockForUpdate()->first();

            // 锁内复查：账单必须仍可支付，否则不扣款（已被认领为 processing/已结算/已作废）。
            if ($locked === null || ! in_array($locked->status, ['unpaid', 'partially_paid'], true)) {
                return 'not_payable';
            }

            $user = (new User())->where('id', $locked->user_id)->lockForUpdate()->first();

            if ($user === null || (float) $user->money <= 0) {
                return 'insufficient';
            }

            $money_before = (float) $user->money;

            if ((float) $user->money >= (float) $locked->price) {
                $paid = (float) $locked->price;
                $locked->status = 'paid_balance';
            } else {
                $paid = (float) $user->money;
                $locked->status = 'partially_paid';
                $locked->price -= $paid;
                $invoice_content = json_decode($locked->content);
                $invoice_content[] = [
                    'content_id' => count($invoice_content),
                    'name' => '余额部分支付',
                    'price' => '-' . $paid,
                ];
                $locked->content = json_encode($invoice_content);
            }

            $user->money -= $paid;
            $user->save();

            (new UserMoneyLog())->add(
                (int) $user->id,
                $money_before,
                (float) $user->money,
                -$paid,
                '支付账单 #' . $locked->id
            );

            $locked->update_time = time();
            $locked->pay_time = time();
            $locked->save();

            return $locked->status === 'paid_balance' ? 'paid_full' : 'partial';
        });

        if ($outcome === 'not_payable') {
            return $response->withJson([
                'ret' => 0,
                'msg' => '该账单当前状态不支持余额支付',
            ]);
        }

        if ($outcome === 'insufficient') {
            return $response->withJson([
                'ret' => 0,
                'msg' => '余额不足',
            ]);
        }

        if ($outcome === 'paid_full') {
            // 余额全额支付成功:即时激活关联订单(幂等,cron 兜底)。
            if ($invoice->order_id !== null) {
                OrderActivation::tryActivate((int) $invoice->order_id);
            }

            return $response->withHeader('HX-Redirect', '/user/invoice');
        }

        return $response->withHeader('HX-Refresh', 'true');
    }

    public function ajax(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $invoices = (new Invoice())->orderBy('id', 'desc')->where('user_id', $this->user->id)->get();

        foreach ($invoices as $invoice) {
            $invoice->op = '<a class="btn btn-primary" href="/user/invoice/' . $invoice->id . '/view">查看</a>';
            $invoice->status = $invoice->status();
            $invoice->create_time = Tools::toDateTime($invoice->create_time);
            $invoice->update_time = Tools::toDateTime($invoice->update_time);
            $invoice->pay_time = Tools::toDateTime($invoice->pay_time);
        }

        return $response->withJson([
            'invoices' => $invoices,
        ]);
    }
}
