<?php

declare(strict_types=1);

namespace App\Utils;

use InvalidArgumentException;

final class TcpProbeTarget
{
    public static function validate(mixed $target): array
    {
        if (! is_array($target) || ! is_string($target['ip'] ?? null)
            || ! is_string($target['label'] ?? null) || ! is_string($target['carrier'] ?? null)
            || ! isset(TcpProbeStatus::CARRIERS[$target['carrier']])) {
            throw new InvalidArgumentException('请选择运营商并填写目标信息');
        }
        $ip = trim($target['ip']);
        $label = trim($target['label']);
        $port = filter_var($target['port'] ?? null, FILTER_VALIDATE_INT);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || str_starts_with($ip, '169.254.') || (int) explode('.', $ip)[0] >= 224
            || $port === false || $port < 1 || $port > 65535 || $label === '' || mb_strlen($label) > 80) {
            throw new InvalidArgumentException('请填写目标名称、公网 IPv4 和 1–65535 的端口');
        }
        return ['carrier' => $target['carrier'], 'label' => $label, 'ip' => $ip, 'port' => $port];
    }
}
