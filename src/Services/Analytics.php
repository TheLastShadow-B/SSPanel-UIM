<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HourlyUsage;
use App\Models\Node;
use App\Models\Paylist;
use App\Models\User;
use App\Utils\Tools;
use function array_fill;
use function array_sum;
use function is_array;
use function date;
use function floatval;
use function is_null;
use function json_decode;
use function round;
use function strtotime;
use function time;

final class Analytics
{
    /**
     * 获取累计收入
     */
    public static function getIncome(string $req): float
    {
        $today = strtotime('00:00:00');
        $paylist = new Paylist();
        $number = match ($req) {
            'today' => $paylist->where('status', 1)
                ->whereBetween('datetime', [$today, time()])
                ->sum('total'),
            'yesterday' => $paylist->where('status', 1)
                ->whereBetween('datetime', [strtotime('-1 day', $today), $today])
                ->sum('total'),
            'this month' => $paylist->where('status', 1)
                ->whereBetween('datetime', [strtotime('first day of this month 00:00:00'), time()])
                ->sum('total'),
            default => $paylist->where('status', 1)->sum('total'),
        };

        return is_null($number) ? 0.00 : round(floatval($number), 2);
    }

    public static function getTotalUser(): int
    {
        return (new User())->count();
    }

    public static function getCheckinUser(): int
    {
        return (new User())->where('last_check_in_time', '>', 0)->count();
    }

    public static function getTodayCheckinUser(): int
    {
        return (new User())->where('last_check_in_time', '>', strtotime('today'))->count();
    }

    public static function getTrafficUsage(): string
    {
        return Tools::autoBytes((new User())->sum('u') + (new User())->sum('d'));
    }

    public static function getTodayTrafficUsage(): string
    {
        return Tools::autoBytes((new User())->sum('transfer_today'));
    }

    public static function getRawTodayTrafficUsage(): int
    {
        return (new User())->sum('transfer_today');
    }

    public static function getRawGbTodayTrafficUsage(): float
    {
        return Tools::bToGB((new User())->sum('transfer_today'));
    }

    public static function getLastTrafficUsage(): string
    {
        return Tools::autoBytes((new User())->sum('u') + (new User())->sum('d') - (new User())->sum('transfer_today'));
    }

    public static function getRawLastTrafficUsage(): int
    {
        return (new User())->sum('u') + (new User())->sum('d') - (new User())->sum('transfer_today');
    }

    public static function getRawGbLastTrafficUsage(): float
    {
        return Tools::bToGB((new User())->sum('u') + (new User())->sum('d') - (new User())->sum('transfer_today'));
    }

    public static function getUnusedTrafficUsage(): string
    {
        return Tools::autoBytes((new User())->sum('transfer_enable') - (new User())->sum('u') - (new User())->sum('d'));
    }

    public static function getRawUnusedTrafficUsage(): int
    {
        return (new User())->sum('transfer_enable') - (new User())->sum('u') - (new User())->sum('d');
    }

    public static function getRawGbUnusedTrafficUsage(): float
    {
        return Tools::bToGB((new User())->sum('transfer_enable') - (new User())->sum('u') - (new User())->sum('d'));
    }

    public static function getTotalTraffic(): string
    {
        return Tools::autoBytes((new User())->sum('transfer_enable'));
    }

    public static function getRawTotalTraffic(): int
    {
        return (new User())->sum('transfer_enable');
    }

    public static function getRawGbTotalTraffic(): float
    {
        return Tools::bToGB((new User())->sum('transfer_enable'));
    }

    public static function getTotalNode(): int
    {
        return (new Node())->where('node_heartbeat', '>', 0)->count();
    }

    public static function getAliveNode(): int
    {
        return (new Node())->where('node_heartbeat', '>', time() - 90)->count();
    }

    public static function getInactiveUser(): int
    {
        return (new User())->where('is_inactive', 1)->count();
    }

    public static function getActiveUser(): int
    {
        return (new User())->where('is_inactive', 0)->count();
    }

    /**
     * 近 N 天(含今天)每日收入,按天补零。
     *
     * @return array<int, array{date: string, value: float}>
     */
    public static function getIncomeTrend(int $days = 14): array
    {
        $start = strtotime('00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $buckets = [];

        for ($i = 0; $i < $days; $i++) {
            $buckets[date('m-d', strtotime("+{$i} days", $start))] = 0.0;
        }

        $rows = (new Paylist())->where('status', 1)
            ->where('datetime', '>=', $start)
            ->get(['total', 'datetime']);

        foreach ($rows as $row) {
            $key = date('m-d', (int) $row->datetime);
            if (isset($buckets[$key])) {
                $buckets[$key] += (float) $row->total;
            }
        }

        $trend = [];
        foreach ($buckets as $date => $value) {
            $trend[] = ['date' => $date, 'value' => round($value, 2)];
        }

        return $trend;
    }

    /**
     * 近 N 天(含今天)全站每日流量(GB),按天补零。
     *
     * @return array<int, array{date: string, value: float}>
     */
    public static function getTrafficTrend(int $days = 14): array
    {
        $start_day = strtotime('-' . ($days - 1) . ' days');
        $buckets = [];

        for ($i = 0; $i < $days; $i++) {
            $buckets[date('Y-m-d', strtotime("+{$i} days", $start_day))] = 0;
        }

        $rows = (new HourlyUsage())->where('date', '>=', date('Y-m-d', $start_day))
            ->get(['date', 'usage']);

        foreach ($rows as $row) {
            $key = $row->date;
            if (isset($buckets[$key])) {
                $hours = json_decode($row->usage, true);
                if (is_array($hours)) {
                    $buckets[$key] += array_sum($hours);
                }
            }
        }

        $trend = [];
        foreach ($buckets as $date => $bytes) {
            $trend[] = ['date' => date('m-d', strtotime($date)), 'value' => Tools::bToGB((int) $bytes)];
        }

        return $trend;
    }

    /**
     * 近 N 天(含今天)每日新注册用户数,按天补零。
     *
     * @return array<int, array{date: string, value: int}>
     */
    public static function getRegTrend(int $days = 14): array
    {
        $start = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $buckets = [];

        for ($i = 0; $i < $days; $i++) {
            $buckets[date('m-d', strtotime("+{$i} days", strtotime($start)))] = 0;
        }

        $rows = (new User())->where('reg_date', '>=', $start)->get(['reg_date']);

        foreach ($rows as $row) {
            $key = date('m-d', strtotime((string) $row->reg_date));
            if (isset($buckets[$key])) {
                $buckets[$key]++;
            }
        }

        $trend = [];
        foreach ($buckets as $date => $value) {
            $trend[] = ['date' => $date, 'value' => $value];
        }

        return $trend;
    }

    public static function getUserHourlyUsage(int $user_id, string $date): array
    {
        $hourly_usage = (new HourlyUsage())->where('user_id', $user_id)->where('date', $date)->first();

        return $hourly_usage ? json_decode($hourly_usage->usage, true) : array_fill(0, 24, 0);
    }

    public static function getUserTodayHourlyUsage(int $user_id): array
    {
        $date = date('Y-m-d');

        return self::getUserHourlyUsage($user_id, $date);
    }
}
