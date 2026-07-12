{include file='shell/auth_top.tpl' page_title='二步验证' brand_title='再确认一步<br>确保是你本人' brand_sub='你的账户已启用二步验证，完成附加身份验证后即可进入用户中心。'}

<script src="https://unpkg.com/@simplewebauthn/browser/dist/bundle/index.umd.min.js"></script>

<h2 class="text-2xl font-semibold tracking-tight">二步验证</h2>
<p class="text-faint mt-1.5 mb-7 text-sm">您的账户已启用二步验证，请完成附加身份验证</p>

{if $method['totp']}
    <div class="mb-6 flex justify-between gap-2">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input="">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input="">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input="">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input="">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input="">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold" maxlength="1" inputmode="numeric" pattern="[0-9]*" data-code-input="">
    </div>
    <button class="btn-primary mb-3 w-full"
            hx-post="/auth/totp" hx-swap="none" hx-vals="js:{
                code: code,
            }">
        提交
    </button>
{/if}
{if $method['fido']}
    <button class="btn-outline w-full" id="webauthnLogin">
        <i class="ti ti-fingerprint"></i> 使用 FIDO2 验证
    </button>
{/if}

{if $method['totp']}
    {literal}
    <script>
        var code = '';
        document.addEventListener('DOMContentLoaded', function () {
            var inputs = document.querySelectorAll('[data-code-input]');

            for (let i = 0; i < inputs.length; i++) {
                inputs[i].addEventListener('input', function (e) {
                    if (e.target.value.length === e.target.maxLength && i + 1 < inputs.length) {
                        inputs[i + 1].focus();
                    }
                    code = '';
                    inputs.forEach(function (input) { code += input.value; });
                });
                inputs[i].addEventListener('keydown', function (e) {
                    if (e.target.value.length === 0 && e.keyCode === 8 && i > 0) {
                        inputs[i - 1].focus();
                    }
                });
            }
        });
    </script>
    {/literal}
{/if}

{if $method['fido']}
    {literal}
    <script>
        const { startAuthentication } = SimpleWebAuthnBrowser;
        document.getElementById('webauthnLogin').addEventListener('click', async () => {
            const resp = await fetch('/auth/fido');
            const options = await resp.json();
            let asseResp;
            try {
                asseResp = await startAuthentication({ optionsJSON: options });
            } catch (error) {
                showToast(String(error), 'danger');
                throw error;
            }
            const verificationResp = await fetch('/auth/fido', {
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
{/if}

{include file='shell/auth_bottom.tpl'}
