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

    public static function config(int $nodeId, ?array $targets = null, ?object $probe = null): array
    {
        $targets ??= self::targets();
        $probe ??= DB::table('tcp_probe')->where('node_id', $nodeId)->first();
        $config = [
            'enabled' => (bool) ($probe->enabled ?? false),
            'threshold_ms' => (int) ($probe->threshold_ms ?? 250),
            'interval_seconds' => 60, 'timeout_ms' => 3000, 'attempts' => 3,
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
        if (! is_int($time) || $time < $now - 120 || $time > $now + 30) {
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
            $prior = $previous && $previous->config_hash === $config['config_hash'] && $minute === $previous->minute + 1
                ? self::decode($previous)['states'] : [];
            $states = TcpProbeStatus::stabilize(TcpProbeStatus::summarize($results, $config['threshold_ms']), $prior);
            DB::table('tcp_probe_round')->insert([
                'node_id' => $nodeId, 'minute' => $minute, 'measured_at' => $body['measured_at'],
                'config_hash' => $config['config_hash'],
                'results' => json_encode($results, JSON_THROW_ON_ERROR), 'states' => json_encode($states, JSON_THROW_ON_ERROR),
            ]);
        });
    }

    /** Batch reads for the list; no per-node history queries. */
    public static function current(array $nodeIds, int $now): array
    {
        $rows = array_fill_keys($nodeIds, TcpProbeStatus::current(null, $now));
        if ($nodeIds === [] || ! self::installed()) {
            return $rows;
        }
        $targets = self::targets();
        $probes = DB::table('tcp_probe')->whereIn('node_id', $nodeIds)->get()->keyBy('node_id');
        $latest = DB::table('tcp_probe_round')->selectRaw('MAX(id)')->whereIn('node_id', $nodeIds)->groupBy('node_id');
        foreach (DB::table('tcp_probe_round')->whereIn('id', $latest)->get() as $round) {
            $probe = $probes->get($round->node_id);
            if ($probe && $probe->enabled && self::config((int) $round->node_id, $targets, $probe)['config_hash'] === $round->config_hash) {
                $rows[$round->node_id] = TcpProbeStatus::current(self::decode($round), $now);
            }
        }
        return $rows;
    }

    public static function detail(int $nodeId, int $now): array
    {
        $rounds = [];
        $config = ['enabled' => false, 'threshold_ms' => 250];
        if (self::installed()) {
            $config = self::config($nodeId);
            $rounds = DB::table('tcp_probe_round')->where('node_id', $nodeId)
                ->where('measured_at', '>=', intdiv($now, 900) * 900 - 95 * 900)
                ->where('measured_at', '<=', $now)->orderBy('minute')->get()->map(fn ($row) => self::decode($row))->all();
        }
        $latest = $rounds === [] ? null : $rounds[array_key_last($rounds)];
        $fresh = $latest && $config['enabled'] && $latest['config_hash'] === $config['config_hash'] && $now - $latest['measured_at'] <= 180;
        $carriers = TcpProbeStatus::current($fresh ? $latest : null, $now);
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
                $state = $result ? TcpProbeStatus::summarize([$result], $config['threshold_ms'])[$key] : null;
                $status = $state['raw'] ?? 'gray';
                $carrier['targets'][] = [
                    'label' => $target['label'], 'status' => $status, 'status_label' => TcpProbeStatus::LABELS[$status],
                    'latency_ms' => $state['latency_ms'] ?? null,
                    'success' => $state ? $state['success'] . ' / ' . $state['attempts'] : '—',
                ];
            }
        }
        unset($carrier);
        return [
            'carriers' => $carriers, 'enabled' => $config['enabled'], 'threshold_ms' => $config['threshold_ms'],
            'updated_at' => $fresh ? date('m-d H:i:s', $latest['measured_at']) : '暂无有效数据',
        ];
    }

    public static function cleanup(): void
    {
        if (self::installed()) {
            DB::table('tcp_probe_round')->where('measured_at', '<', time() - 7 * 86400)->delete();
        }
    }
}
