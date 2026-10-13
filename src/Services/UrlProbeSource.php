<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\TcpProbeTarget;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Fills a probe group from an address the admin controls. The document is plain text with one
 * target per line, `IP,端口,运营商,名字`; blank lines and lines starting with # are ignored.
 */
final class UrlProbeSource
{
    public const MAX_TARGETS = 100;
    public const MAX_BYTES = 1048576;
    public const RETRY_SECONDS = 900;
    private const CARRIERS = [
        '电信' => 'telecom', 'ct' => 'telecom', 'telecom' => 'telecom',
        '联通' => 'unicom', 'cu' => 'unicom', 'unicom' => 'unicom',
        '移动' => 'mobile', 'cm' => 'mobile', 'mobile' => 'mobile',
    ];
    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client();
    }

    public static function installed(): bool
    {
        return TcpProbeGroup::installed();
    }

    /** @return array{targets: list<array>, skipped: int, truncated: int} */
    public static function parse(string $body): array
    {
        $targets = [];
        $skipped = $truncated = 0;
        foreach (preg_split('/\r\n|\r|\n/', ltrim($body, "\xEF\xBB\xBF")) as $line) {
            $line = trim(str_replace('，', ',', $line));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $fields = array_map('trim', explode(',', $line, 4));
            $carrier = self::CARRIERS[mb_strtolower($fields[2] ?? '')] ?? null;
            $label = mb_substr($fields[3] ?? '', 0, 80);
            try {
                if ($carrier === null) {
                    throw new InvalidArgumentException('缺少运营商');
                }
                $target = TcpProbeTarget::validate([
                    'carrier' => $carrier, 'label' => $label !== '' ? $label : $fields[0] . ':' . $fields[1],
                    'ip' => $fields[0], 'port' => $fields[1],
                ]);
            } catch (InvalidArgumentException) {
                $skipped++;
                continue;
            }
            $endpoint = $target['ip'] . ':' . $target['port'];
            if (isset($targets[$endpoint])) {
                $skipped++;
            } elseif (count($targets) >= self::MAX_TARGETS) {
                $truncated++;
            } else {
                $targets[$endpoint] = $target;
            }
        }
        return ['targets' => array_values($targets), 'skipped' => $skipped, 'truncated' => $truncated];
    }

    private function fetch(string $url): string
    {
        $response = $this->client->request('GET', $url, [
            'timeout' => 10, 'connect_timeout' => 5, 'read_timeout' => 10,
            'allow_redirects' => false, 'http_errors' => false, 'stream' => true,
            'headers' => ['Accept' => 'text/plain, text/csv;q=0.9, */*;q=0.5'],
        ]);
        $stream = $response->getBody();
        try {
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('抓取失败：HTTP ' . $response->getStatusCode() . '，已保留原目标');
            }
            $body = '';
            $deadline = microtime(true) + 10;
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if (($chunk === '' && ! $stream->eof()) || microtime(true) > $deadline) {
                    throw new RuntimeException('抓取超时，已保留原目标');
                }
                $body .= $chunk;
                if (strlen($body) > self::MAX_BYTES) {
                    throw new RuntimeException('抓取内容超过 1 MiB，已保留原目标');
                }
            }
            return $body;
        } finally {
            $stream->close();
        }
    }

    /** Every group with an address that is due (or all of them when forced); called by the five-minute cron. */
    public function sync(bool $force = false, ?int $now = null): array
    {
        $now ??= time();
        if (! self::installed()) {
            return [];
        }
        $due = DB::table('tcp_probe_group')->where('url', '!=', '')->where('lock_until', '<=', $now);
        if (! $force) {
            $due->where('next_sync_at', '<=', $now);
        }
        $results = [];
        foreach ($due->orderBy('id')->pluck('id') as $id) {
            $results[] = $this->syncGroup((int) $id, $force, $now);
        }
        return $results;
    }

    public function syncGroup(int $groupId, bool $force = false, ?int $now = null): array
    {
        $now ??= time();
        if (! self::installed()) {
            return ['ret' => 0, 'msg' => '请先运行数据库迁移'];
        }
        $token = bin2hex(random_bytes(16));
        $claim = DB::table('tcp_probe_group')->where('id', $groupId)->where('url', '!=', '')->where('lock_until', '<=', $now);
        if (! $force) {
            $claim->where('next_sync_at', '<=', $now);
        }
        if (! $claim->update(['lock_token' => $token, 'lock_until' => $now + 120, 'last_attempt' => $now])) {
            return ['ret' => 0, 'msg' => '该组没有抓取地址、尚未到期或正在抓取'];
        }
        try {
            $parsed = self::parse($this->fetch((string) TcpProbeGroup::find($groupId)['url']));
            if ($parsed['targets'] === []) {
                throw new RuntimeException('未解析出有效目标，已保留原目标');
            }
            return DB::connection()->transaction(function () use ($groupId, $parsed, $token, $now): array {
                $group = DB::table('tcp_probe_group')->where('id', $groupId)->lockForUpdate()->first();
                if (! $group || $group->lock_token !== $token) {
                    return ['ret' => 0, 'msg' => '组设置已改变，本次结果已丢弃'];
                }
                $endpoint = static fn ($target) => $target->ip . ':' . $target->port;
                $existing = DB::table('tcp_probe_target')->where('group_id', $groupId)->where('source', 'url')->get()->keyBy($endpoint);
                $others = DB::table('tcp_probe_target')->where('group_id', $groupId)->where('source', '!=', 'url')->get()->keyBy($endpoint);
                // Reconcile atomically, preserving ids so each endpoint keeps its history.
                DB::table('tcp_probe_target')->where('group_id', $groupId)->where('source', 'url')->delete();
                $kept = 0;
                $skipped = $parsed['skipped'];
                foreach ($parsed['targets'] as $target) {
                    $key = $target['ip'] . ':' . $target['port'];
                    if ($others->has($key)) {
                        $skipped++;
                        continue;
                    }
                    $target += ['group_id' => $groupId, 'source' => 'url', 'source_key' => null, 'source_host_id' => null];
                    if ($old = $existing->get($key)) {
                        $target['id'] = $old->id;
                    }
                    DB::table('tcp_probe_target')->insert($target);
                    $kept++;
                }
                if ($kept === 0) {
                    throw new RuntimeException('未解析出有效目标，已保留原目标');
                }
                $message = '抓取成功：' . $kept . ' 个目标' . ($skipped ? '，跳过 ' . $skipped . ' 行' : '')
                    . ($parsed['truncated'] ? '，超出 ' . self::MAX_TARGETS . ' 个上限已截去 ' . $parsed['truncated'] . ' 行' : '');
                DB::table('tcp_probe_group')->where('id', $groupId)->update([
                    'last_success' => $now, 'next_sync_at' => $now + (int) $group->interval_minutes * 60,
                    'last_message' => $message, 'last_status' => 'ok', 'lock_token' => null, 'lock_until' => 0,
                ]);
                return ['ret' => 1, 'msg' => $message];
            });
        } catch (Throwable $e) {
            // Never persist raw HTTP or database exception text.
            $message = $e instanceof RuntimeException && ! $e instanceof GuzzleException && ! $e instanceof QueryException
                ? $e->getMessage() : '抓取失败，已保留原目标';
            DB::table('tcp_probe_group')->where('id', $groupId)->where('lock_token', $token)->update([
                'next_sync_at' => $now + self::RETRY_SECONDS, 'last_message' => mb_substr($message, 0, 255),
                'last_status' => 'failed', 'lock_token' => null, 'lock_until' => 0,
            ]);
            return ['ret' => 0, 'msg' => $message];
        }
    }
}
