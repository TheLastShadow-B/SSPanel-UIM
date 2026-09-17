<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\Subscribe;
use App\Services\TcpProbe;
use App\Utils\NodeRegion;
use App\Utils\TcpProbeStatus;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

final class ServerController extends BaseController
{
    /**
     * @throws Exception
     */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $nodes = Subscribe::getUserNodes($this->user, true);
        $probe_status = TcpProbe::current($nodes->pluck('id')->all(), time());
        $node_list = [];

        foreach ($nodes as $node) {
            $node_list[] = [
                'id' => $node->id,
                'name' => $node->name,
                'display_name' => $node->displayName(),
                'country' => $node->country ?? '',
                'probe_status' => $probe_status[$node->id],
                'class' => (int) $node->node_class,
                'locked' => $this->user->class < (int) $node->node_class,
                'connection_type' => $node->connection_type,
                'proto' => $node->sortShort(),
                'online_user' => $node->online_user,
                'online' => $node->getNodeOnlineStatus(),
                'traffic_rate' => $node->traffic_rate,
                'is_dynamic_rate' => $node->is_dynamic_rate,
            ];
        }

        return $response->write(
            $this->view()
                ->assign('server_groups', NodeRegion::group($node_list))
                ->assign('carriers', TcpProbeStatus::CARRIERS)
                ->fetch('user/server.tpl')
        );
    }

    public function summary(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $ids = Subscribe::getUserNodes($this->user, true)->pluck('id')->all();
        return $response->withHeader('Cache-Control', 'no-store')->withJson(['ret' => 1, 'data' => TcpProbe::current($ids, time())]);
    }

    public function detail(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $node = Subscribe::getUserNodes($this->user, true)->firstWhere('id', (int) $args['id']);
        if ($node === null) {
            return $response->withStatus(404)->write('节点不存在或不可访问');
        }
        return $response->write($this->view()->assign('node', $node)
            ->assign('probe', TcpProbe::detail((int) $node->id, time()))->fetch('user/server_detail.tpl'));
    }

    public function status(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $node = Subscribe::getUserNodes($this->user, true)->firstWhere('id', (int) $args['id']);
        if ($node === null) {
            return $response->withStatus(404)->write('节点不存在或不可访问');
        }
        return $response->withHeader('Cache-Control', 'no-store')->write($this->view()
            ->assign('probe', TcpProbe::detail((int) $node->id, time()))->fetch('user/server_probe.tpl'));
    }
}
