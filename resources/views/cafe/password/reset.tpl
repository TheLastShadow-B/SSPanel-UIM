{include file='shell/auth_top.tpl' page_title='忘记密码' brand_title='别担心<br>找回密码很简单' brand_sub='我们将向你的注册邮箱发送一封包含重设密码链接的邮件。'}

<h2 class="text-2xl font-semibold tracking-tight">忘记密码</h2>
<p class="text-faint mt-1.5 mb-7 text-sm">我们将向你的注册邮箱发送一封重设密码的邮件</p>

<div class="mb-5">
    <label class="field-label" for="email">注册邮箱</label>
    <input id="email" type="email" class="field-input" placeholder="you@example.com" autocomplete="email">
</div>

{if $public_setting['enable_reset_password_captcha']}
    <div class="mb-5">
        {include file='captcha/div.tpl'}
    </div>
{/if}

<button id="send" class="btn-primary w-full"
        hx-post="/password/reset" hx-swap="none" hx-vals='js:{
            {if $public_setting['enable_reset_password_captcha']}
                {include file='captcha/ajax.tpl'}
            {/if}
            email: document.getElementById("email").value,
         }'>
    <i class="ti ti-send"></i> 发送邮件
</button>

<p class="text-faint mt-7 text-center text-sm">
    已有账户？ <a href="/auth/login" class="text-primary font-medium">点击登录</a>
</p>

{if $public_setting['enable_reset_password_captcha']}
    {include file='captcha/js.tpl'}
{/if}

{include file='shell/auth_bottom.tpl'}
