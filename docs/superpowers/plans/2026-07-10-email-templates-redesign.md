# 邮件模板重做(Lagom 风格)实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把 `resources/email/` 全部 12 封邮件换成组件化的 Lagom 风格底座(Tabler 蓝),订阅系列注入结构化字段与支付 CTA,实装注册欢迎邮件。

**Architecture:** 新建 `resources/email/components/{header,hero,card,button,footer}.tpl` 五个组件,采用「header/hero 打开容器、footer 统一闭合」的 include 契约;每封邮件 = header → hero → 正文(自由 HTML + card/button)→ footer。PHP 侧给 `Notification::notify*` 加可选 `$extra` 合并进模板变量,四个订阅通知点与注册流程补接。

**Tech Stack:** Smarty 5.7(独立实例,`Mail::genHtml`,模板目录 `resources/email/`)、纯 table + 内联样式邮件 HTML、Pest 3 渲染测试。

## Global Constraints

- 设计规范以 `docs/superpowers/specs/2026-07-10-email-templates-redesign-design.md` 为准;文案逐字使用 spec 第「12 封邮件的针对性设计」节。
- 主色 `#206bc4`;渐变 `linear-gradient(135deg,#1a5db0 0%,#3d8fd1 100%)`,降级纯色 `#2f7ac9`;语义色 green `#2e7d32` / red `#c62828` / orange `#ef6c00`。
- 字体栈(逐字):`-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif`。禁止引用任何外部字体/CSS/JS。
- 布局只用 `<table role="presentation">` + 内联样式;圆角表格需 `border-collapse:separate`。
- 模板必须容错:所有结构化变量用 `{if isset($x)}` 守卫(老队列邮件参数没有新字段,渲染不得抛异常)。`test.tpl` 渲染时只有 `$config` 可用。
- PHP:`declare(strict_types=1)`;不新增 composer 依赖;IM 通知路径行为不变。
- 渲染测试用 `Mail::genHtml($tpl, $vars)`(src/Services/Mail.php:47);它 assign `config` = `View::getConfig()` — **动手前先读 View::getConfig 确认其依赖**(若查库则测试沿用 TestDatabase 模式:`require BASE_PATH . '/config/.config.test.php'; TestDatabase::init();`)。
- 测试命令 `./vendor/bin/pest <file>`;vendor voku deprecation 是既有噪音。config/.config.test.php 绝不提交。
- 提交信息结尾:`Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`。
- 报告只写真实观察到的输出,禁止编造。

## 组件契约(所有任务共用,先读懂再动手)

- `components/header.tpl` 打开:`<html><body>` + 画布 table(#1)+ 640px table(#2)+ 色带行 `<tr><td>`(色带 td **不闭合**,留给 hero)。可选 `$preheader`。
- `components/hero.tpl`(参数 `$hero_title`):渲染色带内渐变标题卡 → 闭合色带 td/tr → 间隔行 → 打开白色正文行 `<tr><td>`(**不闭合**,留给 footer)。
- `components/footer.tpl`:闭合正文 td/tr → 页脚行 → 闭合 table#2、table#1 外层 td/tr、`</body></html>`。
- `components/card.tpl`(参数 `$card_rows` 必填、`$card_title` 可选):自闭合信息卡。row = `['label'=>string,'value'=>string,'color'=>省略|'green'|'red'|'orange']`。
- `components/button.tpl`(参数 `$btn_text`、`$btn_url`、可选 `$btn_color`):自闭合 CTA。
- 内容模板一律不写 `</body>`/`</html>`/容器闭合;标签平衡由 header+hero+footer 三件套保证,渲染测试做计数断言。

---

### Task 1: 组件底座 + 渲染测试基建 + warn.tpl / test.tpl

**Files:**
- Create: `resources/email/components/header.tpl`、`components/hero.tpl`、`components/card.tpl`、`components/button.tpl`、`components/footer.tpl`
- Rewrite: `resources/email/warn.tpl`、`resources/email/test.tpl`
- Test: `tests/Unit/Views/EmailTemplatesRenderTest.php`

**Interfaces:**
- Consumes: `Mail::genHtml(string $template, array $ary): false|string`
- Produces: 上述组件契约 + 测试文件内的共享助手 `emailRender(string $tpl, array $vars = []): string` 与 `emailAssertBalanced(string $html): void`(后续任务往同一测试文件追加用例并复用)。

- [ ] **Step 1: 读取 View::getConfig,确认渲染依赖**

Run: `grep -n "function getConfig" -A 20 src/Services/View.php`
把结论写进报告(是否查库/需要哪些 $_ENV)。若查库 → 测试 beforeEach 用 TestDatabase 模式;若纯 $_ENV → beforeEach 里直接设 `$_ENV['appName']='Rabbit House'; $_ENV['baseUrl']='https://test.local';`(以实际读到的键为准,两种情况都要保证 `$config['appName']`/`$config['baseUrl']` 渲染非空)。

- [ ] **Step 2: 写失败测试**

创建 `tests/Unit/Views/EmailTemplatesRenderTest.php`:

```php
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

// beforeEach: 按 Step 1 的结论准备 config 依赖(TestDatabase 或 $_ENV),此处按实际情况填。

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
```

- [ ] **Step 3: 跑测试确认失败**

Run: `./vendor/bin/pest tests/Unit/Views/EmailTemplatesRenderTest.php`
Expected: FAIL(warn.tpl 是旧模板:含 Google Fonts / 写死「系统提示」大标题,`账号异地登录提醒` 断言不满足;test.tpl 同理)

- [ ] **Step 4: 创建五个组件**

`resources/email/components/header.tpl`(完整文件):

```smarty
{* 邮件底座 · 画布 + 600px 容器 + 顶部色带 logo 行。
   标签契约:本文件打开 <html><body>、画布 table(#1 tr td)、640px table(#2)、
   色带 <tr><td>(不闭合);hero.tpl 负责结束色带并打开白色正文区;
   footer.tpl 统一闭合全部容器。内容模板不得自行闭合任何容器标签。
   可选参数:$preheader(收件箱预览文案,隐藏渲染)。 *}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta http-equiv="X-UA-Compatible" content="IE=edge"/>
    <title>{$config['appName']}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;-webkit-text-size-adjust:100%;">
{if isset($preheader)}<div style="display:none;font-size:1px;color:#f4f5f7;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{$preheader}</div>{/if}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f5f7;">
    <tr>
        <td align="center" valign="top" style="padding:0 10px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;">
                <tr>
                    <td style="background-color:#206bc4;border-radius:0 0 10px 10px;padding:0 20px;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                            <tr>
                                <td align="left" style="padding:18px 4px;">
                                    <a href="{$config['baseUrl']}" target="_blank" style="text-decoration:none;">
                                        <img src="{$config['baseUrl']}/images/uim-logo-round_192x192.png" width="36" height="36" alt="{$config['appName']}" style="vertical-align:middle;border:0;border-radius:50%;"/>
                                        <span style="font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:18px;font-weight:700;color:#ffffff;vertical-align:middle;padding-left:10px;">{$config['appName']}</span>
                                    </a>
                                </td>
                                <td align="right" style="padding:18px 4px;">
                                    <a href="{$config['baseUrl']}" target="_blank" style="font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:12px;color:#d7e5f7;text-decoration:none;">前往 {$config['appName']} →</a>
                                </td>
                            </tr>
                        </table>
```

`resources/email/components/hero.tpl`(完整文件):

```smarty
{* 渐变 Hero 标题卡。参数:$hero_title(必填)。
   契约:渲染标题卡 → 闭合 header 留下的色带 td/tr → 间隔行 → 打开白色正文
   <tr><td>(不闭合,footer 统一收)。 *}
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:6px 0 26px;">
                            <tr>
                                <td align="center" style="border-radius:8px;background-color:#2f7ac9;background:linear-gradient(135deg,#1a5db0 0%,#3d8fd1 100%);padding:38px 24px;">
                                    <h1 style="margin:0;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:26px;line-height:34px;font-weight:700;color:#ffffff;">{$hero_title}</h1>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="height:16px;line-height:16px;font-size:0;">&nbsp;</td>
                </tr>
                <tr>
                    <td align="left" style="background-color:#ffffff;border-radius:10px;padding:32px 40px 36px;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">
```

`resources/email/components/card.tpl`(完整文件):

```smarty
{* 信息卡(自闭合)。参数:$card_rows 必填(元素:label/value/可选 color=green|red|orange),
   $card_title 可选。 *}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e6e7e9;border-radius:6px;border-collapse:separate;margin:18px 0;">
    {if isset($card_title)}
    <tr>
        <td style="background-color:#f8f9fa;border-bottom:1px solid #e6e7e9;border-radius:6px 6px 0 0;padding:12px 16px;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:14px;font-weight:600;color:#344054;">{$card_title}</td>
    </tr>
    {/if}
    <tr>
        <td style="padding:12px 16px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                {foreach $card_rows as $row}
                <tr>
                    <td style="padding:5px 0;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;color:#475467;">
                        {$row['label']}:
                        <strong style="color:{if isset($row['color']) && $row['color'] === 'green'}#2e7d32{elseif isset($row['color']) && $row['color'] === 'red'}#c62828{elseif isset($row['color']) && $row['color'] === 'orange'}#ef6c00{else}#1f2937{/if};">{$row['value']}</strong>
                    </td>
                </tr>
                {/foreach}
            </table>
        </td>
    </tr>
</table>
```

`resources/email/components/button.tpl`(完整文件):

```smarty
{* 防弹 CTA 按钮(自闭合)。参数:$btn_text、$btn_url 必填,$btn_color 可选(默认主色)。 *}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:26px auto 8px;">
    <tr>
        <td align="center" bgcolor="{$btn_color|default:'#206bc4'}" style="border-radius:6px;">
            <a href="{$btn_url}" target="_blank" style="display:inline-block;padding:12px 34px;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">{$btn_text}</a>
        </td>
    </tr>
</table>
```

`resources/email/components/footer.tpl`(完整文件):

```smarty
{* 页脚 · 统一闭合 header/hero 打开的全部容器(正文 td/tr → table#2 → table#1 → body/html)。 *}
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:22px 10px 32px;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:#98a2b3;">
                        <a href="{$config['baseUrl']}" target="_blank" style="color:#667085;text-decoration:none;">{$config['appName']}</a>
                        &nbsp;·&nbsp;
                        <a href="{$config['baseUrl']}/user/edit" target="_blank" style="color:#667085;text-decoration:none;">修改邮件接收设置</a>
                        <br/>这是一封系统邮件,请勿直接回复。
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
```

- [ ] **Step 5: 重写 warn.tpl 与 test.tpl**

`resources/email/warn.tpl`(完整文件):

```smarty
{include file='components/header.tpl'}
{include file='components/hero.tpl' hero_title=$title|default:'系统提示'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">{$text}</p>
{include file='components/footer.tpl'}
```

`resources/email/test.tpl`(完整文件):

```smarty
{include file='components/header.tpl' preheader='邮件发送配置测试'}
{include file='components/hero.tpl' hero_title='测试邮件'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好</p>
<p style="margin:0;">如果你收到这封邮件,说明邮件发送配置正确。</p>
{$rows = [['label' => '发送时间', 'value' => "{$smarty.now|date_format:'%Y-%m-%d %H:%M:%S'}"]]}
{include file='components/card.tpl' card_rows=$rows}
{include file='components/footer.tpl'}
```

(若 Smarty 5 对 `{$smarty.now|date_format:...}` 在双引号内插值报错,改为先 `{$sent_at = $smarty.now|date_format:'%Y-%m-%d %H:%M:%S'}` 再放进数组——以渲染测试结果为准修正,不许留报错。)

- [ ] **Step 6: 跑测试确认通过**

Run: `./vendor/bin/pest tests/Unit/Views/EmailTemplatesRenderTest.php`
Expected: PASS(3 tests)。若 Smarty 语法错误,修模板直到绿,把最终语法记进报告。

- [ ] **Step 7: Commit**

```bash
git add resources/email/components resources/email/warn.tpl resources/email/test.tpl tests/Unit/Views/EmailTemplatesRenderTest.php
git commit -m "feat(email): component-based Lagom-style base; warn/test on new layout

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: 认证三封(verify_code / password_reset / new_user)+ 注册欢迎接线

**Files:**
- Rewrite: `resources/email/verify_code.tpl`、`resources/email/password_reset.tpl`、`resources/email/new_user.tpl`
- Modify: `src/Controllers/AuthController.php:286-296`(registerHelper 成功分支)
- Test: `tests/Unit/Views/EmailTemplatesRenderTest.php`(追加)、`tests/Unit/Controllers/RegisterWelcomeMailTest.php`(新建)

**Interfaces:**
- Consumes: Task 1 组件与 `emailRender`/`emailAssertBalanced`;现有变量:verify_code=`['code','expire']`(AuthController:199-203)、password_reset=`['resetUrl']`(Services/Password.php:28-36)。
- Produces: new_user.tpl 变量约定 `['user' => User, 'reg_time' => 'Y-m-d H:i:s']`;注册成功入队主题 `{$_ENV['appName']}-欢迎加入`。

- [ ] **Step 1: 追加失败测试(渲染)**

在 `EmailTemplatesRenderTest.php` 追加:

```php
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
```

- [ ] **Step 2: 跑渲染测试确认失败**

Run: `./vendor/bin/pest tests/Unit/Views/EmailTemplatesRenderTest.php`
Expected: 新增 3 个 FAIL(旧模板不含新文案)

- [ ] **Step 3: 重写三个模板**

`resources/email/verify_code.tpl`(完整文件):

```smarty
{include file='components/header.tpl' preheader='你的邮箱验证码'}
{include file='components/hero.tpl' hero_title='邮箱验证码'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好</p>
<p style="margin:0 0 18px;">感谢注册 {$config['appName']},你的邮箱验证码是:</p>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:6px 0 14px;">
    <tr>
        <td align="center" style="background-color:#ecf3fb;border-radius:8px;padding:18px 12px;font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:28px;font-weight:700;letter-spacing:6px;color:#1a5db0;">{$code}</td>
    </tr>
</table>
<p style="margin:0;font-size:13px;color:#667085;text-align:center;">验证码有效期至 {$expire},请勿泄露给他人。</p>
<p style="margin:14px 0 0;font-size:13px;color:#667085;text-align:center;">如非本人操作,请忽略此邮件。</p>
{include file='components/footer.tpl'}
```

`resources/email/password_reset.tpl`(完整文件):

```smarty
{include file='components/header.tpl' preheader='重置你的账户密码'}
{include file='components/hero.tpl' hero_title='重置密码'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好</p>
<p style="margin:0;">我们收到了你的密码重置请求,点击下方按钮设置新密码:</p>
{include file='components/button.tpl' btn_text='重置密码' btn_url=$resetUrl}
<p style="margin:14px 0 0;font-size:13px;line-height:20px;color:#667085;text-align:center;">链接在有效期内一次有效;如非本人操作,请忽略此邮件,你的密码不会被更改。</p>
{include file='components/footer.tpl'}
```

`resources/email/new_user.tpl`(完整文件):

```smarty
{include file='components/header.tpl' preheader='你的账户已创建成功'}
{include file='components/hero.tpl' hero_title="欢迎加入 {$config['appName']}"}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">你的账户已创建成功,以下是账户信息:</p>
{$rows = []}
{if isset($user)}{$rows[] = ['label' => '账号邮箱', 'value' => $user->email]}{/if}
{if isset($reg_time)}{$rows[] = ['label' => '注册时间', 'value' => $reg_time]}{/if}
{if $rows}{include file='components/card.tpl' card_rows=$rows}{/if}
{include file='components/button.tpl' btn_text='进入用户中心' btn_url="{$config['baseUrl']}/user"}
<p style="margin:14px 0 0;font-size:13px;color:#667085;text-align:center;">如需帮助,请通过工单联系我们。</p>
{include file='components/footer.tpl'}
```

- [ ] **Step 4: 跑渲染测试确认通过**

Run: `./vendor/bin/pest tests/Unit/Views/EmailTemplatesRenderTest.php`
Expected: PASS(6 tests)

- [ ] **Step 5: 写注册接线的失败测试**

创建 `tests/Unit/Controllers/RegisterWelcomeMailTest.php`(DB-backed,镜像 SubscriptionPurchaseTest 的 beforeEach/afterEach;注册入口用 `AuthController::registerHelper` 直调,参数签名先 `grep -n "public function registerHelper" -A 12 src/Controllers/AuthController.php` 核实;`$_SERVER['REMOTE_ADDR']='127.0.0.1';` 需在调用前设置,Config 需开注册——先读 registerHelper 依赖的 Config::obtain 键并在测试里 seed):

```php
<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Models\EmailQueue;
use Tests\TestDatabase;

/*
 * registerHelper 成功注册(非管理员创建)后,应入队 new_user.tpl 欢迎邮件。
 */

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

afterEach(function () {
    TestDatabase::dropTables();
});

it('queues a welcome email after successful registration', function () {
    // 按 registerHelper 实际签名构造调用(email/password/name 等),执行成功注册。
    // 断言:email_queue 有一行 to_email=注册邮箱、template='new_user.tpl',
    // array JSON 解码后含 reg_time 键。
    // 具体调用代码由实现者按签名补全 —— 断言部分如下,不许省略:
    $row = (new EmailQueue())->where('template', 'new_user.tpl')->first();
    expect($row)->not->toBeNull();
    expect($row->to_email)->toBe('welcome_test@example.com');
    expect(json_decode($row->array, true))->toHaveKey('reg_time');
});
```

(实现者补全调用部分后,先跑一次确认 FAIL 于 `$row` 为 null。若 registerHelper 直调因依赖太多不可行——例如内部触发验证码/邀请码校验——降级方案:测试改为直接调用你在 Step 6 抽出的 `AuthController::sendWelcomeEmail(User $user): void` 静态/实例方法并断言队列行,同时在报告里说明。)

- [ ] **Step 6: 实现注册接线**

`src/Controllers/AuthController.php` registerHelper 成功分支(`if ($user->save() && ! $is_admin_reg) {` 内、`return $response->withHeader('HX-Redirect', $redir);` 之前)插入:

```php
            (new EmailQueue())->add(
                $user->email,
                $_ENV['appName'] . '-欢迎加入',
                'new_user.tpl',
                [
                    'user' => $user,
                    'reg_time' => date('Y-m-d H:i:s'),
                ]
            );
```

`use App\Models\EmailQueue;` 若缺则补。

- [ ] **Step 7: 跑接线测试确认通过 + 回归**

Run: `./vendor/bin/pest tests/Unit/Controllers/RegisterWelcomeMailTest.php tests/Unit/Views/EmailTemplatesRenderTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add resources/email/verify_code.tpl resources/email/password_reset.tpl resources/email/new_user.tpl src/Controllers/AuthController.php tests/
git commit -m "feat(email): auth trio on new layout; wire welcome mail on register

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Notification $extra + 订阅四封 + SubscriptionService 四个通知点

**Files:**
- Modify: `src/Services/Notification.php:21-64`(notifyUser/notifyAdmin 加 `$extra`;notifyAllUser 同样加,保持一致)
- Rewrite: `resources/email/subscription_renewal.tpl`、`subscription_reminder.tpl`、`subscription_renewal_failed.tpl`、`subscription_expired.tpl`
- Modify: `src/Services/SubscriptionService.php` 四个通知点(约 310 / 365 / 697 / 884+963 行,行号以实际为准)
- Test: `EmailTemplatesRenderTest.php`(追加 8 个用例:四封 × 全字段/缺字段)、`tests/Unit/Services/NotificationExtraTest.php`(新建)

**Interfaces:**
- Consumes: Task 1 组件与测试助手;`SubscriptionService::SELF_MANAGED` 等现有结构。
- Produces: `Notification::notifyUser($user, $title = '', $msg = '', $template = 'warn.tpl', array $extra = []): void`(notifyAdmin/notifyAllUser 同形);订阅模板变量约定:`plan_name`、`billing_cycle_text`、`amount`、`end_date`、`grace_until`、`order_id`、`invoice_url`(全部可缺省)。

- [ ] **Step 1: 写 Notification $extra 失败测试**

创建 `tests/Unit/Services/NotificationExtraTest.php`(DB-backed 模式同前;用户工厂用 `makeUserWithMoney`,`require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php'`):

```php
<?php

declare(strict_types=1);

use App\Models\EmailQueue;
use App\Services\Notification;
use Tests\TestDatabase;

require_once __DIR__ . '/AutoRenew/AutoRenewHelpers.php';

beforeEach(function () {
    require BASE_PATH . '/config/.config.test.php';
    TestDatabase::init();
});

afterEach(function () {
    TestDatabase::dropTables();
});

it('merges extra vars into the queued mail payload without clobbering base keys', function () {
    $user = makeUserWithMoney(0.0);
    $user->contact_method = 1;
    $user->save();

    Notification::notifyUser($user, '标题', '正文', 'subscription_renewal.tpl', [
        'plan_name' => 'Pro',
        'invoice_url' => 'https://x/user/invoice/9/view',
        'title' => '不应覆盖',
    ]);

    $row = (new EmailQueue())->where('template', 'subscription_renewal.tpl')->first();
    expect($row)->not->toBeNull();
    $payload = json_decode($row->array, true);
    expect($payload['plan_name'])->toBe('Pro');
    expect($payload['invoice_url'])->toBe('https://x/user/invoice/9/view');
    expect($payload['title'])->toBe('标题');
    expect($payload['text'])->toBe('正文');
});
```

- [ ] **Step 2: 跑测试确认失败**

Run: `./vendor/bin/pest tests/Unit/Services/NotificationExtraTest.php`
Expected: FAIL(notifyUser 不接收第 5 参 → ArgumentCountError 不会发生,多余实参 PHP 会忽略吗?不会——PHP 对多余实参在非可变函数上不报错但也不可用;payload 无 plan_name → 断言失败)

- [ ] **Step 3: 实现 $extra**

`src/Services/Notification.php` 三个方法签名统一加 `array $extra = []`,入队数组改为:

```php
                (new EmailQueue())->add(
                    $user->email,
                    $title,
                    $template,
                    array_merge($extra, [
                        'user' => $user,
                        'title' => $title,
                        'text' => $msg,
                    ])
                );
```

(notifyAdmin 循环里同样;IM 分支不动。)

- [ ] **Step 4: 追加订阅四封渲染失败测试**

`EmailTemplatesRenderTest.php` 追加(每封两组;这里给出 renewal 的两组,另外三封按同样模式写全——文案断言逐字取 spec):

```php
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
```

reminder / renewal_failed / expired 的全字段用例分别断言:Hero 文案(「续费订单待支付」/「订阅续费失败」/「订阅已过期」)、各自卡片字段(failed 含 `grace_until`,expired 含「已过期」状态与「重新订阅」按钮指向 `/user/product`)、缺字段用例断言正文在场且无按钮文案。

- [ ] **Step 5: 重写订阅四封**

`resources/email/subscription_renewal.tpl`(完整文件):

```smarty
{include file='components/header.tpl' preheader='你的订阅续费订单已生成,请及时支付'}
{include file='components/hero.tpl' hero_title='订阅续费提醒'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">{$text}</p>
{$rows = []}
{if isset($plan_name)}{$rows[] = ['label' => '套餐', 'value' => $plan_name]}{/if}
{if isset($billing_cycle_text)}{$rows[] = ['label' => '计费周期', 'value' => $billing_cycle_text]}{/if}
{if isset($amount)}{$rows[] = ['label' => '续费金额', 'value' => "{$amount} 元", 'color' => 'orange']}{/if}
{if isset($end_date)}{$rows[] = ['label' => '本期到期日', 'value' => $end_date]}{/if}
{if isset($order_id)}{$rows[] = ['label' => '订单号', 'value' => "#{$order_id}"]}{/if}
{if $rows}{include file='components/card.tpl' card_title='续费订单' card_rows=$rows}{/if}
{if isset($invoice_url)}{include file='components/button.tpl' btn_text='立即支付' btn_url=$invoice_url}{/if}
<p style="margin:14px 0 0;font-size:13px;line-height:20px;color:#667085;text-align:center;">订阅默认自动续费:到期时优先扣账户余额,其次扣已绑定的银行卡;手动支付后自动续费本期不再执行。</p>
{include file='components/footer.tpl'}
```

`subscription_reminder.tpl`:同上骨架,差异:preheader='续费订单仍未支付,请尽快完成支付';hero_title='续费订单待支付';辅助文案换成「若未在到期前完成支付,服务将在宽限期后中断。」。

`subscription_renewal_failed.tpl`:preheader='自动续费扣款失败,已进入宽限期';hero_title='订阅续费失败';卡片(无 card_title):套餐 / `{if isset($amount)}` 续费金额(color='red')/ `{if isset($grace_until)}` 宽限截止(color='red');按钮文案「前往支付」;辅助文案「在宽限期内完成支付即可无缝续期。」。

`subscription_expired.tpl`:preheader='你的订阅已过期';hero_title='订阅已过期';卡片:套餐 / `{if isset($end_date)}` 过期日(color='red')/ 固定行 `['label'=>'状态','value'=>'已过期','color'=>'red']`(仅当 rows 非空时整卡渲染,状态行放在 `{if isset($plan_name) || isset($end_date)}` 守卫内);按钮固定渲染:`btn_text='重新订阅' btn_url="{$config['baseUrl']}/user/product"`;辅助文案「重新购买后服务立即恢复。」。

(四个文件都必须是完整可渲染文件,结构逐字对照 renewal 版;不许只写差异。)

- [ ] **Step 6: 跑渲染测试确认通过**

Run: `./vendor/bin/pest tests/Unit/Views/EmailTemplatesRenderTest.php`
Expected: PASS(14 tests)

- [ ] **Step 7: 四个通知点传 extra**

先读上下文再改(行号可能漂移,按函数名定位)。共用小助手:`SubscriptionService` 内加私有方法:

```php
    /** 计费周期中文名(邮件展示用) */
    private static function billingCycleText(string $cycle): string
    {
        return match ($cycle) {
            'month' => '月付',
            'quarter' => '季付',
            'year' => '年付',
            default => $cycle,
        };
    }
```

1. `generateRenewalOrder`(notify 处,$content/$order/$invoice/$subscription/$user 全在作用域):

```php
                Notification::notifyUser(
                    $user,
                    $_ENV['appName'] . '-订阅续费提醒',
                    '你好，你的订阅即将到期，系统已为你生成续费订单，请及时支付以避免服务中断。',
                    'subscription_renewal.tpl',
                    [
                        'plan_name' => $content->name ?? null,
                        'billing_cycle_text' => self::billingCycleText($subscription->billing_cycle),
                        'amount' => $subscription->renewal_price,
                        'end_date' => $subscription->end_date,
                        'order_id' => $order->id,
                        'invoice_url' => $_ENV['baseUrl'] . '/user/invoice/' . $invoice->id . '/view',
                    ]
                );
```

2. `sendSecondRenewalNotification`($unpaidOrder 在作用域;invoice 需查:`$invoice = (new Invoice())->where('order_id', $unpaidOrder->id)->first();`;plan 名取 `json_decode($subscription->product_content)->name ?? null`):extra 键同上(end_date 用 $subscription->end_date;invoice_url 仅当 $invoice 非空)。
3. `enterGrace`(renewal_failed;`$payUrl` 已现成计算,$sub/$user/$graceUntil 在作用域):

```php
                    [
                        'plan_name' => json_decode($sub->product_content)->name ?? null,
                        'billing_cycle_text' => self::billingCycleText($sub->billing_cycle),
                        'amount' => $sub->renewal_price,
                        'grace_until' => $graceUntil,
                        'invoice_url' => $payUrl,
                    ]
```

(`$msg` 原文不动 —— IM 路径靠它;`invoice_url` 为 null 时模板 isset 自动省略按钮。)
4. `expireSubscription` 与 `terminateLapsed` 的两处 expired 通知:extra = plan_name + end_date(取各自 $subscription->end_date)。

- [ ] **Step 8: 通知点测试**

AutoRenew 基建下已有覆盖 enterGrace/expire 的测试(会因签名不变而继续通过)。追加一个针对性断言(放 `NotificationExtraTest.php`):构造 makeSub + makeUserWithMoney,直接调用 `SubscriptionService::generateRenewalOrder()`(ob_start 缓冲),断言 email_queue 中 `subscription_renewal.tpl` 行的 payload 含 plan_name/amount/invoice_url 且 invoice_url 以 `/view` 结尾。(makeSub 默认 end_date=today、pending_renewal——generateRenewalOrder 的筛选条件先读函数头确认,必要时按条件构造 sub 的 status/end_date/auto_renew。)

Run: `./vendor/bin/pest tests/Unit/Services/NotificationExtraTest.php tests/Unit/Services/AutoRenew`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add src/Services/Notification.php src/Services/SubscriptionService.php resources/email/subscription_*.tpl tests/
git commit -m "feat(email): subscription mails with structured fields and pay CTA

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: traffic_report(用量条)+ finance

**Files:**
- Rewrite: `resources/email/traffic_report.tpl`、`resources/email/finance.tpl`
- Modify: `src/Models/User.php`(sendDailyTrafficReport,~283-305 行,补 `used_pct`)
- Test: `EmailTemplatesRenderTest.php` 追加 3 个用例

**Interfaces:**
- Consumes: Task 1 组件;现有变量 traffic_report=`['user','text','lastday_traffic','enable_traffic','used_traffic','unused_traffic']`、finance=`['title','text']`。
- Produces: traffic_report 新增可选 `used_pct`(0-100 int)。

- [ ] **Step 1: 追加失败测试**

```php
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
        ->toContain('70GB')->toContain('100GB')->toContain('width:30%')->toContain('测试公告');
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

    expect($html)->toContain('每日流量报告')->not->toContain('width:%');
    emailAssertBalanced($html);
});

it('renders finance.tpl wrapping preformatted text', function () {
    $html = emailRender('finance.tpl', ['title' => '财务日报', 'text' => '<table><tr><td>77.00</td></tr></table>']);

    expect($html)->toContain('财务日报')->toContain('77.00');
    emailAssertBalanced($html);
});
```

注意:finance 的 `$text` 自带 table → `emailAssertBalanced` 的 table/tr 计数仍应平衡(text 内部自平衡);若 Cron 生成的 text 不是完整标签(执行时 `grep -n "finance.tpl" -B 30 src/Services/Cron.php` 看 text 怎么拼),按实际调整断言,不许放过不平衡输出。

- [ ] **Step 2: 跑测试确认失败**

Run: `./vendor/bin/pest tests/Unit/Views/EmailTemplatesRenderTest.php`
Expected: 新 3 个 FAIL

- [ ] **Step 3: 重写两个模板**

`resources/email/traffic_report.tpl`(完整文件):

```smarty
{include file='components/header.tpl' preheader='你的每日流量使用报告'}
{include file='components/hero.tpl' hero_title='每日流量报告'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">以下是你截至今日的流量使用情况:</p>
{$rows = []}
{if isset($lastday_traffic)}{$rows[] = ['label' => '昨日用量', 'value' => $lastday_traffic]}{/if}
{if isset($used_traffic)}{$rows[] = ['label' => '已用流量', 'value' => $used_traffic]}{/if}
{if isset($unused_traffic)}{$rows[] = ['label' => '剩余流量', 'value' => $unused_traffic, 'color' => 'green']}{/if}
{if isset($enable_traffic)}{$rows[] = ['label' => '总流量', 'value' => $enable_traffic]}{/if}
{if $rows}{include file='components/card.tpl' card_title='用量概览' card_rows=$rows}{/if}
{if isset($used_pct)}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:4px 0 16px;">
    <tr>
        <td style="background-color:#e9ecef;border-radius:6px;height:10px;line-height:10px;font-size:0;">
            <div style="width:{$used_pct}%;max-width:100%;height:10px;border-radius:6px;background-color:{if $used_pct >= 80}#c62828{else}#206bc4{/if};">&nbsp;</div>
        </td>
    </tr>
    <tr>
        <td align="right" style="padding-top:4px;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:12px;color:#667085;">已使用 {$used_pct}%</td>
    </tr>
</table>
{/if}
<p style="margin:8px 0 0;font-size:14px;line-height:22px;color:#475467;">{$text}</p>
{include file='components/button.tpl' btn_text='查看用量详情' btn_url="{$config['baseUrl']}/user"}
{include file='components/footer.tpl'}
```

`resources/email/finance.tpl`(完整文件):

```smarty
{include file='components/header.tpl' preheader='站点财务报表'}
{include file='components/hero.tpl' hero_title=$title|default:'财务报表'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好</p>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e6e7e9;border-radius:6px;border-collapse:separate;margin:18px 0;">
    <tr>
        <td style="padding:14px 16px;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#1f2937;">{$text}</td>
    </tr>
</table>
{include file='components/footer.tpl'}
```

`src/Models/User.php` sendDailyTrafficReport 的入队数组补一行(位置在现有 `'unused_traffic' => ...` 之后):

```php
                    'used_pct' => $this->transfer_enable > 0
                        ? min(100, (int) round(($this->u + $this->d) * 100 / $this->transfer_enable))
                        : null,
```

- [ ] **Step 4: 跑测试确认通过 + Commit**

Run: `./vendor/bin/pest tests/Unit/Views/EmailTemplatesRenderTest.php`
Expected: PASS(17 tests)

```bash
git add resources/email/traffic_report.tpl resources/email/finance.tpl src/Models/User.php tests/Unit/Views/EmailTemplatesRenderTest.php
git commit -m "feat(email): traffic report with usage bar; finance on new layout

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: 删除旧底座 + 全量回归 + 12 封预览产物

**Files:**
- Delete: `resources/email/header.tpl`、`resources/email/footer.tpl`(根目录旧底座)
- Test: 全量 `./vendor/bin/pest`
- Produce: `/private/tmp/claude-501/-Users-Mashiro-Projects-XJJ-SSPanel-UIM/1b88ed27-a866-4c19-a4bb-bc66513d7f59/scratchpad/email-previews/*.html`(12 封渲染产物,不入库)

- [ ] **Step 1: 确认旧底座无引用**

Run: `grep -rn "include file='header.tpl'\|include file='footer.tpl'" resources/email/`
Expected: 无输出(全部模板已迁移;若有残留,先修复该模板再继续)。

- [ ] **Step 2: 删除并跑全渲染测试**

```bash
git rm resources/email/header.tpl resources/email/footer.tpl
./vendor/bin/pest tests/Unit/Views/EmailTemplatesRenderTest.php
```
Expected: PASS(17 tests)

- [ ] **Step 3: 生成 12 封预览 HTML**

写一个 THROWAWAY 脚本到 scratchpad(勿放仓库),对 12 个模板逐一 `Mail::genHtml` 并写出 `email-previews/<name>.html`,变量用渲染测试同款桩数据(全字段组)。脚本执行后列出产物路径写进报告。

- [ ] **Step 4: 全量回归**

Run: `./vendor/bin/pest > /tmp/pest-email-final.log 2>&1; echo EXIT=$?; grep -c "  PASS  " /tmp/pest-email-final.log; grep -c "  FAIL  " /tmp/pest-email-final.log`
Expected: EXIT=0,FAIL 计数 0(pest 汇总行在重定向下不显示,以块计数为准)。

- [ ] **Step 5: Commit**

```bash
git add -A resources/email
git commit -m "chore(email): drop legacy header/footer after full migration

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## 上线备注

- EmailQueue 老行兼容:模板全部 isset 容错,部署时队列中的旧参数邮件照常渲染(Task 3/4 的 legacy 用例即此保障)。
- 部署仅模板 + 少量 PHP,无迁移;测试服验证:后台「发送测试邮件」+ 注册新号收欢迎信 + 手动触发 `php xcat Cron`(观察队列消化)。
- 预览产物交用户过目后再上生产。
