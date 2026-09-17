<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Middleware\CSRF;
use App\Models\Node;
use App\Services\DB;
use App\Services\TcpProbe;
use App\Utils\TcpProbeStatus;
use InvalidArgumentException;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

final class TcpProbeController extends BaseController
{
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $installed = TcpProbe::installed();
        $targets = $installed ? TcpProbe::targets() : [];
        $probes = $installed ? DB::table('tcp_probe')->get()->keyBy('node_id') : collect();
        $nodes = Node::orderBy('id')->get()->map(fn ($node) => [
            'id' => $node->id, 'name' => $node->name,
            'enabled' => (bool) ($probes->get($node->id)->enabled ?? false),
            'threshold_ms' => $probes->get($node->id)->threshold_ms ?? 250,
        ])->all();
        return $response->write($this->view()->assign('installed', $installed)->assign('targets', $targets)
            ->assign('carriers', TcpProbeStatus::DISPLAY_NAMES)->assign('interval_seconds', TcpProbe::interval(count($targets)))
            ->assign('nodes', $nodes)->assign('csrf_token', CSRF::token())->fetch('admin/node/probe.tpl'));
    }

    public static function validateTarget(mixed $target): array
    {
        if (! is_array($target) || ! is_string($target['ip'] ?? null)
            || ! is_string($target['label'] ?? null) || ! is_string($target['carrier'] ?? null)
            || ! isset(TcpProbeStatus::CARRIERS[$target['carrier']])) {
            throw new InvalidArgumentException('请选择运营商并填写目标信息');
        }
        $ip = trim($target['ip']);
        $label = trim($target['label']);
        $port = filter_var($target['port'] ?? null, FILTER_VALIDATE_INT);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || str_starts_with($ip, '169.254.') || (int) explode('.', $ip)[0] >= 224
            || $port === false || $port < 1 || $port > 65535 || $label === '' || mb_strlen($label) > 80) {
            throw new InvalidArgumentException('请填写目标名称、公网 IPv4 和 1–65535 的端口');
        }
        return ['carrier' => $target['carrier'], 'label' => $label, 'ip' => $ip, 'port' => $port];
    }

    public function saveTarget(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbe::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        $id = isset($args['id']) ? (int) $args['id'] : null;
        if ($id !== null && ! DB::table('tcp_probe_target')->where('id', $id)->exists()) {
            return $response->withJson(['ret' => 0, 'msg' => '测试目标不存在'], 404);
        }
        try {
            $target = self::validateTarget($request->getParams());
            $duplicates = DB::table('tcp_probe_target')->where('ip', $target['ip'])->where('port', $target['port']);
            if ($id !== null) {
                $duplicates->where('id', '!=', $id);
            }
            if ($duplicates->exists()) {
                throw new InvalidArgumentException('同一 IP 和端口不能重复作为多个目标');
            }
            if ($id === null) {
                $id = DB::table('tcp_probe_target')->insertGetId($target);
            } else {
                DB::table('tcp_probe_target')->where('id', $id)->update($target);
            }
        } catch (InvalidArgumentException|UniqueConstraintViolationException $e) {
            $message = $e instanceof InvalidArgumentException ? $e->getMessage() : '同一 IP 和端口不能重复作为多个目标';
            return $response->withJson(['ret' => 0, 'msg' => $message], 422);
        }
        return $response->withJson(['ret' => 1, 'msg' => '目标已保存，XrayR 将在下一轮自动获取', 'id' => $id])
            ->withHeader('HX-Refresh', 'true');
    }

    public function deleteTarget(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbe::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        if (! DB::table('tcp_probe_target')->where('id', (int) $args['id'])->delete()) {
            return $response->withJson(['ret' => 0, 'msg' => '测试目标不存在'], 404);
        }
        return $response->withJson(['ret' => 1, 'msg' => '测试目标已删除'])->withHeader('HX-Refresh', 'true');
    }

    public function saveNode(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbe::installed() || ! Node::where('id', $args['id'])->exists()) {
            return $response->withJson(['ret' => 0, 'msg' => '节点不存在或数据库尚未迁移'], 404);
        }
        $threshold = filter_var($request->getParam('threshold_ms'), FILTER_VALIDATE_INT);
        if ($threshold === false || $threshold < 1 || $threshold > 3000) {
            return $response->withJson(['ret' => 0, 'msg' => '延迟阈值必须为 1–3000 ms'], 422);
        }
        DB::table('tcp_probe')->updateOrInsert(['node_id' => (int) $args['id']], [
            'enabled' => $request->getParam('enabled') === '1', 'threshold_ms' => $threshold,
        ]);
        return $response->withJson(['ret' => 1, 'msg' => '节点检测设置已保存']);
    }
}
