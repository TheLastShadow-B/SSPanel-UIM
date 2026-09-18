<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\TcpProbeStatus;
use InvalidArgumentException;

final class TcpProbe
{
    public static function installed(): bool
    {
        return DB::getCapsule()->schema()->hasTable('tcp_probe_round');
    }

    public static function targets(): array
    {
        return DB::table('tcp_probe_target')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    /** Six workers, up to ten seconds per target, with time reserved for API calls. */
    public static function interval(int $targetCount): int
    {
        return max(60, (int) ceil((ceil($targetCount / 6) * 10 + 15) / 60) * 60);
    }

    public static function config(int $nodeId, ?array $targets = null, ?object $probe = null): array
    {
        $targets ??= self::targets();
        // Source metadata must not invalidate probes when endpoints are unchanged.
        $targets = array_map(fn ($target) => array_intersect_key($target, array_flip(['id', 'carrier', 'label', 'ip', 'port'])), $targets);
        $probe ??= DB::table('tcp_probe')->where('node_id', $nodeId)->first();
        $config = [
            'enabled' => (bool) ($probe->enabled ?? false),
            'threshold_ms' => (int) ($probe->threshold_ms ?? 250),
            'interval_seconds' => self::interval(count($targets)), 'timeout_ms' => 3000, 'attempts' => 3,
            'targets' => $targets,
        ];
        $config['config_hash'] = hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
        return $config;
    }

    public static function decode(object $row): array
    {
        $round = (array) $row;
        $round['states'] = json_decode($round['states'], true, 32, JSON_THROW_ON_ERROR);
        $round['results'] = json_decode($round['results'], true, 32, JSON_THROW_ON_ERROR);
        return $round;
    }

    public static function validateReport(array $body, array $config, int $now): array
    {
        if (($body['config_hash'] ?? '') !== $config['config_hash']) {
            throw new InvalidArgumentException('检测配置已更新，请重新获取配置');
        }
        $time = $body['measured_at'] ?? null;
        if (! is_int($time) || $time < $now - max(120, $config['interval_seconds'] + 30) || $time > $now + 30) {
            throw new InvalidArgumentException('检测时间无效，请同步节点时钟');
        }
        $reports = $body['results'] ?? null;
        if (! is_array($reports) || ! array_is_list($reports) || count($reports) !== count($config['targets'])) {
            throw new InvalidArgumentException('必须上报全部已配置目标');
        }
        $targets = array_column($config['targets'], null, 'id');
        $results = [];
        foreach ($reports as $report) {
            $id = is_array($report) ? ($report['target_id'] ?? null) : null;
            if (! is_int($id) || ! isset($targets[$id])) {
                throw new InvalidArgumentException('目标不存在或重复');
            }
            $samples = $report['samples'] ?? null;
            if (! is_array($samples) || ! array_is_list($samples) || count($samples) !== 3) {
                throw new InvalidArgumentException('每个目标需要 3 次检测结果');
            }
            foreach ($samples as $sample) {
                if (! is_array($sample) || ! array_key_exists('error', $sample) || ! array_key_exists('latency_ms', $sample)) {
                    throw new InvalidArgumentException('检测样本格式错误');
                }
                $error = $sample['error'];
                $latency = $sample['latency_ms'];
                if ($error === null) {
                    if ((! is_int($latency) && ! is_float($latency)) || ! is_finite((float) $latency) || $latency < 0 || $latency > 3500) {
                        throw new InvalidArgumentException('连接耗时无效');
                    }
                } elseif (! in_array($error, ['timeout', 'refused', 'unreachable', 'other'], true) || $latency !== null) {
                    throw new InvalidArgumentException('连接错误类型无效');
                }
            }
            $target = $targets[$id];
            // Keep only protocol fields, never persist arbitrary client data.
            $results[] = [
                'target_id' => $id, 'carrier' => $target['carrier'], 'label' => $target['label'],
                'samples' => array_map(fn ($s) => ['latency_ms' => $s['latency_ms'], 'error' => $s['error']], $samples),
            ];
            unset($targets[$id]);
        }
        return $results;
    }

    public static function report(int $nodeId, array $body, int $now): void
    {
        DB::connection()->transaction(function () use ($nodeId, $body, $now): void {
            $probe = DB::table('tcp_probe')->where('node_id', $nodeId)->lockForUpdate()->first();
            if (! $probe || ! $probe->enabled) {
                throw new InvalidArgumentException('节点未开启回国检测');
            }
            $config = self::config($nodeId, null, $probe);
            $results = self::validateReport($body, $config, $now);
            $minute = intdiv($body['measured_at'], 60);
            $previous = DB::table('tcp_probe_round')->where('node_id', $nodeId)->orderByDesc('minute')->first();
            // Idempotency and monotonicity: a retry/late packet cannot overwrite newer status.
            if ($previous && $minute <= $previous->minute) {
                return;
            }
            $prior = $previous && $previous->config_hash === $config['config_hash'] && $minute === $previous->minute + intdiv($config['interval_seconds'], 60)
                ? self::decode($previous)['states'] : [];
            $states = TcpProbeStatus::stabilize(TcpProbeStatus::summarize($results, $config['threshold_ms']), $prior);
            foreach ($states as &$state) {
                $state['interval_seconds'] = $config['interval_seconds'];
            }
            unset($state);
            DB::table('tcp_probe_round')->insert([
                'node_id' => $nodeId, 'minute' => $minute, 'measured_at' => $body['measured_at'],
                'config_hash' => $config['config_hash'],
                'results' => json_encode($results, JSON_THROW_ON_ERROR), 'states' => json_encode($states, JSON_THROW_ON_ERROR),
            ]);
        });
    }

    /** Carriers with at least one target, in display order; the user list only shows these columns. */
    public static function carriers(): array
    {
        if (! self::installed()) {
            return [];
        }
        return array_intersect_key(TcpProbeStatus::CARRIERS, array_flip(array_column(self::targets(), 'carrier')));
    }

    /** Batch reads for the list; null marks a node that is not being probed at all. */
    public static function current(array $nodeIds, int $now): array
    {
        $rows = array_fill_keys($nodeIds, null);
        if ($nodeIds === [] || ! self::installed()) {
            return $rows;
        }
        $stale = 3 * self::interval(count(self::targets()));
        $probes = DB::table('tcp_probe')->whereIn('node_id', $nodeIds)->get()->keyBy('node_id');
        foreach ($nodeIds as $nodeId) {
            if ($probes->get($nodeId)?->enabled) {
                $rows[$nodeId] = TcpProbeStatus::current(null, $now, $stale);
            }
        }
        $latest = DB::table('tcp_probe_round')->selectRaw('MAX(id)')->whereIn('node_id', $nodeIds)->groupBy('node_id');
        foreach (DB::table('tcp_probe_round')->whereIn('id', $latest)->get() as $round) {
            // A round recorded before a target edit is still a real measurement while it is fresh by time.
            if ($rows[$round->node_id] !== null) {
                $rows[$round->node_id] = TcpProbeStatus::current(self::decode($round), $now, $stale);
            }
        }
        return $rows;
    }

    /**
     * One pass over the newest round of every enabled node, for the admin console:
     * a carrier rollup across nodes, per-target reachability, and per-node indicators.
     */
    public static function overview(int $now): array
    {
        $overview = ['carriers' => [], 'nodes' => [], 'targets' => [], 'measured_at' => null, 'reporting' => 0, 'enabled' => 0, 'status' => 'gray'];
        foreach (TcpProbeStatus::DISPLAY_NAMES as $carrier => $code) {
            $overview['carriers'][$carrier] = [
                'carrier' => $carrier, 'code' => $code, 'name' => TcpProbeStatus::CARRIERS[$carrier],
                'status' => 'gray', 'label' => TcpProbeStatus::LABELS['gray'], 'latency_ms' => null,
                'targets' => 0, 'counts' => ['green' => 0, 'yellow' => 0, 'red' => 0, 'gray' => 0],
            ];
        }
        if (! self::installed()) {
            return $overview;
        }
        $targets = self::targets();
        foreach ($targets as $target) {
            $overview['targets'][(int) $target['id']] = [
                'ok' => 0, 'reached' => 0, 'total' => 0, 'latency_ms' => null,
                'status' => 'gray', 'label' => TcpProbeStatus::LABELS['gray'],
            ];
            $overview['carriers'][$target['carrier']]['targets']++;
        }
        // Deleting a node leaves its probe rows behind; only nodes that still exist count.
        $probes = DB::table('tcp_probe')->where('enabled', true)
            ->whereIn('node_id', DB::table('node')->select('id'))->get()->keyBy('node_id');
        $overview['enabled'] = $probes->count();
        $latencies = array_fill_keys(array_keys($overview['carriers']), []);
        $reach = [];
        if ($probes->isNotEmpty()) {
            $stale = 3 * self::interval(count($targets));
            $latest = DB::table('tcp_probe_round')->selectRaw('MAX(id)')->whereIn('node_id', $probes->keys()->all())->groupBy('node_id');
            foreach (DB::table('tcp_probe_round')->whereIn('id', $latest)->get() as $row) {
                if ($now - $row->measured_at > $stale) {
                    continue;
                }
                $probe = $probes->get($row->node_id);
                $round = self::decode($row);
                $overview['reporting']++;
                $overview['measured_at'] = max($overview['measured_at'] ?? 0, $round['measured_at']);
                // A node still probing an outdated config is alive, but nothing it reports is valid yet.
                $valid = self::config((int) $row->node_id, $targets, $probe)['config_hash'] === $row->config_hash;
                $overview['nodes'][(int) $row->node_id] = [
                    'measured_at' => $round['measured_at'],
                    'states' => $valid ? array_map(static fn ($state) => $state['status'], $round['states'])
                        : array_fill_keys(array_keys(TcpProbeStatus::CARRIERS), 'gray'),
                ];
                foreach ($overview['nodes'][(int) $row->node_id]['states'] as $carrier => $status) {
                    if (isset($overview['carriers'][$carrier])) {
                        $overview['carriers'][$carrier]['counts'][$status]++;
                    }
                }
                if (! $valid) {
                    continue;
                }
                foreach ($round['states'] as $carrier => $state) {
                    // Unconfirmed (gray) rounds have a median too, but the card says "no valid data".
                    if (isset($overview['carriers'][$carrier]) && $state['status'] !== 'gray' && $state['latency_ms'] !== null) {
                        $latencies[$carrier][] = $state['latency_ms'];
                    }
                }
                foreach ($round['results'] as $result) {
                    $id = $result['target_id'];
                    if (! isset($overview['targets'][$id])) {
                        continue;
                    }
                    $values = array_column(array_filter($result['samples'], static fn ($s) => $s['error'] === null), 'latency_ms');
                    $overview['targets'][$id]['total']++;
                    if ($values === []) {
                        continue;
                    }
                    $median = TcpProbeStatus::median($values);
                    $reach[$id][] = $median;
                    $overview['targets'][$id]['reached']++;
                    // Same rule as TcpProbeStatus::summarize(): every sample connected and the node's own threshold met.
                    if (count($values) === count($result['samples']) && $median <= $probe->threshold_ms) {
                        $overview['targets'][$id]['ok']++;
                    }
                }
            }
        }
        $ranks = ['gray' => 0, 'green' => 1, 'yellow' => 2, 'red' => 3];
        $worst = 0;
        foreach ($overview['carriers'] as $carrier => &$row) {
            // Worst status wins, so one broken node is never hidden behind healthy ones.
            $counts = $row['counts'];
            $row['status'] = $counts['red'] ? 'red' : ($counts['yellow'] ? 'yellow' : ($counts['green'] ? 'green' : 'gray'));
            $row['label'] = TcpProbeStatus::LABELS[$row['status']];
            $row['latency_ms'] = TcpProbeStatus::median($latencies[$carrier]);
            $worst = max($worst, $ranks[$row['status']]);
        }
        unset($row);
        foreach ($overview['targets'] as $id => &$row) {
            $row['status'] = $row['total'] === 0 ? 'gray'
                : ($row['reached'] === 0 ? 'red' : ($row['ok'] < $row['total'] ? 'yellow' : 'green'));
            $row['label'] = TcpProbeStatus::LABELS[$row['status']];
            $row['latency_ms'] = TcpProbeStatus::median($reach[$id] ?? []);
        }
        unset($row);
        // The header grades liveness as well as health: an enabled node that stopped reporting is a problem too.
        if ($overview['reporting'] > 0) {
            $overview['status'] = array_search(max($worst, $overview['reporting'] < $overview['enabled'] ? 2 : 1), $ranks, true);
        }
        return $overview;
    }

    public static function detail(int $nodeId, int $now): array
    {
        $rounds = [];
        $config = ['enabled' => false, 'threshold_ms' => 250, 'interval_seconds' => 60];
        if (self::installed()) {
            $config = self::config($nodeId);
            $rounds = DB::table('tcp_probe_round')->where('node_id', $nodeId)
                ->where('measured_at', '>=', intdiv($now, 900) * 900 - 95 * 900)
                ->where('measured_at', '<=', $now)->orderBy('minute')->get()->map(fn ($row) => self::decode($row))->all();
        }
        $latest = $rounds === [] ? null : $rounds[array_key_last($rounds)];
        $fresh = $latest && $config['enabled'] && $now - $latest['measured_at'] <= 3 * $config['interval_seconds'];
        $carriers = TcpProbeStatus::current($fresh ? $latest : null, $now, 3 * $config['interval_seconds']);
        foreach ($carriers as $key => &$carrier) {
            $carrier['history'] = TcpProbeStatus::history($rounds, $key, $now);
            $carrier['targets'] = [];
            foreach (($config['targets'] ?? []) as $target) {
                if ($target['carrier'] !== $key) {
                    continue;
                }
                $result = null;
                foreach (($fresh ? $latest['results'] : []) as $candidate) {
                    if ($candidate['target_id'] === (int) $target['id']) {
                        $result = $candidate;
                        break;
                    }
                }
                if ($fresh && $result === null) {
                    continue; // added after the last report; it shows up with the next round
                }
                $state = $result ? TcpProbeStatus::summarize([$result], $config['threshold_ms'])[$key] : null;
                $status = $state['raw'] ?? 'gray';
                if ($status === 'gray') {
                    $status = 'red'; // stale or no successful sample: the user page has no "no data" state
                }
                $carrier['targets'][] = [
                    'label' => $target['label'], 'status' => $status, 'status_label' => TcpProbeStatus::LABELS[$status],
                    'latency_ms' => $state['latency_ms'] ?? null,
                    'success' => $state ? $state['success'] . ' / ' . $state['attempts'] : '—',
                ];
            }
        }
        unset($carrier);
        return [
            'interval_seconds' => $config['interval_seconds'],
            'carriers' => $carriers, 'enabled' => $config['enabled'], 'threshold_ms' => $config['threshold_ms'],
            'updated_at' => $latest ? date('m-d H:i:s', $latest['measured_at']) : '—',
        ];
    }

    public static function cleanup(): void
    {
        if (self::installed()) {
            DB::table('tcp_probe_round')->where('measured_at', '<', time() - 7 * 86400)->delete();
        }
    }
}
