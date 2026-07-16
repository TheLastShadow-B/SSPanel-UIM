<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Setting;

use App\Controllers\BaseController;
use App\Models\Config;
use App\Services\Payment;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Smarty\Exception;
use Srmklive\PayPal\Services\PayPal;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Throwable;

final class BillingController extends BaseController
{
    private array $update_field;
    private array $settings;

    public function __construct()
    {
        parent::__construct();
        $this->update_field = Config::getItemListByClass('billing');
        $this->settings = Config::getClass('billing');
    }

    /**
     * @throws Exception
     */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $response->write(
            $this->view()
                ->assign('update_field', $this->update_field)
                ->assign('settings', $this->settings)
                ->assign('payment_gateways', $this->returnGatewaysList())
                ->assign('active_payment_gateway', $this->returnActiveGateways())
                ->fetch('admin/setting/billing.tpl')
        );
    }

    public function save(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $active_gateway = [];

        foreach ($this->returnGatewaysList() as $key => $value) {
            if ($request->getParam($value) === 'true') {
                $active_gateway[] = $value;
            }
        }

        if (! Config::set('payment_gateway', $active_gateway)) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '保存支付网关时出错',
            ]);
        }

        foreach ($this->update_field as $item) {
            if ($item === 'payment_gateway') {
                continue;
            }

            if (! Config::set($item, $request->getParam($item))) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '保存 ' . $item . ' 时出错',
                ]);
            }
        }

        return $response->withJson([
            'ret' => 1,
            'msg' => '保存成功',
        ]);
    }

    public function setStripeWebhook(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $stripe_api_key = $request->getParam('stripe_api_key');

        $stripe = new StripeClient([
            'api_key' => $stripe_api_key,
            'stripe_version' => '2026-03-25.dahlia',
        ]);

        $notify_url = $_ENV['baseUrl'] . '/payment/notify/stripe';

        try {
            // 先清掉指向本站回调的旧 endpoint:重复点击会产生多个 endpoint,
            // 每个签名密钥不同,与库中密钥不符的事件全部验签 400。
            $existing = $stripe->webhookEndpoints->all(['limit' => 100]);
            foreach ($existing->data as $ep) {
                if ($ep->url === $notify_url) {
                    $stripe->webhookEndpoints->delete($ep->id, []);
                }
            }

            $endpoint = $stripe->webhookEndpoints->create([
                'url' => $notify_url,
                'enabled_events' => [
                    // One-time / first-purchase settlement (Stripe::notify, inline)
                    // and self-managed renewal off-session charges.
                    'payment_intent.succeeded',
                    // Card-binding for the self-managed renewal engine
                    // (WebhookHandler::handleSetupIntentSucceeded).
                    'setup_intent.succeeded',
                ],
            ]);
        } catch (ApiErrorException) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '设置 Stripe Webhook 失败',
            ]);
        }

        // 签名密钥只在创建响应里出现一次,必须立即入库,否则 notify 验签全部失败。
        if (! Config::set('stripe_endpoint_secret', (string) $endpoint->secret)) {
            return $response->withJson([
                'ret' => 0,
                'msg' => 'Webhook 已创建，但保存签名密钥失败，请手动填写 stripe_endpoint_secret',
            ]);
        }

        return $response->withJson([
            'ret' => 1,
            'msg' => '设置 Stripe Webhook 成功，签名密钥已自动保存',
        ]);
    }

    public function setPaypalWebhook(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $paypal_client_id = $request->getParam('paypal_client_id');
        $paypal_client_secret = $request->getParam('paypal_client_secret');

        $gateway_config = [
            'mode' => 'live',
            'live' => [
                'client_id' => $paypal_client_id,
                'client_secret' => $paypal_client_secret,
            ],
            'payment_action' => 'Sale',
            'currency' => 'USD',
            'notify_url' => '',
            'locale' => 'en_US',
            'validate_ssl' => true,
        ];

        try {
            $pp = new PayPal($gateway_config);
            $pp->getAccessToken();
            $pp->createWebHook($_ENV['baseUrl'] . '/payment/notify/paypal', ['PAYMENT.CAPTURE.COMPLETED']);
        } catch (Throwable $e) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '设置 PayPal Webhook 失败',
            ]);
        }

        return $response->withJson([
            'ret' => 1,
            'msg' => '设置 PayPal Webhook 成功',
        ]);
    }

    public function returnGatewaysList(): array
    {
        $result = [];

        foreach (Payment::getAllPaymentMap() as $payment) {
            $result[$payment::_readableName()] = $payment::_name();
        }

        return $result;
    }

    public function returnActiveGateways(): ?array
    {
        return Config::obtain('payment_gateway');
    }
}
