{include file='shell/auth_top.tpl' page_title='登录'}

<script src="https://unpkg.com/@simplewebauthn/browser/dist/bundle/index.umd.min.js"></script>

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
    <input id="remember_me" type="checkbox" class="accent-primary size-4 rounded">
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

{if $public_setting['enable_login_captcha']}
    {include file='captcha/js.tpl'}
{/if}

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

{include file='shell/auth_bottom.tpl'}
