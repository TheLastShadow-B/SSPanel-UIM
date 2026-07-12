{include file='shell/auth_top.tpl' page_title='注册' brand_title='初次见面<br>欢迎光临咖啡厅' brand_sub='注册账户后即可选购订阅套餐，开始你的加速之旅。'}

{if $public_setting['reg_mode'] !== 'close'}
    <h2 class="text-2xl font-semibold tracking-tight">注册账户</h2>
    <p class="text-faint mt-1.5 mb-7 text-sm">几步之内即可完成注册</p>

    <div class="mb-4">
        <label class="field-label" for="name">昵称</label>
        <input id="name" type="text" class="field-input" placeholder="怎么称呼你">
    </div>

    <div class="mb-4">
        <label class="field-label" for="email">电子邮箱</label>
        <input id="email" type="email" class="field-input" placeholder="you@example.com" autocomplete="email">
    </div>

    {if $public_setting['reg_email_verify']}
        <div class="mb-4">
            <label class="field-label" for="emailcode">邮箱验证码</label>
            <div class="flex gap-2">
                <input id="emailcode" type="text" class="field-input flex-1" placeholder="验证码">
                <button id="send-verify-email" class="btn-secondary btn-sm shrink-0" type="button"
                        hx-post="/auth/send" hx-swap="none" hx-disabled-elt="this"
                        hx-vals='js:{ email: document.getElementById("email").value }'>
                    获取
                </button>
            </div>
        </div>
    {/if}

    <div class="mb-4">
        <label class="field-label" for="password">登录密码</label>
        <input id="password" type="password" class="field-input" placeholder="••••••••" autocomplete="new-password">
    </div>

    <div class="mb-4">
        <label class="field-label" for="confirm_password">重复登录密码</label>
        <input id="confirm_password" type="password" class="field-input" placeholder="••••••••" autocomplete="new-password">
    </div>

    <div class="mb-4">
        <label class="field-label" for="invite_code">邀请码{if $public_setting['reg_mode'] === 'open'}（可选）{else}（必填）{/if}</label>
        <input id="invite_code" type="text" class="field-input" value="{$invite_code}">
    </div>

    <label class="mb-5 flex cursor-pointer items-center gap-2 text-sm">
        <input id="tos" type="checkbox" class="accent-primary size-4 rounded">
        <span class="text-body">我已阅读并同意 <a href="/tos" class="text-primary" tabindex="-1">服务条款与隐私政策</a></span>
    </label>

    {if $public_setting['enable_reg_captcha']}
        <div class="mb-5">
            {include file='captcha/div.tpl'}
        </div>
    {/if}

    <button class="btn-primary w-full"
            hx-post="/auth/register" hx-swap="none" hx-vals='js:{
                {if $public_setting['reg_email_verify']}
                    emailcode: document.getElementById("emailcode").value,
                {/if}
                {if $public_setting['enable_reg_captcha']}
                    {include file='captcha/ajax.tpl'}
                {/if}
                name: document.getElementById("name").value,
                email: document.getElementById("email").value,
                password: document.getElementById("password").value,
                confirm_password: document.getElementById("confirm_password").value,
                invite_code: document.getElementById("invite_code").value,
                tos: document.getElementById("tos").checked,
             }'>
        注册新账户
    </button>
{else}
    <h2 class="text-2xl font-semibold tracking-tight">暂未开放注册</h2>
    <p class="text-faint mt-1.5 text-sm">还没有开放注册，过两天再来看看吧</p>
{/if}

<p class="text-faint mt-7 text-center text-sm">
    已有账户？ <a href="/auth/login" class="text-primary font-medium">点击登录</a>
</p>

{if $public_setting['enable_reg_captcha']}
    {include file='captcha/js.tpl'}
{/if}

{include file='shell/auth_bottom.tpl'}
