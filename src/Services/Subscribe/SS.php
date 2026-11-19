<?php

declare(strict_types=1);

namespace App\Services\Subscribe;

use App\Models\Config;
use App\Services\Subscribe;
use App\Utils\Tools;
use function base64_encode;
use function json_decode;
use const PHP_EOL;

final class SS extends Base
{
    public function getContent($user): string
    {
        $links = '';
        //判断是否开启SS订阅
        if (! Config::obtain('enable_ss_sub')) {
            return $links;
        }

        $nodes_raw = Subscribe::getUserNodes($user);

        foreach ($nodes_raw as $node_raw) {
            $node_custom_config = json_decode($node_raw->custom_config, true);

            // Shadowsocks
            if ((int) $node_raw->sort === 0) {
                $links .= base64_encode($user->method . ':' . $user->passwd . '@' . $node_raw->server . ':' . $user->port) . '#' .
                    $node_raw->name . PHP_EOL;
            }

            // Shadowsocks 2022
            if ((int) $node_raw->sort === 1) {
                $ss_2022_port = $node_custom_config['offset_port_user'] ??
                    ($node_custom_config['offset_port_node'] ?? 443);
                $method = $node_custom_config['method'] ?? '2022-blake3-aes-128-gcm';
                
                // 生成用户私钥
                $user_pk = Tools::genSs2022UserPk($user->passwd, $method);

                if (! $user_pk) {
                    continue;
                }

                $server_key = $node_custom_config['server_key'] ?? '';
                // 组合密码：如果有 server_key，格式为 server_key:user_pk，否则仅 user_pk
                $password = $server_key === '' ? $user_pk : $server_key . ':' . $user_pk;

                $links .= base64_encode($method . ':' . $password . '@' . $node_raw->server . ':' . $ss_2022_port) . '#' .
                    $node_raw->name . PHP_EOL;
            }
        }

        return $links;
    }
}
