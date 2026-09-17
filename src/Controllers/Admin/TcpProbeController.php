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
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

final class TcpProbeController extends BaseController
{
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $installed = TcpProbe::installed();
        $targets = $installed ? array_column(TcpProbe::targets(), null, 'id') : [];
        $slots = [];
        $id = 0;
        foreach (TcpProbeStatus::CARRIERS as $carrier => $name) {
            for ($i = 1; $i <= 3; $i++) {
                $id++;
                $slots[] = ['id' => $id, 'carrier_name' => $name, 'position' => $i,
                    'label' => $targets[$id]['label'] ?? '', 'ip' => $targets[$id]['ip'] ?? '',
                    'port' => $targets[$id]['port'] ?? 443];
            }
        }
        $probes = $installed ? DB::table('tcp_probe')->get()->keyBy('node_id') : collect();
        $nodes = Node::orderBy('id')->get()->map(fn ($node) => [
            'id' => $node->id, 'name' => $node->name,
            'enabled' => (bool) ($probes->get($node->id)->enabled ?? false),
            'threshold_ms' => $probes->get($node->id)->threshold_ms ?? 250,
        ])->all();
        return $response->write($this->view()->assign('installed', $installed)->assign('targets', $slots)
            ->assign('nodes', $nodes)->assign('csrf_token', CSRF::token())->fetch('admin/node/probe.tpl'));
    }

    public static function validateTargets(mixed $input): array
    {
        if (! is_array($input) || count($input) > 9) {
            throw new InvalidArgumentException('测试目标格式错误');
        }
        $targets = [];
        $addresses = [];
        $carriers = array_keys(TcpProbeStatus::CARRIERS);
        foreach ($input as $id => $target) {
            if (! ctype_digit((string) $id) || (int) $id < 1 || (int) $id > 9 || ! is_array($target)) {
                throw new InvalidArgumentException('测试目标编号错误');
            }
            if (! is_string($target['ip'] ?? null) || ! is_string($target['label'] ?? null)) {
                throw new InvalidArgumentException('测试目标格式错误');
            }
            $ip = trim($target['ip']);
            if ($ip === '') {
                continue;
            }
            $label = trim($target['label']);
            $port = filter_var($target['port'] ?? null, FILTER_VALIDATE_INT);
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with($ip, '169.254.') || (int) explode('.', $ip)[0] >= 224
                || $port === false || $port < 1 || $port > 65535 || $label === '' || mb_strlen($label) > 80) {
                throw new InvalidArgumentException('请填写目标名称、公网 IPv4 和 1–65535 的端口');
            }
            if (isset($addresses[$ip . ':' . $port])) {
                throw new InvalidArgumentException('同一 IP 和端口不能重复作为多个目标');
            }
            $addresses[$ip . ':' . $port] = true;
            $targets[] = ['id' => (int) $id, 'carrier' => $carriers[intdiv((int) $id - 1, 3)],
                'label' => $label, 'ip' => $ip, 'port' => $port];
        }
        return $targets;
    }

    public function saveTargets(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbe::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        try {
            $targets = self::validateTargets($request->getParam('targets', []));
        } catch (InvalidArgumentException $e) {
            return $response->withJson(['ret' => 0, 'msg' => $e->getMessage()], 422);
        }
        DB::connection()->transaction(function () use ($targets): void {
            DB::table('tcp_probe_target')->delete();
            if ($targets !== []) {
                DB::table('tcp_probe_target')->insert($targets);
            }
        });
        return $response->withJson(['ret' => 1, 'msg' => '目标已保存，XrayR 将在下一轮自动获取']);
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
