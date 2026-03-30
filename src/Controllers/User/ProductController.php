<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Models\Product;
use App\Models\Subscription;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function json_decode;

final class ProductController extends BaseController
{
    /**
     * @throws Exception
     */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $subscriptions = (new Product())->where('status', '1')
            ->where('type', 'subscription')
            ->orderBy('id')
            ->get();

        $bandwidths = (new Product())->where('status', '1')
            ->where('type', 'bandwidth')
            ->orderBy('id')
            ->get();

        foreach ($subscriptions as $sub) {
            $sub->content = json_decode($sub->content);
        }

        foreach ($bandwidths as $bandwidth) {
            $bandwidth->content = json_decode($bandwidth->content);
        }

        // 检查用户是否有活跃订阅
        $hasActiveSubscription = (new Subscription())
            ->where('user_id', $this->user->id)
            ->whereIn('status', ['active', 'pending_renewal'])
            ->exists();

        return $response->write(
            $this->view()
                ->assign('subscriptions', $subscriptions)
                ->assign('bandwidths', $bandwidths)
                ->assign('hasActiveSubscription', $hasActiveSubscription)
                ->fetch('user/product.tpl')
        );
    }
}
