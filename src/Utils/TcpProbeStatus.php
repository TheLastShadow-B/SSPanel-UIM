<?php

declare(strict_types=1);

namespace App\Utils;

final class TcpProbeStatus
{
    public const CARRIERS = ['telecom' => '电信', 'unicom' => '联通', 'mobile' => '移动'];
    public const DISPLAY_NAMES = ['telecom' => 'CT', 'mobile' => 'CM', 'unicom' => 'CU'];
    public const LABELS = ['green' => '正常', 'yellow' => '波动 / 延迟偏高', 'red' => '连接中断', 'gray' => '暂无有效数据'];

    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        return round(count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2, 2);
    }

    /** Results have already been validated against the configured target list. */
    public static function summarize(array $results, int $threshold): array
    {
        $states = [];
        foreach (self::CARRIERS as $carrier => $name) {
            $targets = array_values(array_filter($results, fn ($target) => $target['carrier'] === $carrier));
            $attempts = $success = 0;
            $latencies = [];
            $slow = false;
            foreach ($targets as $target) {
                $values = [];
                foreach ($target['samples'] as $sample) {
                    $attempts++;
                    if ($sample['error'] === null) {
                        $success++;
                        $values[] = $sample['latency_ms'];
                        $latencies[] = $sample['latency_ms'];
                    }
                }
                $slow = $slow || (self::median($values) ?? 0) > $threshold;
            }
            $raw = $attempts === 0 ? 'gray' : ($success === 0 ? 'red' : ($success < $attempts || $slow ? 'yellow' : 'green'));
            $states[$carrier] = [
                'raw' => $raw, 'status' => $raw, 'streak' => 1,
                'success' => $success, 'attempts' => $attempts,
                'latency_ms' => self::median($latencies), 'targets' => count($targets),
            ];
        }
        return $states;
    }

    /** Consecutive minute reports only; gaps/config changes reset confirmation. */
    public static function stabilize(array $states, array $previous): array
    {
        foreach ($states as $carrier => &$state) {
            $prior = $previous[$carrier] ?? ['raw' => 'gray', 'status' => 'gray', 'streak' => 0];
            $state['streak'] = $state['raw'] === $prior['raw'] ? min(3, $prior['streak'] + 1) : 1;
            $required = $state['raw'] === 'red' ? 3 : 2;
            $state['status'] = $state['raw'] === 'gray' ? 'gray' : (
                $state['streak'] >= $required ? $state['raw'] : $prior['status']
            );
        }
        unset($state);
        return $states;
    }

    /**
     * Current per-carrier state for the user pages. There is no "no data" state here:
     * a missing or stale report means the node is unreachable and reads as 中断, and a
     * fresh but not yet confirmed round shows its raw reading instead of a placeholder.
     */
    public static function current(?array $round, int $now, int $staleAfter = 180): array
    {
        $rows = [];
        $fresh = $round !== null && $now - $round['measured_at'] <= $staleAfter;
        foreach (self::DISPLAY_NAMES as $carrier => $name) {
            $state = $fresh ? ($round['states'][$carrier] ?? null) : null;
            $status = $state['status'] ?? 'gray';
            if ($status === 'gray') {
                $status = $state['raw'] ?? 'gray';
            }
            if ($status === 'gray') {
                $status = 'red';
            }
            $rows[$carrier] = [
                'carrier' => $carrier, 'name' => $name, 'status' => $status,
                'label' => self::LABELS[$status], 'latency_ms' => $state['latency_ms'] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * 96 quarter-hour buckets, including the current partial one. With $live (the carrier's current
     * state) the partial bucket never turns coverage-gray: it shows the live state unless a confirmed
     * yellow/red sample already landed in it.
     */
    public static function history(array $rounds, string $carrier, int $now, ?string $live = null): array
    {
        $start = intdiv($now, 900) * 900 - 95 * 900;
        $groups = [];
        $valid = $available = $coveredSeconds = 0;
        foreach ($rounds as $index => $round) {
            $state = $round['states'][$carrier] ?? null;
            if ($round['measured_at'] < $start || $round['measured_at'] > $now || ! $state || $state['attempts'] === 0) {
                continue;
            }
            // Distribute a scheduled round across every history bucket it covers.
            // Stop at the next round so config changes cannot double-count coverage.
            $from = intdiv($round['measured_at'], 60) * 60;
            $until = min($from + ($state['interval_seconds'] ?? 60), (intdiv($now, 60) + 1) * 60);
            if (isset($rounds[$index + 1])) {
                $until = min($until, intdiv($rounds[$index + 1]['measured_at'], 60) * 60);
            }
            for ($i = max(0, intdiv($from - $start, 900)); $i < 96 && $start + $i * 900 < $until; $i++) {
                $state['coverage_seconds'] = max(0, min($until, $start + ($i + 1) * 900) - max($from, $start + $i * 900));
                $coveredSeconds += $state['coverage_seconds'];
                $groups[$i][] = $state;
            }
            $valid++;
            $available += $state['success'] > 0 ? 1 : 0;
        }
        $buckets = [];
        for ($i = 0; $i < 96; $i++) {
            $from = $start + $i * 900;
            $to = min($from + 900, $now);
            $expected = max(1, (int) ceil(($to - $from) / 60));
            $samples = $groups[$i] ?? [];
            $colors = array_column($samples, 'status');
            $coverage = min(100, (int) round((array_sum(array_column($samples, 'coverage_seconds')) / 60) / $expected * 100));
            $status = in_array('red', $colors, true) ? 'red' : (in_array('yellow', $colors, true) ? 'yellow' : (
                $coverage >= 80 && ! in_array('gray', $colors, true) && $samples !== [] ? 'green' : 'gray'
            ));
            $success = array_sum(array_column($samples, 'success'));
            $attempts = array_sum(array_column($samples, 'attempts'));
            $rate = $attempts ? round($success / $attempts * 100, 1) . '%' : '—';
            if ($i === 95 && $live !== null) {
                $status = in_array($status, ['red', 'yellow'], true) ? $status : $live;
                $label = date('m-d H:i', $from) . ' – 现在 · ' . self::LABELS[$status] . ' · 正在检测 · 连接成功率 ' . $rate;
            } else {
                $label = date('m-d H:i', $from) . ' – ' . date('H:i', $from + 900) . ' · ' . self::LABELS[$status]
                    . ' · 连接成功率 ' . $rate . ' · 数据覆盖率 ' . $coverage . '%';
            }
            $buckets[] = ['status' => $status, 'label' => $label];
        }
        return [
            'buckets' => $buckets,
            'uptime' => $valid ? round($available / $valid * 100, 2) . '%' : '—',
            'coverage' => min(100, round(($coveredSeconds / 60) / max(1, ceil(($now - $start) / 60)) * 100, 1)),
            'start' => date('m-d H:i', $start), 'end' => date('m-d H:i', $now),
        ];
    }
}
