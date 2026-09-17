<?php

declare(strict_types=1);

namespace App\Controllers\WebAPI;

use App\Controllers\BaseController;
use App\Models\Node;
use App\Services\TcpProbe;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

final class TcpProbeController extends BaseController
{
    public function config(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbe::installed() || ! Node::where('id', $args['id'])->where('type', 1)->exists()) {
            return $response->withJson(['ret' => 1, 'data' => ['enabled' => false]])->withHeader('Cache-Control', 'no-store');
        }
        return $response->withJson(['ret' => 1, 'data' => TcpProbe::config((int) $args['id'])])->withHeader('Cache-Control', 'no-store');
    }

    public function report(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbe::installed() || ! Node::where('id', $args['id'])->where('type', 1)->exists()) {
            return $response->withJson(['ret' => 0, 'msg' => '节点不可用'], 404);
        }
        $limit = max(32768, 1024 + count(TcpProbe::targets()) * 1024);
        $raw = $request->getBody()->read($limit + 1);
        if (strlen($raw) > $limit) {
            return $response->withJson(['ret' => 0, 'msg' => '报告过大'], 413);
        }
        try {
            $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($body)) {
                throw new InvalidArgumentException('报告格式错误');
            }
            TcpProbe::report((int) $args['id'], $body, time());
        } catch (InvalidArgumentException|JsonException $e) {
            return $response->withJson(['ret' => 0, 'msg' => $e->getMessage()], 422);
        }
        return $response->withJson(['ret' => 1, 'msg' => '已接收']);
    }
}
