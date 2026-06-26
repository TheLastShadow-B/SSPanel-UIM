<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\SubscriptionService;
use App\Utils\Tools;
use Tests\TestDatabase;

beforeEach(function () {
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

/**
 * 直接创建用户行（仅使用测试 schema 中存在的列）。
 * 注意：tests/Factories/UserFactory 依赖 fakerphp/faker（未安装）且写入了
 * 测试表中不存在的列（username/locale/uuid 等），无法在本环境运行；
 * 与已合并的 P0.1/P0.2/P0.3 测试一致，这里直接用 new User() 建行。
 */
function makeGrantUser(): User
{
    $user = new User();
    $user->email = 'grant_' . bin2hex(random_bytes(6)) . '@example.com';
    $user->user_name = 'grant_test';
    $user->passwd = bin2hex(random_bytes(8));
    $user->class = 0;
    $user->transfer_enable = 0;
    $user->node_group = 0;
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

it('grants membership fields from product content', function () {
    $user = makeGrantUser();

    $content = (object) [
        'bandwidth' => 100,
        'class' => 2,
        'node_group' => 3,
        'speed_limit' => 50,
        'ip_limit' => 5,
    ];

    SubscriptionService::grantMembershipFromContent($user, $content, '2026-12-31 23:59:59');

    $fresh = (new User())->find($user->id);
    expect((int) $fresh->class)->toBe(2)
        ->and((int) $fresh->node_group)->toBe(3)
        ->and((int) $fresh->node_speedlimit)->toBe(50)
        ->and((int) $fresh->node_iplimit)->toBe(5)
        ->and((int) $fresh->transfer_enable)->toBe(Tools::gbToB(100))
        ->and($fresh->class_expire)->toBe('2026-12-31 23:59:59')
        ->and((int) $fresh->u)->toBe(0)
        ->and((int) $fresh->d)->toBe(0)
        ->and((int) $fresh->transfer_today)->toBe(0);
});
