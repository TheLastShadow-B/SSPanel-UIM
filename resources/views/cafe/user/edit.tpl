{include file='shell/header.tpl' nav='settings'}

<script src="https://unpkg.com/@simplewebauthn/browser/dist/bundle/index.umd.min.js"></script>
<script src="/theme/cafe/js/qrcode.min.js"></script>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">个人设置</h2>
        <p class="text-faint mt-1 text-sm">修改账户资料与安全设置</p>
    </div>
    <a href="/user/profile" class="btn-secondary btn-sm"><i class="ti ti-user"></i> 账户信息</a>
</div>

<div x-data="{ tab: 'profile', showTotp: false, showKill: false }">
    <div class="pill-tabs mb-6">
        <button class="pill-tab" :class="tab === 'profile' && 'active'" @click="tab = 'profile'">资料</button>
        <button class="pill-tab" :class="tab === 'security' && 'active'" @click="tab = 'security'">登录安全</button>
        <button class="pill-tab" :class="tab === 'usage' && 'active'" @click="tab = 'usage'">连接与订阅</button>
        <button class="pill-tab" :class="tab === 'other' && 'active'" @click="tab = 'other'">通知与外观</button>
    </div>

    {* ================ 资料 ================ *}
    <div x-show="tab === 'profile'" class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="c-card-pad">
            <h3 class="mb-1 text-base">登录邮箱</h3>
            <p class="text-faint mb-4 text-xs">当前邮箱：<span class="text-body font-medium" id="email">{$user->email}</span></p>
            {if $public_setting['reg_email_verify'] && $config['enable_change_email']}
                <div class="mb-3">
                    <input id="new-email" type="email" class="field-input" placeholder="新邮箱">
                </div>
                <div class="flex items-center gap-2">
                    <input id="email-code" type="text" class="field-input flex-1" placeholder="验证码">
                    <button class="btn-secondary btn-sm shrink-0"
                            hx-post="/user/edit/send" hx-swap="none"
                            hx-vals='js:{ email: document.getElementById("new-email").value }'>
                        获取验证码
                    </button>
                    <button class="btn-primary btn-sm shrink-0"
                            hx-post="/user/edit/email" hx-swap="none"
                            hx-vals='js:{
                                newemail: document.getElementById("new-email").value,
                                emailcode: document.getElementById("email-code").value
                            }'>
                        修改
                    </button>
                </div>
            {elseif $config['enable_change_email']}
                <div class="flex items-center gap-2">
                    <input id="new-email" type="email" class="field-input flex-1" placeholder="新邮箱">
                    <button class="btn-primary btn-sm shrink-0"
                            hx-post="/user/edit/email" hx-swap="none"
                            hx-vals='js:{ newemail: document.getElementById("new-email").value }'>
                        修改
                    </button>
                </div>
            {else}
                <div class="flex items-center gap-2">
                    <input id="new-email" type="email" class="field-input flex-1" placeholder="新邮箱" disabled>
                    <button class="btn-secondary btn-sm shrink-0" disabled>不允许修改</button>
                </div>
            {/if}
        </div>

        <div class="c-card-pad">
            <h3 class="mb-1 text-base">用户名</h3>
            <p class="text-faint mb-4 text-xs">当前用户名：<span class="text-body font-medium" id="username">{$user->user_name}</span></p>
            <div class="flex items-center gap-2">
                <input id="new-username" type="text" class="field-input flex-1" placeholder="新用户名" autocomplete="off">
                <button class="btn-primary btn-sm shrink-0"
                        hx-post="/user/edit/username" hx-swap="none"
                        hx-vals='js:{ newusername: document.getElementById("new-username").value }'>
                    修改
                </button>
            </div>
        </div>

        <div class="c-card-pad">
            <h3 class="mb-1 text-base">IM 账号绑定</h3>
            <p class="text-faint mb-4 text-xs">绑定后可通过 IM 接收通知</p>
            <div class="mb-3">
                <select id="imtype" class="field-input"
                        {if $user->im_type !== 0 && $user->im_value !== ''}disabled{/if}>
                    <option value="0" {if $user->im_type === 0}selected{/if}>未绑定</option>
                    <option value="1" {if $user->im_type === 1}selected{/if}>Slack</option>
                    <option value="2" {if $user->im_type === 2}selected{/if}>Discord</option>
                    <option value="4" {if $user->im_type === 4}selected{/if}>Telegram</option>
                </select>
            </div>
            {if $user->im_value !== ''}
                <div class="mb-3">
                    <input id="imvalue" type="text" class="field-input" value="{$user->im_value}" disabled>
                </div>
            {/if}
            <div class="flex justify-end" id="oauth-provider"></div>
        </div>

        <div class="c-card-pad">
            <h3 class="mb-1 text-base">解绑 IM 账户</h3>
            {if $user->im_type === 0}
                <p class="text-faint mb-4 text-xs">你的账户当前没有绑定任何 IM 服务</p>
            {else}
                <p class="text-faint mb-4 text-xs">
                    当前绑定的 IM 服务：{$user->imType()}
                    · 账户 ID：<span class="font-mono">{$user->im_value}</span>
                </p>
                <div class="flex justify-end">
                    <button class="btn-danger-soft btn-sm"
                            hx-post="/user/edit/unbind_im" hx-swap="none">
                        解绑
                    </button>
                </div>
            {/if}
        </div>

        <div class="c-card-pad">
            <h3 class="mb-1 text-base">支付方式</h3>
            <p class="text-faint mb-4 text-xs leading-relaxed">
                绑定银行卡用于订阅自动续费，余额不足时将从已绑定的卡自动扣款。
            </p>
            <div class="flex justify-end">
                <a href="/user/payment-method" class="btn-secondary btn-sm">
                    <i class="ti ti-credit-card"></i> 管理支付方式
                </a>
            </div>
        </div>
    </div>

    {* ================ 登录安全 ================ *}
    <div x-show="tab === 'security'" x-cloak class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="c-card-pad">
            <h3 class="mb-4 text-base">修改登录密码</h3>
            <div class="mb-3">
                <input id="password" type="password" class="field-input" placeholder="当前登录密码" autocomplete="off">
            </div>
            <div class="mb-3">
                <input id="new_password" type="password" class="field-input" placeholder="输入新密码" autocomplete="off">
            </div>
            <div class="mb-4">
                <input id="confirm_new_password" type="password" class="field-input" placeholder="再次输入新密码" autocomplete="off">
            </div>
            <div class="flex justify-end">
                <button class="btn-primary btn-sm"
                        hx-post="/user/edit/password" hx-swap="none"
                        hx-vals='js:{
                            new_password: document.getElementById("new_password").value,
                            confirm_new_password: document.getElementById("confirm_new_password").value,
                            password: document.getElementById("password").value
                        }'>
                    修改
                </button>
            </div>
        </div>

        <div class="c-card-pad">
            <div class="mb-1 flex items-center gap-2">
                <h3 class="text-base">TOTP</h3>
                {if $totpDevices}
                    <span class="badge-success">已启用</span>
                {else}
                    <span class="badge-neutral">未启用</span>
                {/if}
            </div>
            <p class="text-faint mb-4 text-xs leading-relaxed">
                TOTP 是一种基于时间的一次性密码算法，可以使用 Google Authenticator 或 Authy 等客户端进行验证。
            </p>
            <div class="flex justify-end">
                {if $totpDevices}
                    <button class="btn-danger-soft btn-sm"
                            hx-delete="/user/totp" hx-confirm="确认禁用 TOTP？" hx-swap="none">
                        禁用
                    </button>
                {else}
                    <button class="btn-primary btn-sm" id="enableTotp" @click="showTotp = true">启用</button>
                {/if}
            </div>
        </div>

        <div class="c-card-pad lg:col-span-2">
            <h3 class="mb-1 text-base">Passkey</h3>
            <p class="text-faint mb-4 text-xs leading-relaxed">
                Passkey 是一种新的身份验证标准，使用生物识别或安全密钥进行身份验证以取代传统密码。
            </p>
            {if $webauthnDevices && count($webauthnDevices) > 0}
                <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {foreach $webauthnDevices as $device}
                        <div class="bg-tile rounded-(--radius-tile) p-4">
                            <div class="text-ink text-sm font-medium">{$device->name|default:'未命名'}</div>
                            <div class="text-faint mt-1 text-xs">添加：{$device->created_at}</div>
                            <div class="text-faint text-xs">上次使用：{$device->used_at|default:'从未使用'}</div>
                            <button class="btn-danger-soft btn-sm mt-3"
                                    hx-delete="/user/webauthn/{$device->id}" hx-swap="none"
                                    hx-confirm="确认删除此设备？">
                                删除
                            </button>
                        </div>
                    {/foreach}
                </div>
            {/if}
            <div class="flex justify-end">
                <button class="btn-primary btn-sm" id="webauthnReg">注册 Passkey 设备</button>
            </div>
        </div>

        <div class="c-card-pad lg:col-span-2">
            <div class="mb-1 flex items-center gap-2">
                <h3 class="text-base">FIDO</h3>
                {if $fidoDevices}
                    <span class="badge-success">已启用</span>
                {else}
                    <span class="badge-neutral">未启用</span>
                {/if}
            </div>
            <p class="text-faint mb-4 text-xs leading-relaxed">
                FIDO2 是一种基于公钥加密的身份验证标准，支持 Yubikey 等硬件安全密钥。
            </p>
            {if $fidoDevices && count($fidoDevices) > 0}
                <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {foreach $fidoDevices as $device}
                        <div class="bg-tile rounded-(--radius-tile) p-4">
                            <div class="text-ink text-sm font-medium">{$device->name|default:'未命名'}</div>
                            <div class="text-faint mt-1 text-xs">添加：{$device->created_at}</div>
                            <div class="text-faint text-xs">上次使用：{$device->used_at|default:'从未使用'}</div>
                            <button class="btn-danger-soft btn-sm mt-3"
                                    hx-delete="/user/fido/{$device->id}" hx-swap="none"
                                    hx-confirm="确认删除此设备？">
                                删除
                            </button>
                        </div>
                    {/foreach}
                </div>
            {/if}
            <div class="flex justify-end">
                <button class="btn-primary btn-sm" id="fidoReg">注册 FIDO 设备</button>
            </div>
        </div>
    </div>

    {* ================ 连接与订阅 ================ *}
    <div x-show="tab === 'usage'" x-cloak class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="c-card-pad">
            <h3 class="mb-1 text-base">更换加密方式</h3>
            <p class="text-faint mb-4 text-xs">不同客户端支持的加密方式可能不同，请参考客户端支持列表设置</p>
            <div class="flex items-center gap-2">
                <select id="user-method" class="field-input flex-1">
                    {foreach $methods as $method}
                        <option value="{$method}" {if $user->method === $method}selected{/if}>{$method}</option>
                    {/foreach}
                </select>
                <button class="btn-primary btn-sm shrink-0"
                        hx-post="/user/edit/method" hx-swap="none"
                        hx-vals='js:{ method: document.getElementById("user-method").value }'>
                    修改
                </button>
            </div>
        </div>

        <div class="c-card-pad">
            <h3 class="mb-1 text-base">重置订阅地址</h3>
            <p class="text-faint mb-4 text-xs leading-relaxed">
                重置后旧的订阅地址将无法获取配置，但节点配置仍能使用。如需作废旧节点配置，请配合重置连接密码操作。
            </p>
            <div class="flex justify-end">
                <button class="btn-danger-soft btn-sm"
                        hx-post="/user/edit/url_reset" hx-swap="none"
                        hx-confirm="确认重置订阅地址？所有客户端需重新导入。">
                    重置
                </button>
            </div>
        </div>

        <div class="c-card-pad lg:col-span-2">
            <h3 class="mb-1 text-base">重置连接密码</h3>
            <p class="text-faint mb-3 text-xs">重置连接密码与 UUID，重置后需更新订阅才能继续使用</p>
            <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                <div class="bg-tile rounded-(--radius-tile) px-4 py-3">
                    <div class="text-faint text-xs">当前连接密码</div>
                    <div class="spoiler text-ink mt-0.5 font-mono text-sm" id="passwd">{$user->passwd}</div>
                </div>
                <div class="bg-tile rounded-(--radius-tile) px-4 py-3">
                    <div class="text-faint text-xs">当前 UUID</div>
                    <div class="spoiler text-ink mt-0.5 font-mono text-sm" id="uuid">{$user->uuid}</div>
                </div>
            </div>
            <div class="flex justify-end">
                <button class="btn-danger-soft btn-sm"
                        hx-post="/user/edit/passwd_reset" hx-swap="none"
                        hx-confirm="确认重置连接密码与 UUID？">
                    重置
                </button>
            </div>
        </div>
    </div>

    {* ================ 通知与外观 ================ *}
    <div x-show="tab === 'other'" x-cloak class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="c-card-pad">
            <h3 class="mb-4 text-base">每日流量报告</h3>
            <div class="flex items-center gap-2">
                <select id="daily-mail" class="field-input flex-1">
                    <option value="0" {if $user->daily_mail_enable === 0}selected{/if}>不接收</option>
                    <option value="1" {if $user->daily_mail_enable === 1}selected{/if}>邮件接收</option>
                    <option value="2" {if $user->daily_mail_enable === 2}selected{/if}>IM 接收</option>
                </select>
                <button class="btn-primary btn-sm shrink-0"
                        hx-post="/user/edit/daily_mail" hx-swap="none"
                        hx-vals='js:{ mail: document.getElementById("daily-mail").value }'>
                    修改
                </button>
            </div>
        </div>

        <div class="c-card-pad">
            <h3 class="mb-1 text-base">偏好的联系方式</h3>
            <p class="text-faint mb-4 text-xs">当 IM 未绑定时站点依然会向账户邮箱发送通知信息</p>
            <div class="flex items-center gap-2">
                <select id="contact-method" class="field-input flex-1">
                    <option value="1" {if $user->contact_method === 1}selected{/if}>邮件</option>
                    <option value="2" {if $user->contact_method === 2}selected{/if}>IM</option>
                </select>
                <button class="btn-primary btn-sm shrink-0"
                        hx-post="/user/edit/contact_method" hx-swap="none"
                        hx-vals='js:{ contact: document.getElementById("contact-method").value }'>
                    修改
                </button>
            </div>
        </div>

        <div class="c-card-pad">
            <h3 class="mb-4 text-base">界面主题</h3>
            <div class="flex items-center gap-2">
                <select id="user-theme" class="field-input flex-1">
                    {foreach $themes as $theme}
                        <option value="{$theme}" {if $user->theme === $theme}selected{/if}>{$theme}</option>
                    {/foreach}
                </select>
                <button class="btn-primary btn-sm shrink-0"
                        hx-post="/user/edit/theme" hx-swap="none"
                        hx-vals='js:{ theme: document.getElementById("user-theme").value }'>
                    修改
                </button>
            </div>
        </div>

        <div class="c-card-pad">
            <h3 class="mb-4 text-base">深浅色模式</h3>
            <div class="flex items-center gap-2">
                <select id="theme-mode" class="field-input flex-1">
                    <option value="2" {if $user->is_dark_mode === 2}selected{/if}>自动</option>
                    <option value="0" {if $user->is_dark_mode === 0}selected{/if}>浅色</option>
                    <option value="1" {if $user->is_dark_mode === 1}selected{/if}>深色</option>
                </select>
                <button class="btn-primary btn-sm shrink-0"
                        hx-post="/user/edit/theme_mode" hx-swap="none"
                        hx-vals='js:{ theme_mode: document.getElementById("theme-mode").value }'>
                    修改
                </button>
            </div>
        </div>

        {if $config['enable_kill']}
            <div class="c-card-pad border-danger/40 lg:col-span-2">
                <div class="mb-1 flex items-center gap-2">
                    <i class="ti ti-alert-triangle text-danger"></i>
                    <h3 class="text-base">删除账户数据</h3>
                </div>
                <p class="text-faint mb-4 text-xs">此操作无法撤销，所有账户数据将被彻底删除</p>
                <div class="flex justify-end">
                    <button class="btn-danger-soft btn-sm" @click="showKill = true">
                        <i class="ti ti-trash"></i> 删除账户
                    </button>
                </div>
            </div>
        {/if}
    </div>

    {* ================ TOTP 模态 ================ *}
    <template x-teleport="body">
        <div x-show="showTotp" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40"></div>
            <div class="c-card modal-pop relative w-full max-w-sm p-6 text-center shadow-xl">
                <h3 class="mb-2 text-base">设置 TOTP</h3>
                <p class="text-faint mb-4 text-xs">请使用 Google Authenticator 或 Authy 扫描下面的二维码</p>
                <div class="mb-4 flex justify-center">
                    <div id="qrcode" class="rounded-xl bg-white p-3 shadow-sm"></div>
                </div>
                <p class="text-faint mb-1 text-xs">若无法扫描二维码，可手动输入以下密钥</p>
                <p id="totpSecret" class="text-ink mb-4 font-mono text-xs break-all"></p>
                <input type="text" id="totpCode" placeholder="输入 TOTP 代码" class="field-input mb-5 text-center">
                <div class="flex justify-end gap-2">
                    <button class="btn-secondary btn-sm" @click="showTotp = false">取消</button>
                    <button class="btn-primary btn-sm" id="submitTotp">提交</button>
                </div>
            </div>
        </div>
    </template>

    {* ================ 删除账户模态 ================ *}
    {if $config['enable_kill']}
        <template x-teleport="body">
            <div x-show="showKill" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" @click="showKill = false"></div>
                <div class="c-card modal-pop relative w-full max-w-sm p-6 text-center shadow-xl">
                    <span class="bg-danger-tint text-danger mx-auto mb-4 flex size-12 items-center justify-center rounded-full text-xl">
                        <i class="ti ti-alert-circle"></i>
                    </span>
                    <h3 class="mb-2 text-base">删除确认</h3>
                    <p class="text-faint mb-4 text-xs leading-relaxed">
                        请确认是否真的要删除你的账户，此操作无法撤销，你的所有账户数据将会被从服务器上彻底删除。
                    </p>
                    <input id="confirm_kill_password" type="password" class="field-input mb-5"
                           placeholder="输入登录密码" autocomplete="off">
                    <div class="flex justify-end gap-2">
                        <button class="btn-secondary btn-sm" @click="showKill = false">取消</button>
                        <button class="btn-danger-soft btn-sm" @click="showKill = false"
                                hx-post="/user/edit/kill" hx-swap="none"
                                hx-vals='js:{ password: document.getElementById("confirm_kill_password").value }'>
                            确认删除
                        </button>
                    </div>
                </div>
            </div>
        </template>
    {/if}
</div>

<script>
    window.SETTINGS = {
        hasTotp: {if $totpDevices}true{else}false{/if},
        canBindIm: {if $user->im_type === 0 && $user->im_value === ''}true{else}false{/if},
        telegramBot: "{$public_setting['telegram_bot']|default:''}"
    };
</script>
{literal}
<script>
    // ---- TOTP ----
    (function () {
        const enableBtn = document.getElementById('enableTotp');
        if (enableBtn && !window.SETTINGS.hasTotp) {
            enableBtn.addEventListener('click', async function () {
                const resp = await fetch('/user/totp');
                const data = await resp.json();
                if (data.ret === 1) {
                    const qrcodeElement = document.getElementById('qrcode');
                    qrcodeElement.innerHTML = '';
                    document.getElementById('totpSecret').textContent = data.token;
                    new QRCode(qrcodeElement, {
                        text: data.url, width: 180, height: 180,
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } else {
                    showToast(data.msg, 'danger');
                }
            });
        }

        const submitBtn = document.getElementById('submitTotp');
        if (submitBtn) {
            submitBtn.addEventListener('click', function () {
                const totpCode = document.getElementById('totpCode').value;
                fetch('/user/totp', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code: totpCode })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    showToast(data.msg, data.ret === 1 ? 'success' : 'danger');
                    if (data.ret === 1) setTimeout(function () { location.reload(); }, 1000);
                });
            });
        }
    })();

    // ---- Passkey / FIDO ----
    (function () {
        const { startRegistration } = SimpleWebAuthnBrowser;

        async function register(endpoint) {
            const resp = await fetch(endpoint);
            const options = await resp.json();
            let attResp;
            try {
                attResp = await startRegistration({ optionsJSON: options });
            } catch (error) {
                showToast(String(error.message || error), 'danger');
                throw error;
            }
            attResp.name = prompt('请输入设备名称:');
            const verificationResp = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(attResp)
            });
            const verificationJSON = await verificationResp.json();
            showToast(verificationJSON.msg, verificationJSON.ret === 1 ? 'success' : 'danger');
            if (verificationJSON.ret === 1) setTimeout(function () { location.reload(); }, 1000);
        }

        const webauthnBtn = document.getElementById('webauthnReg');
        if (webauthnBtn) webauthnBtn.addEventListener('click', function () { register('/user/webauthn'); });
        const fidoBtn = document.getElementById('fidoReg');
        if (fidoBtn) fidoBtn.addEventListener('click', function () { register('/user/fido'); });
    })();

    // ---- IM 绑定 ----
    (function () {
        if (!window.SETTINGS.canBindIm) return;
        const provider = document.getElementById('oauth-provider');
        const imtype = document.getElementById('imtype');
        if (!provider || !imtype) return;

        function bindOauth(type) {
            fetch('/oauth/' + type, { method: 'POST' })
                .then(function (r) { return r.json(); })
                .then(function (data) { handleOauthResult(data, type); });
        }

        window.onTelegramAuth = function (user) {
            const body = new URLSearchParams({ user: JSON.stringify(user) });
            fetch('/oauth/telegram', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            }).then(function (r) { return r.json(); }).then(function (data) {
                handleOauthResult(data, 'telegram');
            });
        };

        function handleOauthResult(data, type) {
            if (data.ret === 1) {
                if (type === 'telegram') {
                    showToast(data.msg, 'success');
                } else {
                    window.location.replace(data.redir);
                }
            } else {
                showToast(data.msg, 'danger');
            }
        }

        imtype.addEventListener('change', function () {
            provider.innerHTML = '';
            const val = this.value;
            if (val === '1' || val === '2') {
                const btn = document.createElement('button');
                btn.className = 'btn-primary btn-sm';
                btn.textContent = val === '1' ? '绑定 Slack' : '绑定 Discord';
                btn.addEventListener('click', function () { bindOauth(val === '1' ? 'slack' : 'discord'); });
                provider.appendChild(btn);
            } else if (val === '4' && window.SETTINGS.telegramBot) {
                const s = document.createElement('script');
                s.async = true;
                s.src = 'https://telegram.org/js/telegram-widget.js?22';
                s.setAttribute('data-telegram-login', window.SETTINGS.telegramBot);
                s.setAttribute('data-size', 'large');
                s.setAttribute('data-onauth', 'onTelegramAuth(user)');
                s.setAttribute('data-request-access', 'write');
                provider.appendChild(s);
            }
        });
    })();
</script>
{/literal}

{include file='shell/footer.tpl'}
