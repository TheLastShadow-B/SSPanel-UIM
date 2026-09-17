<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\TaierProbeSource;

final class TcpProbeSync extends Command
{
    public string $description = '├─=: php xcat TcpProbeSync - 立即同步已开启的泰尔 TCP 检测目标';

    public function boot(): void
    {
        $result = (new TaierProbeSource())->sync(true);
        echo $result['msg'] . PHP_EOL;
    }
}
