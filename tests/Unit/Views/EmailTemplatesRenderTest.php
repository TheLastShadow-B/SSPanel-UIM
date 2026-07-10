<?php

declare(strict_types=1);

use App\Services\Mail;

/*
 * ---------------------------------------------------------------------------
 * 邮件模板渲染冒烟:每个模板用桩变量走真实 Mail::genHtml(独立 Smarty 实例,
 * 模板目录 resources/email/),断言不抛异常、关键内容在场、标签平衡。
 * 组件契约:header/hero 打开容器,footer 统一闭合 —— 平衡断言是契约的守门员。
 * ---------------------------------------------------------------------------
 */

// View::getConfig()(src/Services/View.php:62)只读 $_ENV,不查库:
// 这里直接设齐它读取的全部键,保证渲染时 $config['appName']/$config['baseUrl'] 等非空,
// 且不因缺键触发 PHP "Undefined array key" 警告(phpunit.xml 配置了 failOnWarning）。
beforeEach(function () {
    $_ENV['appName'] = 'Rabbit House';
    $_ENV['baseUrl'] = 'https://test.local';
    $_ENV['jump_delay'] = 1000;
    $_ENV['enable_kill'] = false;
    $_ENV['enable_change_email'] = true;
    $_ENV['enable_r2_client_download'] = false;
    $_ENV['jsdelivr_url'] = 'fastly.jsdelivr.net';
    $_ENV['locale'] = 'zh-CN';
});

if (! function_exists('emailRender')) {
    function emailRender(string $tpl, array $vars = []): string
    {
        $html = Mail::genHtml($tpl, $vars);
        expect($html)->toBeString();

        return $html;
    }

    function emailAssertBalanced(string $html): void
    {
        expect(substr_count($html, '<body'))->toBe(1);
        expect(substr_count($html, '</body>'))->toBe(1);
        expect(substr_count($html, '<table'))->toBe(substr_count($html, '</table>'));
        expect(substr_count($html, '<tr'))->toBe(substr_count($html, '</tr>'));
        expect(substr_count($html, '<div'))->toBe(substr_count($html, '</div'));
    }

    function emailStubUser(): object
    {
        return (object) ['user_name' => '测试用户', 'email' => 'stub@example.com'];
    }
}

it('renders warn.tpl with parameterized title and text', function () {
    $html = emailRender('warn.tpl', [
        'user' => emailStubUser(),
        'title' => '账号异地登录提醒',
        'text' => '检测到你的账号在新 IP 登录。',
    ]);

    expect($html)->toContain('账号异地登录提醒')
        ->toContain('检测到你的账号在新 IP 登录。')
        ->toContain('测试用户')
        ->not->toContain('fonts.googleapis.com');
    emailAssertBalanced($html);
});

it('renders warn.tpl without title falling back to 系统提示', function () {
    $html = emailRender('warn.tpl', ['text' => '一段内容']);

    expect($html)->toContain('系统提示')->toContain('一段内容');
    emailAssertBalanced($html);
});

it('renders test.tpl with only config available', function () {
    $html = emailRender('test.tpl');

    expect($html)->toContain('测试邮件')->toContain('邮件发送配置正确');
    emailAssertBalanced($html);
});

it('renders verify_code.tpl with big code block', function () {
    $html = emailRender('verify_code.tpl', ['code' => 'ABC123', 'expire' => '2026-07-10 12:00:00']);

    expect($html)->toContain('邮箱验证码')->toContain('ABC123')->toContain('2026-07-10 12:00:00')
        ->toContain('如非本人操作,请忽略此邮件');
    emailAssertBalanced($html);
});

it('renders password_reset.tpl with reset button', function () {
    $html = emailRender('password_reset.tpl', ['resetUrl' => 'https://test.local/password/token/tok123']);

    expect($html)->toContain('重置密码')->toContain('https://test.local/password/token/tok123')
        ->toContain('你的密码不会被更改');
    emailAssertBalanced($html);
});

it('renders new_user.tpl welcome mail', function () {
    $html = emailRender('new_user.tpl', ['user' => emailStubUser(), 'reg_time' => '2026-07-10 08:00:00']);

    expect($html)->toContain('欢迎加入')->toContain('stub@example.com')->toContain('2026-07-10 08:00:00')
        ->toContain('/user');
    emailAssertBalanced($html);
});

it('renders subscription_renewal.tpl with full structured fields', function () {
    $html = emailRender('subscription_renewal.tpl', [
        'user' => emailStubUser(),
        'text' => '你好,你的订阅即将到期,系统已为你生成续费订单,请及时支付以避免服务中断。',
        'plan_name' => '测试订阅 Pro',
        'billing_cycle_text' => '月付',
        'amount' => '10.00',
        'end_date' => '2026-08-08',
        'order_id' => 42,
        'invoice_url' => 'https://test.local/user/invoice/9/view',
    ]);

    expect($html)->toContain('订阅续费提醒')->toContain('测试订阅 Pro')->toContain('月付')
        ->toContain('10.00')->toContain('#42')
        ->toContain('https://test.local/user/invoice/9/view')->toContain('立即支付');
    emailAssertBalanced($html);
});

it('renders subscription_renewal.tpl gracefully without structured fields (legacy queue rows)', function () {
    $html = emailRender('subscription_renewal.tpl', [
        'user' => emailStubUser(),
        'text' => '老队列正文',
    ]);

    expect($html)->toContain('订阅续费提醒')->toContain('老队列正文')->not->toContain('立即支付');
    emailAssertBalanced($html);
});

it('renders subscription_renewal.tpl through the production email-queue json round-trip', function () {
    // Mirrors the real dispatch path, not the array literal shorthand the other cases use:
    // EmailQueue::add() (src/Models/EmailQueue.php:32) stores json_encode($array); Cron::
    // processEmailQueue() (src/Services/Cron.php:238) reads it back with plain
    // json_decode($email_queue['array']) — no $assoc = true — so Mail::genHtml() (src/Services/
    // Mail.php:55 foreach) is actually handed nested stdClass objects, not arrays (e.g. $vars['user']
    // arrives as stdClass, same as the hand-built stub, but every other nested value is also
    // whatever json_decode(..., false) produces). We only cast the top level to array here because
    // emailRender()'s helper signature is typed array $vars — the nested values are left exactly as
    // json_decode(..., false) produced them, matching production.
    $vars = [
        'user' => emailStubUser(),
        'text' => '你好,你的订阅即将到期,系统已为你生成续费订单,请及时支付以避免服务中断。',
        'plan_name' => null,
        'billing_cycle_text' => '月付',
        'amount' => '10.00',
        'end_date' => '2026-08-08',
        'order_id' => 42,
        'invoice_url' => null,
    ];
    $queueShapedVars = (array) json_decode(json_encode($vars));

    $html = emailRender('subscription_renewal.tpl', $queueShapedVars);

    expect($html)->toContain('订阅续费提醒')
        ->toContain('测试用户') // $user->user_name renders off a stdClass, per the queue contract
        ->toContain('10.00') // 续费金额 row still renders
        ->not->toContain('立即支付') // invoice_url null → isset() false → no button
        ->not->toContain('套餐'); // plan_name null → isset() false → no row
    emailAssertBalanced($html);
});

it('renders subscription_reminder.tpl with full structured fields', function () {
    $html = emailRender('subscription_reminder.tpl', [
        'user' => emailStubUser(),
        'text' => '你好,你的订阅续费订单仍未支付,请尽快完成支付以避免服务到期后中断。',
        'plan_name' => '测试订阅 Pro',
        'billing_cycle_text' => '月付',
        'amount' => '10.00',
        'end_date' => '2026-08-08',
        'order_id' => 42,
        'invoice_url' => 'https://test.local/user/invoice/9/view',
    ]);

    expect($html)->toContain('续费订单待支付')->toContain('测试订阅 Pro')->toContain('月付')
        ->toContain('10.00')->toContain('#42')
        ->toContain('https://test.local/user/invoice/9/view')->toContain('立即支付');
    emailAssertBalanced($html);
});

it('renders subscription_reminder.tpl gracefully without structured fields (legacy queue rows)', function () {
    $html = emailRender('subscription_reminder.tpl', [
        'user' => emailStubUser(),
        'text' => '老队列正文',
    ]);

    expect($html)->toContain('续费订单待支付')->toContain('老队列正文')->not->toContain('立即支付');
    emailAssertBalanced($html);
});

it('renders subscription_renewal_failed.tpl with full structured fields', function () {
    $html = emailRender('subscription_renewal_failed.tpl', [
        'user' => emailStubUser(),
        'text' => '你好,本次自动续费扣款未能成功。',
        'plan_name' => '测试订阅 Pro',
        'billing_cycle_text' => '月付',
        'amount' => '10.00',
        'grace_until' => '2026-08-11 23:59:59',
        'invoice_url' => 'https://test.local/user/invoice/9/view',
    ]);

    expect($html)->toContain('订阅续费失败')->toContain('测试订阅 Pro')
        ->toContain('10.00')->toContain('2026-08-11 23:59:59')
        ->toContain('https://test.local/user/invoice/9/view')->toContain('前往支付');
    emailAssertBalanced($html);
});

it('renders subscription_renewal_failed.tpl gracefully without structured fields (legacy queue rows)', function () {
    $html = emailRender('subscription_renewal_failed.tpl', [
        'user' => emailStubUser(),
        'text' => '老队列正文',
    ]);

    expect($html)->toContain('订阅续费失败')->toContain('老队列正文')->not->toContain('前往支付');
    emailAssertBalanced($html);
});

it('renders subscription_expired.tpl with full structured fields', function () {
    $html = emailRender('subscription_expired.tpl', [
        'user' => emailStubUser(),
        'text' => '你好,你的订阅已过期,账户服务已被停止。',
        'plan_name' => '测试订阅 Pro',
        'end_date' => '2026-08-08',
    ]);

    expect($html)->toContain('订阅已过期')->toContain('测试订阅 Pro')->toContain('2026-08-08')
        ->toContain('已过期')->toContain('重新订阅')->toContain('/user/product');
    emailAssertBalanced($html);
});

it('renders subscription_expired.tpl gracefully without structured fields (legacy queue rows)', function () {
    $html = emailRender('subscription_expired.tpl', [
        'user' => emailStubUser(),
        'text' => '老队列正文',
    ]);

    expect($html)->toContain('订阅已过期')->toContain('老队列正文')->toContain('重新订阅');
    emailAssertBalanced($html);
});

it('renders traffic_report.tpl with usage bar', function () {
    $html = emailRender('traffic_report.tpl', [
        'user' => emailStubUser(),
        'text' => '站点公告:<br>测试公告',
        'lastday_traffic' => '1.5GB',
        'enable_traffic' => '100GB',
        'used_traffic' => '30GB',
        'unused_traffic' => '70GB',
        'used_pct' => 30,
    ]);

    expect($html)->toContain('每日流量报告')->toContain('1.5GB')->toContain('30GB')
        ->toContain('70GB')->toContain('100GB')->toContain('width="30%"')->toContain('测试公告');
    emailAssertBalanced($html);
});

it('renders traffic_report.tpl without used_pct (legacy rows)', function () {
    $html = emailRender('traffic_report.tpl', [
        'user' => emailStubUser(),
        'text' => '公告',
        'lastday_traffic' => '1GB',
        'enable_traffic' => '10GB',
        'used_traffic' => '9GB',
        'unused_traffic' => '1GB',
    ]);

    expect($html)->toContain('每日流量报告')->not->toContain('已使用');
    emailAssertBalanced($html);
});

it('renders finance.tpl wrapping preformatted text', function () {
    $html = emailRender('finance.tpl', ['title' => '财务日报', 'text' => '<table><tr><td>77.00</td></tr></table>']);

    expect($html)->toContain('财务日报')->toContain('77.00');
    emailAssertBalanced($html);
});
