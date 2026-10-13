<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\TaierProbeSource;
use App\Services\UrlProbeSource;

final class TcpProbeSync extends Command
{
    public string $description = '├─=: php xcat TcpProbeSync - 立即同步泰尔 TCP 检测目标和各检测组的抓取地址';

    public function boot(): void
    {
        $result = (new TaierProbeSource())->sync(true);
        echo $result['msg'] . PHP_EOL;
        foreach ((new UrlProbeSource())->sync(true) as $result) {
            echo $result['msg'] . PHP_EOL;
        }
    }
}
