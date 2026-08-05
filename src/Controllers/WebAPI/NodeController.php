<?php

declare(strict_types=1);

namespace App\Controllers\WebAPI;

use App\Controllers\BaseController;
use App\Models\Node;
use App\Utils\ResponseHelper;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function is_array;
use function json_decode;
use const VERSION;

final class NodeController extends BaseController
{
    /**
     * GET /mod_mu/nodes/{id}/info
     */
    public function getInfo(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $node_id = $args['id'];
        $node = (new Node())->find($node_id);

        if ($node === null) {
            return ResponseHelper::error($response, 'Node not found.');
        }

        if ($node->type === 0) {
            return ResponseHelper::error($response, 'Node is not enabled.');
        }

        $custom_config = $this->decodeCustomConfig((string) $node->custom_config);

        $data = [
            'node_speedlimit' => $node->node_speedlimit,
            'sort' => $node->sort,
            'server' => $node->server,
            'custom_config' => $this->applyXrayrCompat($custom_config, (int) $node->sort),
            'type' => $_ENV['appName'],
            'version' => $this->convertVersionFormat(VERSION),
        ];

        return ResponseHelper::successWithDataEtag($request, $response, $data);
    }

    /**
     * Decode custom_config JSON, degrading a non-array top-level result to [].
     *
     * json_decode() can return a non-null scalar (e.g. '123' decodes to int(123)) that
     * a bare `?? []` does not catch, and applyXrayrCompat() takes a typed `array`
     * parameter — passing it a scalar throws a TypeError. This runs for every node
     * type, not just sort=12, on every GET /mod_mu/nodes/{id}/info call. Nested
     * scalars inside an already-decoded array degrade gracefully via `??` at each
     * read site in applyXrayrCompat() and the Subscribe generators, and need no
     * equivalent guard.
     */
    private function decodeCustomConfig(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * REALITY 客户端版本下限。
     *
     * Xray 自 commit af7eb680(见 applyXrayrCompat() 注释)起,REALITY 服务端在
     * 未显式配置时会把 minClientVer 默认为 26.3.27,而 mihomo 的 REALITY 客户端
     * 硬编码上报 1.8.2,会被一律拒绝。下发本值覆盖该默认。
     */
    private const REALITY_MIN_CLIENT_VER = '0.0.0';

    /**
     * 为 XrayR 补齐由面板 sort 推导出的开关
     *
     * XrayR 不读面板下发的 sort(api/sspanel/sspanel.go 中 nodeInfoResponse.Sort
     * 从未被引用),协议由其自身 config.yml 的 NodeType 决定。VLESS 入站的开关
     * 是 custom_config.enable_vless,且判等的是字符串 "1"。
     *
     * 由面板注入而非要求 admin 手写,可避免 sort 与 enable_vless 两处声明不一致。
     *
     * REALITY 节点另注入 reality-opts.min_client_ver。2026-08-06 实测:XrayR fork
     * 钉的 xray-core master 快照(Xray 26.7.11)含 commit af7eb680,给 REALITY
     * 服务端加了 `config.MinClientVer = []byte{26, 3, 27}` 的默认值;mihomo 在
     * ClientHello 的 sessionId 里硬编码上报 1.8.2,低于该下限即被判
     * "validation criteria not met" 拒绝,导致全部 Clash 用户 100% 连不上
     * (原生 xray 客户端上报 26.x,不受影响)。同样由面板注入,免得每建一个
     * REALITY 节点都重踩。admin 显式写了就尊重其值。
     */
    private function applyXrayrCompat(array $custom_config, int $sort): array
    {
        if ($sort !== 12) {
            return $custom_config;
        }

        $custom_config['enable_vless'] = '1';

        if (($custom_config['security'] ?? '') === 'reality') {
            $custom_config['enable_reality'] = true;

            // 仅在 admin 已写了 reality-opts 时注入:该键缺失时 XrayR 收到
            // REALITYConfig=nil 会整段跳过 REALITY,而凭空造一个只含
            // min_client_ver 的块会让 xray-core 因缺 privateKey 而构建入站失败。
            if (is_array($custom_config['reality-opts'] ?? null) &&
                ! isset($custom_config['reality-opts']['min_client_ver'])
            ) {
                $custom_config['reality-opts']['min_client_ver'] = self::REALITY_MIN_CLIENT_VER;
            }
        }

        return $custom_config;
    }

    /**
     * Convert version format from YY.M.P to YYYY.M.P for backward compatibility
     * This ensures XrayR and other backends can correctly compare versions
     *
     * @param string $version Version string in YY.M.P format (e.g., "25.1.0")
     *
     * @return string Version string in YYYY.M.P format (e.g., "2025.1.0")
     */
    private function convertVersionFormat(string $version): string
    {
        // Match version pattern: YY.M.P
        if (preg_match('/^(\d{2})\.(\d+)\.(\d+)$/', $version, $matches)) {
            $year = (int) $matches[1];
            $month = $matches[2];
            $patch = $matches[3];

            // Convert 2-digit year to 4-digit year
            // Assume years 00-99 map to 2000-2099
            $fullYear = 2000 + $year;

            return "{$fullYear}.{$month}.{$patch}";
        }

        // If format doesn't match, return original version
        return $version;
    }
}
