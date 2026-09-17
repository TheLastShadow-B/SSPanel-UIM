<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Middleware\CSRF;
use App\Models\Node;
use App\Services\DB;
use App\Services\TcpProbe;
use App\Services\TaierProbeSource;
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
        $now = time();
        $overview = TcpProbe::overview($now);
        $blank = ['status' => 'gray', 'label' => TcpProbeStatus::LABELS['gray'], 'latency_ms' => null, 'ok' => 0, 'total' => 0];
        $targets = array_map(static function (array $target) use ($overview, $blank) {
            $stat = $overview['targets'][(int) $target['id']] ?? $blank;
            return $target + [
                'status' => $stat['status'], 'status_label' => $stat['label'],
                'latency_ms' => $stat['latency_ms'], 'reach' => $stat['ok'] . ' / ' . $stat['total'],
                'managed' => ($target['source'] ?? 'manual') === 'taier',
            ];
        }, $installed ? TcpProbe::targets() : []);
        $probes = $installed ? DB::table('tcp_probe')->get()->keyBy('node_id') : collect();
        $nodes = Node::orderBy('id')->get()->map(static function ($node) use ($probes, $overview, $now) {
            $report = $overview['nodes'][$node->id] ?? null;
            $states = $report['states'] ?? [];
            return [
                'id' => $node->id, 'name' => $node->name,
                'enabled' => (bool) ($probes->get($node->id)->enabled ?? false),
                'threshold_ms' => $probes->get($node->id)->threshold_ms ?? 250,
                'states' => $states,
                'state_labels' => array_map(static fn ($status) => TcpProbeStatus::LABELS[$status], $states),
                'reported' => $report ? self::since($now - $report['measured_at']) : '—',
            ];
        })->all();
        $taier = TaierProbeSource::settings();
        return $response->write($this->view()->assign('installed', $installed)->assign('targets', $targets)
            ->assign('carriers', TcpProbeStatus::CARRIERS)->assign('carrier_codes', TcpProbeStatus::DISPLAY_NAMES)
            ->assign('interval_seconds', TcpProbe::interval(count($targets)))
            ->assign('overview', $overview)
            ->assign('updated', $overview['measured_at'] ? self::since($now - $overview['measured_at']) : null)
            ->assign('managed', count(array_filter($targets, static fn ($target) => $target['managed'])))
            ->assign('node_enabled', count(array_filter($nodes, static fn ($node) => $node['enabled'])))
            ->assign('taier_installed', TaierProbeSource::installed())->assign('taier', $taier)
            ->assign('taier_status', $taier['last_success'] === 0 ? 'gray' : ($taier['last_attempt'] > $taier['last_success'] ? 'red' : 'green'))
            ->assign('taier_cities', array_keys(TaierProbeSource::CITIES))->assign('nodes', $nodes)
            ->assign('csrf_token', CSRF::token())->fetch('admin/node/probe.tpl'));
    }

    /** Coarse wording only; the exact timestamp belongs in the per-node view. */
    private static function since(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . ' 秒前';
        }
        return $seconds < 3600 ? intdiv($seconds, 60) . ' 分钟前' : intdiv($seconds, 3600) . ' 小时前';
    }

    public static function validateTarget(mixed $target): array
    {
        return \App\Utils\TcpProbeTarget::validate($target);
    }

    public function saveTarget(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbe::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        // The drawer posts to one endpoint and carries the id as a field; the route arg still wins.
        $id = $args['id'] ?? $request->getParam('id');
        $id = ($id === null || $id === '') ? null : (int) $id;
        if ($id !== null && ! DB::table('tcp_probe_target')->where('id', $id)->exists()) {
            return $response->withJson(['ret' => 0, 'msg' => '测试目标不存在'], 404);
        }
        if ($id !== null && self::managed($id)) {
            return $response->withJson(['ret' => 0, 'msg' => '此目标由泰尔自动维护，请先关闭自动同步再修改'], 409);
        }
        try {
            $target = self::validateTarget($request->getParams());
            if (TaierProbeSource::installed()) {
                $target += ['source' => 'manual', 'source_key' => null, 'source_host_id' => null];
            }
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
        if (self::managed((int) $args['id'])) {
            return $response->withJson(['ret' => 0, 'msg' => '此目标由泰尔自动维护，请修改同步地区或关闭自动同步'], 409);
        }
        if (! DB::table('tcp_probe_target')->where('id', (int) $args['id'])->delete()) {
            return $response->withJson(['ret' => 0, 'msg' => '测试目标不存在'], 404);
        }
        return $response->withJson(['ret' => 1, 'msg' => '测试目标已删除'])->withHeader('HX-Refresh', 'true');
    }

    private static function managed(int $id): bool
    {
        return TaierProbeSource::installed() && TaierProbeSource::settings()['enabled']
            && DB::table('tcp_probe_target')->where('id', $id)->where('source', 'taier')->exists();
    }

    public function saveSource(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TaierProbeSource::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        try {
            TaierProbeSource::saveSettings($request->getParam('enabled') === '1', $request->getParam('cities', []), $request->getParam('interval_hours'));
        } catch (InvalidArgumentException $e) {
            return $response->withJson(['ret' => 0, 'msg' => $e->getMessage()], 422);
        }
        return $response->withJson(['ret' => 1, 'msg' => '已保存，定时任务将在五分钟内同步'])->withHeader('HX-Refresh', 'true');
    }

    public function syncSource(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $result = (new TaierProbeSource())->sync(true);
        return $response->withJson($result)->withHeader('HX-Refresh', 'true');
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

    /** The whole node table saves at once, so one round trip covers every edited row. */
    public function saveNodes(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbe::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        $thresholds = $request->getParam('threshold_ms');
        $switches = $request->getParam('enabled', []);
        if (! is_array($thresholds) || $thresholds === [] || ! is_array($switches)) {
            return $response->withJson(['ret' => 0, 'msg' => '提交内容格式错误'], 422);
        }
        $ids = array_map('intval', array_keys($thresholds));
        if (Node::whereIn('id', $ids)->count() !== count($ids)) {
            return $response->withJson(['ret' => 0, 'msg' => '节点已变动，请刷新后重试'], 404);
        }
        $rows = [];
        foreach ($ids as $id) {
            $threshold = filter_var($thresholds[$id], FILTER_VALIDATE_INT);
            if ($threshold === false || $threshold < 1 || $threshold > 3000) {
                return $response->withJson(['ret' => 0, 'msg' => '延迟阈值必须为 1–3000 ms'], 422);
            }
            $rows[$id] = ['enabled' => ($switches[$id] ?? '0') === '1', 'threshold_ms' => $threshold];
        }
        DB::connection()->transaction(static function () use ($rows): void {
            foreach ($rows as $id => $row) {
                DB::table('tcp_probe')->updateOrInsert(['node_id' => $id], $row);
            }
        });
        return $response->withJson(['ret' => 1, 'msg' => '已保存 ' . count($rows) . ' 个节点的检测设置'])
            ->withHeader('HX-Refresh', 'true');
    }
}
