<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * A probe group is one target list. Every node belongs to exactly one group and its XrayR
 * probes only that group's targets, so relay nodes can watch their relay entry instead of
 * the three-carrier list that direct nodes use.
 */
final class TcpProbeGroup
{
    public const DEFAULT_ID = 1;
    public const MAX_NAME = 40;
    public const MIN_MINUTES = 5;
    public const MAX_MINUTES = 1440;

    public static function installed(): bool
    {
        return DB::getCapsule()->schema()->hasTable('tcp_probe_group');
    }

    public static function all(): array
    {
        return DB::table('tcp_probe_group')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    public static function find(int $id): ?array
    {
        $row = DB::table('tcp_probe_group')->where('id', $id)->first();
        return $row ? (array) $row : null;
    }

    /** Empty url = manual group. Otherwise a public http(s) address the panel may safely fetch on a schedule. */
    public static function validate(mixed $name, mixed $url, mixed $minutes): array
    {
        if (! is_string($name) || ! is_string($url)) {
            throw new InvalidArgumentException('请填写组名称');
        }
        $name = trim($name);
        $url = trim($url);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new InvalidArgumentException('组名称需为 1–' . self::MAX_NAME . ' 个字符');
        }
        if ($url !== '' && ! self::publicUrl($url)) {
            throw new InvalidArgumentException('抓取地址需为公网 http 或 https 地址');
        }
        $minutes = filter_var($minutes, FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
            throw new InvalidArgumentException('抓取间隔需为 ' . self::MIN_MINUTES . '–' . self::MAX_MINUTES . ' 分钟');
        }
        return ['name' => $name, 'url' => $url, 'interval_minutes' => $minutes];
    }

    private static function publicUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (strlen($url) > 255 || ! filter_var($url, FILTER_VALIDATE_URL) || $host === ''
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }
        // A literal address must be public; a hostname is the admin's responsibility.
        return ! filter_var($host, FILTER_VALIDATE_IP)
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** @param array{name: string, url: string, interval_minutes: int} $group already validated */
    public static function save(?int $id, array $group): int
    {
        if ($id === null) {
            return DB::table('tcp_probe_group')->insertGetId($group);
        }
        $current = self::find($id);
        if ($current === null) {
            throw new InvalidArgumentException('检测组不存在');
        }
        if ($group['url'] !== $current['url']) {
            // A new address is fetched by the next cron run; a fetch in flight for the old one is discarded.
            $group += ['next_sync_at' => 0, 'lock_token' => null, 'lock_until' => 0];
        }
        DB::table('tcp_probe_group')->where('id', $id)->update($group);
        return $id;
    }

    public static function delete(int $id): void
    {
        if ($id === self::DEFAULT_ID) {
            throw new InvalidArgumentException('默认组不能删除');
        }
        if (self::find($id) === null) {
            throw new InvalidArgumentException('检测组不存在');
        }
        if (DB::table('tcp_probe_target')->where('group_id', $id)->exists() || DB::table('tcp_probe')->where('group_id', $id)->exists()) {
            throw new InvalidArgumentException('请先删除组内目标并把节点移到其他组');
        }
        DB::table('tcp_probe_group')->where('id', $id)->delete();
    }
}
