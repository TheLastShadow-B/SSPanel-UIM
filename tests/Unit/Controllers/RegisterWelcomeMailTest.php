<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Models\EmailQueue;
use App\Models\User;
use Tests\TestDatabase;

/*
 * ---------------------------------------------------------------------------
 * 注册成功(非管理员创建)后应入队 new_user.tpl 欢迎邮件。
 *
 * 降级说明(按 task-2-brief.md Step 5 预案):registerHelper 的真实签名已核实
 * (src/Controllers/AuthController.php:218 —
 *  registerHelper(Response, $name, $email, $password, $invite_code, $imtype,
 *  $imvalue, $money, $is_admin_reg)),可脱离 HTTP 层直调。但直调 registerHelper
 * 会触发 $user->save() 写入完整生产字段集(pass/uuid/ref_by/daily_mail_enable/
 * remark/auto_reset_day/api_token/locale/last_login_time 等九个以上字段,见
 * db/migrations/2023020100-init.php 的 `user` 表定义),而 tests/TestDatabase.php
 * 共享的 `user` 表 fixture(被 SubscriptionPurchaseTest 等多个既有 DB 测试复用)
 * 只建了其中一部分列 —— 直调因此在 save() 阶段就以
 * QueryException: Unknown column 'remark' 失败,与业务规则校验(验证码/邀请码)
 * 无关,而是共享测试 fixture 的列覆盖不足。加宽这个共享 fixture 超出本任务范围,
 * 且会波及其余既有 DB 测试文件,风险不对称。
 *
 * 因此按预案把欢迎邮件入队从 registerHelper 抽成独立方法
 * `AuthController::sendWelcomeEmail(User $user): void`(见 AuthController.php
 * registerHelper 成功分支 + 新增方法),本测试直接调用该方法,断言部分与
 * brief 给定的一致,未做任何弱化。
 * ---------------------------------------------------------------------------
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

afterEach(function () {
    TestDatabase::dropTables();
});

/**
 * 最小可落库用户,列集合与 TestDatabase 共享 `user` 表 fixture 对齐
 * (镜像 SubscriptionPurchaseTest::purchaseMakeBuyer 的做法)。
 */
function welcomeMailMakeUser(string $email): User
{
    $user = new User();
    $user->email = $email;
    $user->user_name = 'welcome_test';
    $user->passwd = bin2hex(random_bytes(8));
    $user->money = 0;
    $user->transfer_enable = 0;
    $user->class = 0;
    $user->class_expire = date('Y-m-d H:i:s');
    $user->reg_date = date('Y-m-d H:i:s');
    $user->im_type = 0;
    $user->contact_method = 1;
    $user->save();

    return $user;
}

it('queues a welcome email after successful registration', function () {
    $user = welcomeMailMakeUser('welcome_test@example.com');

    (new AuthController())->sendWelcomeEmail($user);

    $row = (new EmailQueue())->where('template', 'new_user.tpl')->first();
    expect($row)->not->toBeNull();
    expect($row->to_email)->toBe('welcome_test@example.com');
    expect(json_decode($row->array, true))->toHaveKey('reg_time');
});
