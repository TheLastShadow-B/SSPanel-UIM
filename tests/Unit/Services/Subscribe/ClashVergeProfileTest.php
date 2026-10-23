<?php

declare(strict_types=1);

it('limits Global to region groups', function () {
    $before = $_ENV;
    try {
        require BASE_PATH . '/config/clash-verge.php';
        $groups = array_column($_ENV['Clash_Group_Config']['proxy-groups'], null, 'name');

        expect($groups['Global'])->toBe([
            'name' => 'Global',
            'type' => 'select',
            'proxies' => ['HK', 'US', 'JP', 'TW'],
        ]);
    } finally {
        $_ENV = $before;
    }
});
