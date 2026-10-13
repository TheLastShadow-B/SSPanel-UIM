<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Middleware\CSRF;
use App\Models\Node;
use App\Services\DB;
use App\Services\TaierProbeSource;
use App\Services\TcpProbe;
use App\Services\TcpProbeGroup;
use App\Services\UrlProbeSource;
use App\Utils\TcpProbeStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

final class TcpProbeController extends BaseController
{
    private const ORIGINS = ['taier' => 'TaierSpeedtest 同步', 'url' => 'URL 抓取', 'manual' => '手动添加'];

    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $installed = TcpProbe::installed();
        $grouped = $installed && TcpProbeGroup::installed();
        $now = time();
        $overview = TcpProbe::overview($now);
        $taier = TaierProbeSource::settings();
        $groups = $grouped ? array_column(TcpProbeGroup::all(), null, 'id') : [];
        $blank = ['status' => 'gray', 'label' => TcpProbeStatus::LABELS['gray'], 'latency_ms' => null, 'ok' => 0, 'total' => 0];
        $targets = array_map(static function (array $target) use ($overview, $blank, $taier, $groups) {
            $stat = $overview['targets'][(int) $target['id']] ?? $blank;
            $source = $target['source'] ?? 'manual';
            $group = (int) ($target['group_id'] ?? TcpProbeGroup::DEFAULT_ID);
            return $target + [
                'status' => $stat['status'], 'status_label' => $stat['label'],
                'latency_ms' => $stat['latency_ms'], 'reach' => $stat['ok'] . ' / ' . $stat['total'],
                'group_id' => $group, 'managed' => $source === 'taier', 'origin' => self::ORIGINS[$source] ?? self::ORIGINS['manual'],
                // Locked rows are rewritten by their source on the next sync, so hand edits would be lost.
                'locked' => ($source === 'taier' && $taier['enabled']) || ($source === 'url' && ($groups[$group]['url'] ?? '') !== ''),
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
                'group_id' => TcpProbe::groupOf($probes->get($node->id)),
                'states' => $states,
                'state_labels' => array_map(static fn ($status) => TcpProbeStatus::LABELS[$status], $states),
                'reported' => $report ? self::since($now - $report['measured_at']) : '—',
            ];
        })->all();
        foreach ($groups as $id => &$group) {
            $size = count(array_filter($targets, static fn ($target) => $target['group_id'] === $id));
            $group += [
                'targets' => $size, 'interval_seconds' => TcpProbe::interval($size), 'status' => self::taierStatus($group),
                'nodes' => count(array_filter($nodes, static fn ($node) => $node['group_id'] === $id)),
            ];
        }
        unset($group);
        return $response->write($this->view()->assign('installed', $installed)->assign('targets', $targets)
            ->assign('carriers', TcpProbeStatus::CARRIERS)->assign('carrier_codes', TcpProbeStatus::DISPLAY_NAMES)
            ->assign('interval_seconds', $groups[TcpProbeGroup::DEFAULT_ID]['interval_seconds'] ?? TcpProbe::interval(count($targets)))
            ->assign('probe_attempts', TcpProbe::ATTEMPTS)->assign('probe_timeout_ms', TcpProbe::TIMEOUT_MS)
            ->assign('overview', $overview)
            ->assign('updated', $overview['measured_at'] ? self::since($now - $overview['measured_at']) : null)
            ->assign('managed', count(array_filter($targets, static fn ($target) => $target['managed'])))
            ->assign('node_enabled', count(array_filter($nodes, static fn ($node) => $node['enabled'])))
            ->assign('groups', array_values($groups))->assign('groups_installed', $grouped)
            ->assign('group_names', array_column($groups, 'name', 'id'))
            ->assign('taier_installed', TaierProbeSource::installed())->assign('taier', $taier)
            ->assign('taier_status', self::taierStatus($taier))
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

    /** last_status is written by each sync together with last_message, so dot and text always come from the same attempt. */
    public static function taierStatus(array $source): string
    {
        return ['ok' => 'green', 'failed' => 'red'][$source['last_status'] ?? 'none'] ?? 'gray';
    }

    public static function validateTarget(mixed $target): array
    {
        return \App\Utils\TcpProbeTarget::validate($target);
    }

    /** The drawer's id field: absent means create; anything that is not a positive integer is not a row. */
    private static function rowId(ServerRequest $request, array $args): int|false|null
    {
        $raw = $args['id'] ?? $request->getParsedBodyParam('id');
        return ($raw === null || $raw === '') ? null : filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    /** An absent group means the default; a group that does not exist is refused. */
    private static function groupParam(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return TcpProbeGroup::DEFAULT_ID;
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || TcpProbeGroup::find($id) === null) {
            throw new InvalidArgumentException('检测组不存在');
        }
        return $id;
    }

    public function saveTarget(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbe::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        // The drawer posts to one endpoint and carries the id as a form field; the route arg still wins.
        // Never read it from the query string, and never cast blindly: id[]= would become 1.
        $id = self::rowId($request, $args);
        if ($id === false || ($id !== null && ! DB::table('tcp_probe_target')->where('id', $id)->exists())) {
            return $response->withJson(['ret' => 0, 'msg' => '测试目标不存在'], 404);
        }
        if ($id !== null && ($lock = self::lock($id)) !== null) {
            return $response->withJson(['ret' => 0, 'msg' => $lock], 409);
        }
        try {
            $target = self::validateTarget($request->getParams());
            if (TaierProbeSource::installed()) {
                $target += ['source' => 'manual', 'source_key' => null, 'source_host_id' => null];
            }
            $duplicates = DB::table('tcp_probe_target')->where('ip', $target['ip'])->where('port', $target['port']);
            if (TcpProbeGroup::installed()) {
                $target['group_id'] = self::groupParam($request->getParam('group_id'));
                $duplicates->where('group_id', $target['group_id']);
            }
            if ($id !== null) {
                $duplicates->where('id', '!=', $id);
            }
            if ($duplicates->exists()) {
                throw new InvalidArgumentException('同一组内 IP 和端口不能重复作为多个目标');
            }
            if ($id === null) {
                $id = DB::table('tcp_probe_target')->insertGetId($target);
            } else {
                DB::table('tcp_probe_target')->where('id', $id)->update($target);
            }
        } catch (InvalidArgumentException|UniqueConstraintViolationException $e) {
            $message = $e instanceof InvalidArgumentException ? $e->getMessage() : '同一组内 IP 和端口不能重复作为多个目标';
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
        if (($lock = self::lock((int) $args['id'])) !== null) {
            return $response->withJson(['ret' => 0, 'msg' => $lock], 409);
        }
        if (! DB::table('tcp_probe_target')->where('id', (int) $args['id'])->delete()) {
            return $response->withJson(['ret' => 0, 'msg' => '测试目标不存在'], 404);
        }
        return $response->withJson(['ret' => 1, 'msg' => '测试目标已删除'])->withHeader('HX-Refresh', 'true');
    }

    /** Why a target may not be edited by hand right now, or null when it may. */
    private static function lock(int $id): ?string
    {
        if (! TaierProbeSource::installed()) {
            return null;
        }
        $target = DB::table('tcp_probe_target')->where('id', $id)->first();
        $source = $target->source ?? 'manual';
        if ($source === 'taier' && TaierProbeSource::settings()['enabled']) {
            return '此目标由泰尔自动维护，请先关闭自动同步再修改';
        }
        if ($source === 'url' && TcpProbeGroup::installed() && (TcpProbeGroup::find(TcpProbe::groupOf($target))['url'] ?? '') !== '') {
            return '此目标由所在组的抓取地址自动维护，请修改抓取内容或清空该组的抓取地址';
        }
        return null;
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
        if ($result['ret'] !== 1) {
            return $response->withJson($result, 409);
        }
        return $response->withJson($result)->withHeader('HX-Refresh', 'true');
    }

    public function saveGroup(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbeGroup::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        $id = self::rowId($request, $args);
        if ($id === false || ($id !== null && TcpProbeGroup::find($id) === null)) {
            return $response->withJson(['ret' => 0, 'msg' => '检测组不存在'], 404);
        }
        try {
            $group = TcpProbeGroup::validate($request->getParam('name'), $request->getParam('url', ''), $request->getParam('interval_minutes'));
            $id = TcpProbeGroup::save($id, $group);
        } catch (InvalidArgumentException $e) {
            return $response->withJson(['ret' => 0, 'msg' => $e->getMessage()], 422);
        }
        $message = $group['url'] === '' ? '检测组已保存' : '检测组已保存，定时任务将在五分钟内抓取';
        return $response->withJson(['ret' => 1, 'msg' => $message, 'id' => $id])->withHeader('HX-Refresh', 'true');
    }

    public function deleteGroup(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbeGroup::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        if (TcpProbeGroup::find((int) $args['id']) === null) {
            return $response->withJson(['ret' => 0, 'msg' => '检测组不存在'], 404);
        }
        try {
            TcpProbeGroup::delete((int) $args['id']);
        } catch (InvalidArgumentException $e) {
            return $response->withJson(['ret' => 0, 'msg' => $e->getMessage()], 409);
        }
        return $response->withJson(['ret' => 1, 'msg' => '检测组已删除'])->withHeader('HX-Refresh', 'true');
    }

    public function syncGroup(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbeGroup::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        if (TcpProbeGroup::find((int) $args['id']) === null) {
            return $response->withJson(['ret' => 0, 'msg' => '检测组不存在'], 404);
        }
        $result = (new UrlProbeSource())->syncGroup((int) $args['id'], true);
        if ($result['ret'] !== 1) {
            return $response->withJson($result, 409);
        }
        return $response->withJson($result)->withHeader('HX-Refresh', 'true');
    }

    /** The whole node table saves at once, so one round trip covers every edited row. */
    public function saveNodes(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        if (! TcpProbe::installed()) {
            return $response->withJson(['ret' => 0, 'msg' => '请先运行数据库迁移'], 503);
        }
        $grouped = TcpProbeGroup::installed();
        $thresholds = $request->getParam('threshold_ms');
        $switches = $request->getParam('enabled', []);
        $groups = $grouped ? $request->getParam('group_id') : [];
        // PHP turns canonical numeric keys into ints; anything else (enabled[01]) is not a node id.
        $canonical = static fn (array $fields): bool => array_keys($fields) === array_filter(array_keys($fields), 'is_int');
        if (! is_array($thresholds) || $thresholds === [] || ! is_array($switches) || ! is_array($groups)
            || ! $canonical($thresholds) || ! $canonical($switches) || ! $canonical($groups)) {
            return $response->withJson(['ret' => 0, 'msg' => '提交内容格式错误'], 422);
        }
        // max_input_vars truncates a large form from the tail without failing the request, so the
        // form ends with a node_count sentinel; every node must be present and the count must match.
        $expected = Node::count();
        $ids = array_keys($thresholds);
        if (filter_var($request->getParam('node_count'), FILTER_VALIDATE_INT) !== $expected
            || count($ids) !== $expected || Node::whereIn('id', $ids)->count() !== $expected) {
            return $response->withJson(['ret' => 0, 'msg' => '节点列表已变动或提交不完整，请刷新后重试'], 409);
        }
        $known = $grouped ? array_map('intval', array_column(TcpProbeGroup::all(), 'id')) : [];
        $rows = [];
        foreach ($ids as $id) {
            $threshold = filter_var($thresholds[$id], FILTER_VALIDATE_INT);
            if ($threshold === false || $threshold < 1 || $threshold > 3000) {
                return $response->withJson(['ret' => 0, 'msg' => '节点 #' . $id . ' 的延迟阈值必须为 1–3000 ms'], 422);
            }
            $rows[$id] = ['enabled' => ($switches[$id] ?? '0') === '1', 'threshold_ms' => $threshold];
            if ($grouped) {
                $group = filter_var($groups[$id] ?? null, FILTER_VALIDATE_INT);
                if ($group === false || ! in_array($group, $known, true)) {
                    return $response->withJson(['ret' => 0, 'msg' => '节点 #' . $id . ' 的检测组不存在'], 422);
                }
                $rows[$id]['group_id'] = $group;
            }
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
