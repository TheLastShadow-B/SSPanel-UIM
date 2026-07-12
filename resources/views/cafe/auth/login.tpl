<!doctype html>
<html lang="zh">

<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="referrer" content="never">
    <title>登录 - {$config['appName']}</title>
    <link rel="icon" href="/favicon.ico">
    <link href="/theme/cafe/app.css?v={$smarty.const.VERSION}" rel="stylesheet"/>
    <link href="//{$config['jsdelivr_url']}/npm/@tabler/icons-webfont@3/dist/tabler-icons.min.css" rel="stylesheet"/>
    <script src="/theme/cafe/js/htmx.min.js"></script>
    <script src="https://unpkg.com/@simplewebauthn/browser/dist/bundle/index.umd.min.js"></script>
</head>

<body class="bg-canvas min-h-screen">
<div class="flex min-h-screen">

    {* ============ 左侧品牌区 ============ *}
    <div class="from-primary to-primary-hover relative hidden w-[44%] flex-col justify-between
                overflow-hidden bg-gradient-to-br p-10 text-white lg:flex">
        <div class="flex items-center gap-3">
            <img src="/images/uim-logo-round_48x48.png" alt="logo" class="size-10 rounded-xl">
            <span class="text-lg font-semibold">{$config['appName']}</span>
        </div>
        <div class="relative z-10">
            <h1 class="text-3xl leading-snug font-semibold text-white">
                欢迎回到咖啡厅<br>今天也要元气满满
            </h1>
            <p class="mt-4 max-w-sm text-sm leading-relaxed text-white/75">
                登录后即可管理订阅、查看流量用量、快速导入客户端配置。
            </p>
        </div>
        <div class="text-xs text-white/50">Powered by SSPanel-UIM</div>
        {* 装饰圆 *}
        <div class="absolute -right-24 -bottom-24 size-80 rounded-full bg-white/10"></div>
        <div class="absolute -right-6 -bottom-40 size-56 rounded-full bg-white/10"></div>
    </div>

    {* ============ 右侧表单区 ============ *}
    <div class="flex flex-1 items-center justify-center px-5 py-10">
        <div class="w-full max-w-sm">
            <div class="mb-8 lg:hidden">
                <img src="/images/uim-logo-round_48x48.png" alt="logo" class="size-11 rounded-xl">
            </div>
            <h2 class="text-2xl font-semibold tracking-tight">登录</h2>
            <p class="text-faint mt-1.5 mb-7 text-sm">使用邮箱账户登录用户中心</p>

            <div class="mb-4">
                <label class="field-label" for="email">邮箱</label>
                <input id="email" type="email" class="field-input" placeholder="you@example.com" autocomplete="email">
            </div>

            <div class="mb-4">
                <div class="mb-1.5 flex items-center justify-between">
                    <label class="field-label !mb-0" for="password">密码</label>
                    <a href="/password/reset" class="text-primary text-xs font-medium">忘记密码？</a>
                </div>
                <input id="password" type="password" class="field-input" placeholder="••••••••" autocomplete="current-password">
            </div>

            <label class="mb-5 flex cursor-pointer items-center gap-2 text-sm">
                <input id="remember_me" type="checkbox"
                       class="accent-primary size-4 rounded">
                <span class="text-body">记住此设备</span>
            </label>

            {if $public_setting['enable_login_captcha']}
                <div class="mb-5">
                    {include file='captcha/div.tpl'}
                </div>
            {/if}

            <button class="btn-primary w-full"
                    hx-post="/auth/login" hx-swap="none" hx-vals='js:{
                        {if $public_setting['enable_login_captcha']}
                            {include file='captcha/ajax.tpl'}
                        {/if}
                        email: document.getElementById("email").value,
                        password: document.getElementById("password").value,
                        remember_me: document.getElementById("remember_me").checked,
                    }'>
                登录
            </button>

            <button class="btn-outline mt-3 w-full" id="webauthnLogin">
                <i class="ti ti-fingerprint"></i> 使用 WebAuthn 登录
            </button>

            <p class="text-faint mt-7 text-center text-sm">
                还没有账户？ <a href="/auth/register" class="text-primary font-medium">点击注册</a>
            </p>
        </div>
    </div>
</div>

{if $public_setting['enable_login_captcha']}
    {include file='captcha/js.tpl'}
{/if}

{include file='toast.tpl'}

{literal}
<script>
    const { startAuthentication } = SimpleWebAuthnBrowser;
    document.getElementById('webauthnLogin').addEventListener('click', async () => {
        const resp = await fetch('/auth/webauthn');
        const options = await resp.json();
        let asseResp;
        try {
            asseResp = await startAuthentication({ optionsJSON: options });
        } catch (error) {
            showToast(String(error), 'danger');
            throw error;
        }
        const verificationResp = await fetch('/auth/webauthn', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(asseResp),
        });
        const verificationJSON = await verificationResp.json();
        if (verificationJSON.ret === 1) {
            showToast(verificationJSON.msg, 'success');
            window.location.href = verificationJSON.redir;
        } else {
            showToast(verificationJSON.msg, 'danger');
        }
    });
</script>
{/literal}

</body>

</html>
