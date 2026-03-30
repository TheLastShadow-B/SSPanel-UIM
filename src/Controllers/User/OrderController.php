<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Services\Coupon;
use App\Utils\Cookie;
use App\Utils\Tools;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function in_array;
use function json_decode;
use function json_encode;
use function time;

final class OrderController extends BaseController
{
    private static array $details = [
        'field' => [
            'op' => '操作',
            'id' => '订单ID',
            'product_id' => '商品ID',
            'product_type' => '商品类型',
            'product_name' => '商品名称',
            'coupon' => '优惠码',
            'price' => '金额',
            'status' => '状态',
            'create_time' => '创建时间',
            'update_time' => '更新时间',
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
                ->fetch('user/order/index.tpl')
        );
    }

    /**
     * @throws Exception
     */
    public function create(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $product_id = $this->antiXss->xss_clean($request->getQueryParams()['product_id']) ?? null;
        $redir = Cookie::get('redir');

        if ($redir !== '') {
            Cookie::set(['redir' => ''], time() - 1);
        }

        if ($product_id === null || $product_id === '') {
            return $response->withRedirect('/user/product');
        }

        $product = (new Product())->where('id', $product_id)->first();
        $product->type_text = $product->type();
        $product->content = json_decode($product->content);

        $isSubscription = $product->type === 'subscription';
        $hasActiveSubscription = false;

        if ($isSubscription) {
            $hasActiveSubscription = (new \App\Models\Subscription())
                ->where('user_id', $this->user->id)
                ->whereIn('status', ['active', 'pending_renewal'])
                ->exists();
        }

        return $response->write(
            $this->view()
                ->assign('product', $product)
                ->assign('isSubscription', $isSubscription)
                ->assign('hasActiveSubscription', $hasActiveSubscription)
                ->fetch('user/order/create.tpl')
        );
    }

    /**
     * @throws Exception
     */
    public function detail(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $id = $this->antiXss->xss_clean($args['id']);

        $order = (new Order())->where('user_id', $this->user->id)->where('id', $id)->first();

        if ($order === null) {
            return $response->withRedirect('/user/order');
        }

        $order->product_type_text = $order->productType();
        $order->status = $order->status();
        $order->create_time = Tools::toDateTime($order->create_time);
        $order->update_time = Tools::toDateTime($order->update_time);
        $order->content = json_decode($order->product_content);

        $invoice = (new Invoice())->where('order_id', $id)->first();
        $invoice->status = $invoice->status();
        $invoice->create_time = Tools::toDateTime($invoice->create_time);
        $invoice->update_time = Tools::toDateTime($invoice->update_time);
        $invoice->pay_time = Tools::toDateTime($invoice->pay_time);
        $invoice->content = json_decode($invoice->content);

        return $response->write(
            $this->view()
                ->assign('order', $order)
                ->assign('invoice', $invoice)
                ->fetch('user/order/view.tpl')
        );
    }

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

    public function product(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $couponCode = $this->antiXss->xss_clean($request->getParam('coupon'));
        $productId = $this->antiXss->xss_clean($request->getParam('product_id'));

        $product = (new Product())->find($productId);

        if ($product === null || $product->stock === 0) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '商品不存在或库存不足',
            ]);
        }

        $buyPrice = $product->price;
        $user = $this->user;
        $discount = 0;

        if ($user->is_shadow_banned) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '商品不存在或库存不足',
            ]);
        }

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

        $couponService = null;

        if ($couponCode !== '') {
            $couponService = new Coupon();

            if (! $couponService->validate($couponCode, $product, $user)) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => $couponService->getError(),
                ]);
            }

            $discount = $couponService->getDiscount();
            $buyPrice = $couponService->getFinalPrice();
        }

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

        $order = new Order();
        $order->user_id = $user->id;
        $order->product_id = $product->id;
        $order->product_type = $product->type;
        $order->product_name = $product->name;
        $order->product_content = $product->content;
        $order->coupon = $couponCode;
        $order->price = $buyPrice;
        $order->status = $buyPrice === 0.0 ? 'pending_activation' : 'pending_payment';
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoiceContent = [];
        $invoiceContent[] = [
            'content_id' => 0,
            'name' => $product->name,
            'price' => $product->price,
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

        // 检查是否有未到期的旧套餐
        if ($user->class > 0 && strtotime($user->class_expire) > time()) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '你当前还有未到期的套餐，请等待到期后再购买订阅',
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

        if (! ($content->billing_cycle->$billingCycle ?? false)) {
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

        // 优惠券验证（仅新购允许）
        if ($couponCode !== '') {
            $couponService = new Coupon();

            if (! $couponService->validate($couponCode, $product, $user)) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => $couponService->getError(),
                ]);
            }

            $discount = $couponService->getDiscount();
            $buyPrice = max(0, round($cyclePrice - $discount, 2));
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

        // 在 product_content 中记录用户选择的周期和产品名
        $orderContent = json_decode($product->content, true);
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

    public function topup(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $amount = $this->antiXss->xss_clean($request->getParam('amount'));
        $amount = is_numeric($amount) ? round((float) $amount, 2) : null;

        if ($amount === null || $amount <= 0) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '充值金额无效',
            ]);
        }

        $order = new Order();
        $order->user_id = $this->user->id;
        $order->product_id = 0;
        $order->product_type = 'topup';
        $order->product_name = '余额充值';
        $order->product_content = json_encode(['amount' => $amount]);
        $order->coupon = '';
        $order->price = $amount;
        $order->status = 'pending_payment';
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice_content = [];
        $invoice_content[] = [
            'content_id' => 0,
            'name' => '余额充值',
            'price' => $amount,
        ];

        $invoice = new Invoice();
        $invoice->user_id = $this->user->id;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode($invoice_content);
        $invoice->price = $amount;
        $invoice->status = 'unpaid';
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->pay_time = 0;
        $invoice->type = 'topup';
        $invoice->save();

        return $response->withHeader('HX-Redirect', '/user/invoice/' . $invoice->id . '/view');
    }

    public function ajax(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $orders = (new Order())->orderBy('id', 'desc')->where('user_id', $this->user->id)->get();

        foreach ($orders as $order) {
            $order->op = '<a class="btn btn-primary" href="/user/order/' . $order->id . '/view">查看</a>';

            if ($order->status === 'pending_payment') {
                $invoice_id = (new Invoice())->where('order_id', $order->id)->first()->id;
                $order->op .= '
                <a class="btn btn-red" href="/user/invoice/' . $invoice_id . '/view">支付</a>';
            }

            $order->product_type = $order->productType();
            $order->status = $order->status();
            $order->create_time = Tools::toDateTime($order->create_time);
            $order->update_time = Tools::toDateTime($order->update_time);
        }

        return $response->withJson([
            'orders' => $orders,
        ]);
    }
}
