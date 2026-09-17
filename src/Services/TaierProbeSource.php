<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\TcpProbeStatus;
use App\Utils\TcpProbeTarget;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class TaierProbeSource
{
    public const CITIES = ['北京' => '北京', '上海' => '上海', '广州' => '广东'];
    private const BASE = 'https://dlcv2.cnspeedtest.cn:8443';
    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client();
    }

    public static function installed(): bool
    {
        // The newest column stands for the whole feature: a half-migrated table must not be written to.
        return DB::getCapsule()->schema()->hasColumn('tcp_probe_source', 'last_status');
    }

    public static function settings(): array
    {
        $row = self::installed() ? DB::table('tcp_probe_source')->where('id', 1)->first() : null;
        $data = $row ? (array) $row : [
            'enabled' => false, 'cities' => '["北京","上海","广州"]', 'interval_hours' => 6,
            'last_attempt' => 0, 'last_success' => 0, 'next_sync_at' => 0,
            'last_message' => '尚未同步', 'last_status' => 'none', 'lock_until' => 0,
        ];
        $data['cities'] = json_decode($data['cities'], true, 8, JSON_THROW_ON_ERROR);
        return $data;
    }

    public static function saveSettings(bool $enabled, mixed $cities, mixed $hours): void
    {
        if (! is_array($cities) || $cities === [] || count($cities) > count(self::CITIES)
            || count(array_filter($cities, fn ($city) => is_string($city) && isset(self::CITIES[$city]))) !== count($cities)) {
            throw new InvalidArgumentException('请至少选择一个同步地区');
        }
        $hours = filter_var($hours, FILTER_VALIDATE_INT);
        if (! in_array($hours, [6, 12, 24], true)) {
            throw new InvalidArgumentException('同步间隔必须为 6、12 或 24 小时');
        }
        $cities = array_values(array_intersect(array_keys(self::CITIES), $cities));
        DB::table('tcp_probe_source')->where('id', 1)->update([
            'enabled' => $enabled, 'cities' => json_encode($cities, JSON_UNESCAPED_UNICODE),
            'interval_hours' => $hours, 'next_sync_at' => 0,
            // Invalidate any in-flight snapshot based on the previous settings.
            'lock_token' => null, 'lock_until' => 0,
        ]);
    }

    private function options(array $query = []): array
    {
        return [
            'query' => $query, 'timeout' => 6, 'connect_timeout' => 3,
            'allow_redirects' => false, 'http_errors' => false,
            'headers' => ['User-Agent' => 'Dalvik/2.1.0 (Linux; U; Android 14; NE2210 Build/TP1A.220624.014)'],
            'stream' => true, 'read_timeout' => 6,
        ];
    }

    private static function body(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        try {
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('泰尔接口暂时不可用');
            }
            $body = '';
            $deadline = microtime(true) + 6;
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if (($chunk === '' && ! $stream->eof()) || microtime(true) > $deadline) {
                    throw new RuntimeException('泰尔接口响应超时');
                }
                $body .= $chunk;
                if (strlen($body) > 1048576) {
                    throw new RuntimeException('泰尔接口响应过大');
                }
            }
            return $body;
        } finally {
            $stream->close();
        }
    }

    /** Strictly match the requested city/operator; never fall back to a neighbouring city. */
    public static function select(array $rows, string $city, string $carrier, ?string $preferredHostId = null): array
    {
        $matches = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['city'] ?? null) !== $city
                || ($row['pname'] ?? null) !== self::CITIES[$city]
                || ! is_string($row['hostname'] ?? null)
                || ! str_contains($row['hostname'], TcpProbeStatus::CARRIERS[$carrier])) {
                continue;
            }
            $id = $row['hostid'] ?? null;
            if ((! is_string($id) && ! is_int($id)) || ! preg_match('/^[0-9]{1,64}$/D', (string) $id)) {
                continue;
            }
            try {
                $target = TcpProbeTarget::validate([
                    'carrier' => $carrier, 'label' => $row['hostname'],
                    'ip' => $row['hostip'] ?? null, 'port' => $row['port'] ?? null,
                ]);
            } catch (InvalidArgumentException) {
                continue;
            }
            $matches[] = $target + ['source' => 'taier', 'source_key' => $city . ':' . $carrier, 'source_host_id' => (string) $id];
        }
        if ($matches === []) {
            throw new RuntimeException($city . TcpProbeStatus::CARRIERS[$carrier] . ' 没有有效目标，已保留原配置');
        }
        usort($matches, fn ($a, $b) => [
            $a['source_host_id'] !== $preferredHostId, strlen($a['source_host_id']), $a['source_host_id'], $a['ip'], $a['port'],
        ] <=> [
            $b['source_host_id'] !== $preferredHostId, strlen($b['source_host_id']), $b['source_host_id'], $b['ip'], $b['port'],
        ]);
        return $matches[0];
    }

    private function fetch(array $cities): array
    {
        $location = self::body($this->client->request('GET', self::BASE . '/dataServer/getIpLocSP.php', $this->options()));
        $ip = explode('|', trim($location))[0];
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('泰尔接口未返回有效的出口 IPv4');
        }
        $preferred = DB::table('tcp_probe_target')->where('source', 'taier')->pluck('source_host_id', 'source_key')->all();
        $jobs = [];
        foreach ($cities as $city) {
            foreach (TcpProbeStatus::DISPLAY_NAMES as $carrier => $name) {
                $jobs[] = [$city, $carrier];
            }
        }
        $requests = function () use ($jobs, $ip) {
            foreach ($jobs as [$city, $carrier]) {
                yield fn () => $this->client->requestAsync('GET', self::BASE . '/dataServer/mobilematch_many.php', $this->options([
                    'ip' => $ip, 'network' => '4', 'province' => self::CITIES[$city], 'city' => $city,
                    'wifioper' => TcpProbeStatus::CARRIERS[$carrier], 'mobileoperid' => '',
                    'ipv6' => '0', 'model' => 'Android', 'pkg' => 'com.cnspeedtest.globalspeed',
                ]));
            }
        };
        $targets = $errors = [];
        (new Pool($this->client, $requests(), [
            'concurrency' => 3,
            'fulfilled' => function (ResponseInterface $response, int $index) use (&$targets, &$errors, $jobs, $preferred): void {
                [$city, $carrier] = $jobs[$index];
                try {
                    $rows = json_decode(self::body($response), true, 16, JSON_THROW_ON_ERROR);
                    if (! is_array($rows) || ! array_is_list($rows)) {
                        throw new RuntimeException('泰尔接口返回格式错误');
                    }
                    $targets[$index] = self::select($rows, $city, $carrier, $preferred[$city . ':' . $carrier] ?? null);
                } catch (Throwable) {
                    $errors[] = $city . TcpProbeStatus::CARRIERS[$carrier];
                }
            },
            'rejected' => function ($reason, int $index) use (&$errors, $jobs): void {
                [$city, $carrier] = $jobs[$index];
                $errors[] = $city . TcpProbeStatus::CARRIERS[$carrier];
            },
        ]))->promise()->wait();
        if ($errors !== []) {
            throw new RuntimeException('未取得有效目标：' . implode('、', $errors) . '；已保留原配置');
        }
        ksort($targets);
        return array_values($targets);
    }

    /** Called by the existing five-minute Cron, or explicitly from the admin page. */
    public function sync(bool $force = false, ?int $now = null): array
    {
        $now ??= time();
        if (! self::installed()) {
            return ['ret' => 0, 'msg' => '请先运行数据库迁移'];
        }
        $token = bin2hex(random_bytes(16));
        $claim = DB::table('tcp_probe_source')->where('id', 1)->where('enabled', true)->where('lock_until', '<=', $now);
        if (! $force) {
            $claim->where('next_sync_at', '<=', $now);
        }
        if (! $claim->update(['lock_token' => $token, 'lock_until' => $now + 120, 'last_attempt' => $now])) {
            return ['ret' => 0, 'msg' => '自动同步未开启、尚未到期或正在同步'];
        }
        try {
            $settings = self::settings();
            $targets = $this->fetch($settings['cities']);
            return DB::connection()->transaction(function () use ($targets, $token, $now): array {
                $source = DB::table('tcp_probe_source')->where('id', 1)->lockForUpdate()->first();
                if (! $source->enabled || $source->lock_token !== $token) {
                    return ['ret' => 0, 'msg' => '同步设置已改变，本次结果已丢弃'];
                }
                $endpoints = [];
                foreach ($targets as $target) {
                    $endpoint = $target['ip'] . ':' . $target['port'];
                    if (isset($endpoints[$endpoint])) {
                        throw new RuntimeException('泰尔接口返回重复目标，已保留原配置');
                    }
                    $endpoints[$endpoint] = true;
                }
                $existing = DB::table('tcp_probe_target')->where('source', 'taier')->get()->keyBy('source_key');
                $manual = DB::table('tcp_probe_target')->where('source', 'manual')->get()->keyBy(fn ($target) => $target->ip . ':' . $target->port);
                $kept = []; $skipped = 0;
                // Reconcile atomically, preserving IDs, including when two endpoints swap IPs.
                DB::table('tcp_probe_target')->where('source', 'taier')->delete();
                foreach ($targets as $target) {
                    $duplicate = $manual->get($target['ip'] . ':' . $target['port']);
                    if ($duplicate) {
                        if ($duplicate->carrier !== $target['carrier']) {
                            throw new RuntimeException('目标地址与其他运营商的手动目标冲突，已保留原配置');
                        }
                        $skipped++;
                        continue;
                    }
                    if ($old = $existing->get($target['source_key'])) {
                        $target['id'] = $old->id;
                    }
                    $kept[] = DB::table('tcp_probe_target')->insertGetId($target);
                }
                $message = '同步成功：' . count($kept) . ' 个自动目标' . ($skipped ? '，跳过 ' . $skipped . ' 个重复的手动目标' : '');
                DB::table('tcp_probe_source')->where('id', 1)->update([
                    'last_success' => $now, 'next_sync_at' => $now + $source->interval_hours * 3600,
                    'last_message' => $message, 'last_status' => 'ok', 'lock_token' => null, 'lock_until' => 0,
                ]);
                return ['ret' => 1, 'msg' => $message];
            });
        } catch (Throwable $e) {
            // Never persist raw HTTP exception messages: query URLs contain the panel's egress IP.
            $message = $e instanceof RuntimeException && ! $e instanceof \GuzzleHttp\Exception\GuzzleException
                && ! $e instanceof \Illuminate\Database\QueryException ? $e->getMessage() : '泰尔同步失败，已保留原配置';
            DB::table('tcp_probe_source')->where('id', 1)->where('lock_token', $token)->update([
                'next_sync_at' => $now + 900, 'last_message' => mb_substr($message, 0, 255), 'last_status' => 'failed',
                'lock_token' => null, 'lock_until' => 0,
            ]);
            return ['ret' => 0, 'msg' => $message];
        }
    }
}
