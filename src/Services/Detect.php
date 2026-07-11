<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Config;
use App\Models\DetectBanLog;
use App\Models\DetectLog;
use App\Models\Node;
use App\Models\User;
use App\Utils\Tools;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function file_get_contents;
use function in_array;
use function json_decode;
use function str_replace;
use function strtotime;
use function time;
use const PHP_EOL;

final class Detect
{
    /**
     * @throws TelegramSDKException
     */
    public static function gfw(): void
    {
        $nodes = (new Node())->where('type', 1)
            ->where('ipv4', '!=', '127.0.0.1')->where('online', 1)->get();

        foreach ($nodes as $node) {
            $api_url = str_replace(
                ['{ip}', '{port}'],
                [$node->ipv4, $_ENV['detect_gfw_port']],
                $_ENV['detect_gfw_url']
            );

            // API 不可达/返回非法 JSON 时跳过本轮:strict_types 下 json_decode(false)
            // 会抛 TypeError 崩掉整个 cron;误把失败当「被墙」则会批量误报并轰炸管理员邮箱。
            $raw_tcping = @file_get_contents($api_url);
            $json_tcping = $raw_tcping === false ? null : json_decode($raw_tcping, true);

            if (! is_array($json_tcping) || ! isset($json_tcping['status'])) {
                echo '节点 #' . $node->id . ' GFW 检测失败(API 不可达或响应非法),本轮跳过' . PHP_EOL;

                continue;
            }

            $result_tcping = $json_tcping['status'] === 'true';

            if ($result_tcping && ! $node->gfw_block) {
                continue;
            }

            if (! $result_tcping && ! $node->gfw_block) {
                //被墙了
                echo '检测到节点 #' . $node->id . ' 被 GFW 封锁' . PHP_EOL;
                echo 'Send gfw mail to admin' . PHP_EOL;

                try {
                    Notification::notifyAdmin(
                        $_ENV['appName'] . '-系统警告',
                        '管理员你好，系统发现节点 ' . $node->name . ' 被墙了。'
                    );
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }

                if (Config::obtain('im_bot_group_notify_node_gfwed')) {
                    try {
                        Notification::notifyUserGroup(
                            str_replace(
                                '%node_name%',
                                $node->name,
                                I18n::trans('bot.node_gfwed', $_ENV['locale'])
                            ),
                        );
                    } catch (TelegramSDKException | GuzzleException $e) {
                        echo $e->getMessage() . PHP_EOL;
                    }
                }

                $node->gfw_block = true;
                $node->save();

                continue;
            }

            if ($result_tcping && $node->gfw_block) {
                echo '检测到节点 #' . $node->id . ' 被 GFW 解除封锁' . PHP_EOL;
                echo 'Send gfw mail to admin' . PHP_EOL;

                try {
                    Notification::notifyAdmin(
                        $_ENV['appName'] . '-系统提示',
                        '管理员你好，系统发现节点 ' . $node->name . ' 溜出墙了。'
                    );
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }

                if (Config::obtain('im_bot_group_notify_node_ungfwed')) {
                    try {
                        Notification::notifyUserGroup(
                            str_replace(
                                '%node_name%',
                                $node->name,
                                I18n::trans('bot.node_ungfwed', $_ENV['locale'])
                            ),
                        );
                    } catch (TelegramSDKException | GuzzleException $e) {
                        echo $e->getMessage() . PHP_EOL;
                    }
                }

                $node->gfw_block = false;
                $node->save();
            }
        }
    }

    public static function ban(): void
    {
        $new_logs = (new DetectLog())->where('status', '=', 0)->orderBy('id')->get();
        $user_logs = [];

        foreach ($new_logs as $log) {
            // 分类各个用户的记录数量。??= 只在首见该用户时初始化——此前无条件置 0
            // 导致同批次内计数恒为 1,auto_detect_ban_number 阈值语义失效。
            $user_logs[$log->user_id] ??= 0;
            $user_logs[$log->user_id]++;
            $log->status = 1;
            $log->save();
        }

        foreach ($user_logs as $userid => $value) {
            // 执行封禁
            $user = (new User())->find($userid);

            if ($user === null) {
                continue;
            }

            if ($user->is_banned === 1 ||
                ($user->is_admin && $_ENV['auto_detect_ban_allow_admin']) ||
                in_array($user->id, $_ENV['auto_detect_ban_allow_users'])) {
                continue;
            }

            $user->all_detect_number += $value;
            $user->save();

            $last_DetectBanLog = (new DetectBanLog())->where('user_id', $userid)->orderBy('id', 'desc')->first();
            $last_all_detect_number = ((int) $last_DetectBanLog?->all_detect_number);
            $detect_number = $user->all_detect_number - $last_all_detect_number;

            if ($detect_number >= $_ENV['auto_detect_ban_number']) {
                $last_detect_ban_time = $user->last_detect_ban_time;
                $user->is_banned = 1;
                $user->banned_reason = 'DetectBan';
                $user->last_detect_ban_time = Tools::toDateTime(time());
                $user->save();
                $DetectBanLog = new DetectBanLog();
                $DetectBanLog->user_id = $user->id;
                $DetectBanLog->detect_number = $detect_number;
                $DetectBanLog->ban_time = $_ENV['auto_detect_ban_time'];
                $DetectBanLog->start_time = strtotime($last_detect_ban_time);
                $DetectBanLog->end_time = time();
                $DetectBanLog->all_detect_number = $user->all_detect_number;
                $DetectBanLog->save();
            }
        }

        echo Tools::toDateTime(time()) . ' 审计封禁检查结束' . PHP_EOL;

        // 审计封禁解封
        $banned_users = (new User())->where('is_banned', 1)->where('banned_reason', 'DetectBan')->get();

        foreach ($banned_users as $user) {
            $logs = (new DetectBanLog())->where('user_id', $user->id)->orderBy('id', 'desc')->first();
            if ($logs !== null && ($logs->end_time + $logs->ban_time * 60) <= time()) {
                $user->is_banned = 0;
                $user->banned_reason = '';
                $user->save();
            }
        }

        echo Tools::toDateTime(time()) . ' 审计解封检查结束' . PHP_EOL;
    }
}
