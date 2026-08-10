<?php

declare(strict_types=1);

namespace App\Services\Subscribe;

use App\Services\Subscribe;
use App\Utils\Tools;
use function array_merge;
use function is_array;
use function json_decode;
use function yaml_emit;
use const YAML_UTF8_ENCODING;

final class Clash extends Base
{
    public function getContent($user): string
    {
        $nodes = [];
        $clash_config = $_ENV['Clash_Config'];
        $clash_group_indexes = $_ENV['Clash_Group_Indexes'];
        $clash_group_config = $_ENV['Clash_Group_Config'];
        $nodes_raw = Subscribe::getUserNodes($user);

        foreach ($nodes_raw as $node_raw) {
            $node_custom_config = json_decode($node_raw->custom_config, true);

            switch ((int) $node_raw->sort) {
                case 0:
                    $plugin = $node_custom_config['plugin'] ?? '';
                    $plugin_option = $node_custom_config['plugin_option'] ?? null;
                    // Clash 特定配置
                    $udp = $node_custom_config['udp'] ?? true;

                    $node = [
                        'name' => $node_raw->name,
                        'type' => 'ss',
                        'server' => $node_raw->server,
                        'port' => (int) $user->port,
                        'password' => $user->passwd,
                        'cipher' => $user->method,
                        'udp' => (bool) $udp,
                        'plugin' => $plugin,
                        'plugin-opts' => $plugin_option,
                    ];

                    break;
                case 1:
                    $ss_2022_port = $node_custom_config['offset_port_user'] ??
                        ($node_custom_config['offset_port_node'] ?? 443);
                    $method = $node_custom_config['method'] ?? '2022-blake3-aes-128-gcm';
                    $user_pk = Tools::genSs2022UserPk($user->passwd, $method);

                    if (! $user_pk) {
                        $node = [];
                        break;
                    }

                    // Clash 特定配置
                    $udp = $node_custom_config['udp'] ?? true;
                    $server_key = $node_custom_config['server_key'] ?? '';
                    $uot = $node_custom_config['uot'] ?? false;

                    $node = [
                        'name' => $node_raw->name,
                        'type' => 'ss',
                        'server' => $node_raw->server,
                        'port' => (int) $ss_2022_port,
                        'password' => $server_key === '' ? $user_pk : $server_key . ':' .$user_pk,
                        'cipher' => $method,
                        'udp' => (bool) $udp,
                        'udp_over_tcp' => (bool) $uot,
                    ];

                    break;
                case 2:
                    $tuic_port = $node_custom_config['offset_port_user'] ??
                        ($node_custom_config['offset_port_node'] ?? 443);
                    $host = $node_custom_config['host'] ?? '';
                    $congestion_control = $node_custom_config['congestion_control'] ?? 'bbr';
                    // Only Clash.Meta core has TUIC support
                    // Tuic V5 Only
                    $node = [
                        'name' => $node_raw->name,
                        'type' => 'tuic',
                        'server' => $node_raw->server,
                        'port' => (int) $tuic_port,
                        'password' => $user->passwd,
                        'uuid' => $user->uuid,
                        'sni' => $host,
                        'congestion-controller' => $congestion_control,
                        'reduce-rtt' => true,
                    ];

                    break;
                case 11:
                    $v2_port = $node_custom_config['offset_port_user'] ??
                        ($node_custom_config['offset_port_node'] ?? 443);
                    $security = $node_custom_config['security'] ?? 'none';
                    $encryption = $node_custom_config['encryption'] ?? 'auto';
                    $network = $node_custom_config['network'] ?? '';
                    $host = $node_custom_config['header']['request']['headers']['Host'][0] ??
                        $node_custom_config['host'] ?? '';
                    $allow_insecure = $node_custom_config['allow_insecure'] ?? false;
                    $tls = $security === 'tls';
                    // Clash 特定配置
                    $udp = $node_custom_config['udp'] ?? true;
                    $ws_opts = $node_custom_config['ws-opts'] ?? $node_custom_config['ws_opts'] ?? null;
                    $h2_opts = $node_custom_config['h2-opts'] ?? $node_custom_config['h2_opts'] ?? null;
                    $http_opts = $node_custom_config['http-opts'] ?? $node_custom_config['http_opts'] ?? null;
                    $grpc_opts = $node_custom_config['grpc-opts'] ?? $node_custom_config['grpc_opts'] ?? null;
                    // HTTPUpgrade 在 Clash.Meta 内核中属于 ws 类型
                    if ($network === 'httpupgrade') {
                        $network = 'ws';
                    }

                    $node = [
                        'name' => $node_raw->name,
                        'type' => 'vmess',
                        'server' => $node_raw->server,
                        'port' => (int) $v2_port,
                        'uuid' => $user->uuid,
                        'alterId' => 0,
                        'cipher' => $encryption,
                        'udp' => (bool) $udp,
                        'tls' => $tls,
                        'skip-cert-verify' => (bool) $allow_insecure,
                        'servername' => $host,
                        'network' => $network,
                        'ws-opts' => $ws_opts,
                        'h2-opts' => $h2_opts,
                        'http-opts' => $http_opts,
                        'grpc-opts' => $grpc_opts,
                    ];

                    break;
                case 12:
                    // json_decode('123', true) returns a non-null, non-array scalar that
                    // `?? []` would not catch, and buildVlessNode()'s typed `array $custom`
                    // parameter would then throw a TypeError.
                    $node = $this->buildVlessNode(
                        $user,
                        $node_raw,
                        is_array($node_custom_config) ? $node_custom_config : []
                    );

                    break;
                case 15:
                    // Hysteria 2 (mihomo / Clash.Meta core)
                    $hy2_port = $node_custom_config['offset_port_user'] ??
                        ($node_custom_config['offset_port_node'] ?? 443);
                    $hy2_opts = $node_custom_config['Hy2Opts'] ?? [];
                    $host = $node_custom_config['host'] ?? '';
                    $up_mbps = $hy2_opts['up_mbps'] ?? 0;
                    $down_mbps = $hy2_opts['down_mbps'] ?? 0;
                    $obfs = $hy2_opts['obfs'] ?? '';
                    $obfs_password = $hy2_opts['obfs_password'] ?? '';
                    $hop_ports = str_replace(' ', '', (string) ($hy2_opts['hop_ports'] ?? ''));
                    $hop_interval = max(5, (int) ($hy2_opts['hop_interval'] ?? 30));

                    $node = [
                        'name' => $node_raw->name,
                        'type' => 'hysteria2',
                        'server' => $node_raw->server,
                        'port' => (int) $hy2_port,
                        'password' => $user->passwd,
                        'sni' => $host,
                        'skip-cert-verify' => false,
                    ];

                    if ($up_mbps > 0) {
                        $node['up'] = (int) $up_mbps;
                    }
                    if ($down_mbps > 0) {
                        $node['down'] = (int) $down_mbps;
                    }
                    if ($obfs !== '' && $obfs_password !== '') {
                        $node['obfs'] = $obfs;
                        $node['obfs-password'] = $obfs_password;

                        // Gecko re-frames QUIC handshake packets into randomly sized
                        // fragments. Each side pads only what it sends and the frame
                        // header carries padLen, so the bounds need not match for
                        // reassembly — but an unset client range leaves the client's
                        // own handshake fragments to mihomo's defaults, which may sit
                        // above the path MTU. Emit them, tracking the backend's
                        // geckoDefault*PacketSize.
                        if ($obfs === 'gecko') {
                            $node['obfs-min-packet-size'] =
                                (int) ($hy2_opts['obfs_min_packet_size'] ?? 600);
                            $node['obfs-max-packet-size'] =
                                (int) ($hy2_opts['obfs_max_packet_size'] ?? 1300);
                        }
                    }
                    if ($hop_ports !== '') {
                        // mihomo 端口跳跃：ports 与 port 并存时以 ports 为准，间隔最小 5 秒
                        $node['ports'] = $hop_ports;
                        $node['hop-interval'] = $hop_interval;
                    }

                    break;
                case 14:
                    $trojan_port = $node_custom_config['offset_port_user'] ??
                        ($node_custom_config['offset_port_node'] ?? 443);
                    $network = $node_custom_config['header']['type'] ?? $node_custom_config['network'] ?? 'tcp';
                    $host = $node_custom_config['host'] ?? '';
                    $allow_insecure = $node_custom_config['allow_insecure'] ?? false;
                    // Clash 特定配置
                    $udp = $node_custom_config['udp'] ?? true;
                    $ws_opts = $node_custom_config['ws-opts'] ?? $node_custom_config['ws_opts'] ?? null;
                    $grpc_opts = $node_custom_config['grpc-opts'] ?? $node_custom_config['grpc_opts'] ?? null;
                    // HTTPUpgrade 在 Clash.Meta 内核中属于 ws 类型
                    if ($network === 'httpupgrade') {
                        $network = 'ws';
                    }

                    $node = [
                        'name' => $node_raw->name,
                        'type' => 'trojan',
                        'server' => $node_raw->server,
                        'sni' => $host,
                        'port' => (int) $trojan_port,
                        'password' => $user->uuid,
                        'network' => $network,
                        'udp' => (bool) $udp,
                        'skip-cert-verify' => (bool) $allow_insecure,
                        'ws-opts' => $ws_opts,
                        'grpc-opts' => $grpc_opts,
                    ];

                    break;
                default:
                    $node = [];
                    break;
            }

            if ($node === []) {
                continue;
            }

            $nodes[] = $node;

            foreach ($clash_group_indexes as $index) {
                $clash_group_config['proxy-groups'][$index]['proxies'][] = $node_raw->name;
            }
        }

        $clash_nodes = [
            'proxies' => $nodes,
        ];

        return yaml_emit(
            array_merge($clash_config, $clash_nodes, $clash_group_config),
            YAML_UTF8_ENCODING
        );
    }

    /**
     * VLESS(sort=12)节点转 mihomo / Clash.Meta proxy 配置
     *
     * REALITY 的公钥由 reality-opts.private_key 推导;后端只需私钥,
     * 客户端只需公钥,故面板不要求 admin 两处各写一遍。
     * 无法得到公钥时返回空数组,由调用方跳过该节点 —— 缺 pbk 的
     * REALITY 配置无法握手,不存在可降级的目标。
     */
    private function buildVlessNode($user, $node_raw, array $custom): array
    {
        $vless_port = $custom['offset_port_user'] ?? ($custom['offset_port_node'] ?? 443);
        $security = $custom['security'] ?? 'none';
        $network = $custom['network'] ?? 'tcp';
        $host = $custom['header']['request']['headers']['Host'][0] ?? $custom['host'] ?? '';
        $allow_insecure = $custom['allow_insecure'] ?? false;
        $flow = (string) ($custom['flow'] ?? '');
        $fingerprint = $custom['fingerprint'] ?? 'chrome';
        // XrayR only reads the hyphenated key (see REALITYConfig in the design doc's XrayR
        // contract table); a `reality_opts` alias here would silently desync the panel's
        // derived client config from what the backend actually applies.
        $reality = $custom['reality-opts'] ?? [];
        // Clash 特定配置
        $udp = $custom['udp'] ?? true;
        $ws_opts = $custom['ws-opts'] ?? $custom['ws_opts'] ?? null;
        $h2_opts = $custom['h2-opts'] ?? $custom['h2_opts'] ?? null;
        $grpc_opts = $custom['grpc-opts'] ?? $custom['grpc_opts'] ?? null;
        // HTTPUpgrade 在 Clash.Meta 内核中属于 ws 类型
        if ($network === 'httpupgrade') {
            $network = 'ws';
        }

        $is_reality = $security === 'reality';

        // XTLS Vision 仅支持裸 TCP，其余传输层丢弃 flow
        if ($network !== 'tcp') {
            $flow = '';
        }

        $node = [
            'name' => $node_raw->name,
            'type' => 'vless',
            'server' => $node_raw->server,
            'port' => (int) $vless_port,
            'uuid' => $user->uuid,
            'udp' => (bool) $udp,
            'tls' => $is_reality || $security === 'tls' || $security === 'xtls',
            'skip-cert-verify' => (bool) $allow_insecure,
            'servername' => $host,
            'network' => $network,
            'client-fingerprint' => $fingerprint,
            'ws-opts' => $ws_opts,
            'h2-opts' => $h2_opts,
            'grpc-opts' => $grpc_opts,
        ];

        if ($is_reality) {
            $public_key = Tools::genRealityPublicKey((string) ($reality['private_key'] ?? ''));

            if ($public_key === '') {
                $public_key = (string) ($reality['public_key'] ?? '');
            }

            if ($public_key === '') {
                return [];
            }

            $node['servername'] = $reality['server_names'][0] ?? $host;
            $node['reality-opts'] = [
                'public-key' => $public_key,
                'short-id' => (string) ($reality['short_ids'][0] ?? ''),
            ];
        }

        if ($flow !== '') {
            $node['flow'] = $flow;
        }

        return $node;
    }
}
