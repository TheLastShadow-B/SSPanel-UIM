<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Ann;
use App\Models\Config;
use App\Services\Analytics;
use App\Services\Auth;
use App\Services\Config\ClientConfig;
use App\Services\Subscribe;
use App\Utils\Tools;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function json_encode;
use function strtotime;
use function time;

final class UserController extends BaseController
{
    /**
     * @throws Exception
     */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $traffic_logs = [];
        $class_expire_days = $this->user->class > 0 ?
            round((strtotime($this->user->class_expire) - time()) / 86400) : 0;
        $ann = (new Ann())->where('status', '>', 0)
            ->orderBy('status', 'desc')
            ->orderBy('sort')
            ->orderBy('date', 'desc')->first();

        if (Config::obtain('traffic_log')) {
            $hourly_usage = Analytics::getUserTodayHourlyUsage($this->user->id);

            foreach ($hourly_usage as $hour => $usage) {
                $traffic_logs[] = Tools::bToMB((int) $usage);
            }
        }

        $universalSub = Subscribe::getUniversalSubLink($this->user);
        $r2Enabled = filter_var($_ENV['enable_r2_client_download'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $clientData = ClientConfig::getClients(
            $universalSub,
            $_ENV['appName'] ?? 'SSPanel',
            $r2Enabled
        );

        // 获取当前用户在线IP列表（仅显示5分钟内活跃的连接）
        $online_ips = (new \App\Models\OnlineLog())->where('user_id', $this->user->id)
            ->where('last_time', '>', time() - 300)
            ->orderBy('last_time', 'desc')
            ->get();

        foreach ($online_ips as $online_ip) {
            $formatted_ip = $online_ip->ip();
            $online_ip->node_name = $online_ip->nodeName();
            $online_ip->formatted_ip = $formatted_ip;
            $online_ip->location = Tools::getIpLocation($formatted_ip);
            $online_ip->formatted_time = Tools::toDateTime($online_ip->last_time);
        }

        return $response->write(
            $this->view()
                ->assign('ann', $ann)
                ->assign('traffic_logs', json_encode($traffic_logs))
                ->assign('class_expire_days', $class_expire_days)
                ->assign('UniversalSub', $universalSub)
                ->assign('clientData', json_encode($clientData['clients']))
                ->assign('platformIcons', json_encode($clientData['icons']))
                ->assign('online_ips', $online_ips)
                ->fetch('user/index.tpl')
        );
    }

    /**
     * @throws Exception
     */
    public function announcement(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $anns = (new Ann())->where('status', '>', 0)
            ->orderBy('status', 'desc')
            ->orderBy('sort')
            ->orderBy('date', 'desc')->get();

        return $response->write(
            $this->view()
                ->assign('anns', $anns)
                ->fetch('user/announcement.tpl')
        );
    }

    /**
     * @throws Exception
     */
    public function banned(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $response->write(
            $this->view()
                ->assign('banned_reason', $this->user->banned_reason)
                ->fetch('user/banned.tpl')
        );
    }

    public function logout(ServerRequest $request, Response $response, array $args): Response
    {
        Auth::logout();

        return $response->withStatus(302)->withHeader('Location', '/');
    }
}
