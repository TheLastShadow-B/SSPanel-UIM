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
