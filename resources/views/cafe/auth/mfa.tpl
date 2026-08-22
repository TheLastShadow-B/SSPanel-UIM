{include file='shell/auth_top.tpl' page_title='二步验证' brand_title='再确认一步<br>确保是你本人' brand_sub='你的账户已启用二步验证，完成附加身份验证后即可进入用户中心。'}

<script src="https://unpkg.com/@simplewebauthn/browser/dist/bundle/index.umd.min.js"></script>

<h2 class="text-2xl font-semibold tracking-tight">二步验证</h2>
<p class="text-faint mt-1.5 mb-7 text-sm">您的账户已启用二步验证，请完成附加身份验证</p>

{if $method['totp']}
    <div class="mb-6 flex justify-between gap-2" data-code-group>
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold"
               maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
               aria-label="验证码第 1 位" data-code-input="">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold"
               maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="off"
               aria-label="验证码第 2 位" data-code-input="">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold"
               maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="off"
               aria-label="验证码第 3 位" data-code-input="">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold"
               maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="off"
               aria-label="验证码第 4 位" data-code-input="">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold"
               maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="off"
               aria-label="验证码第 5 位" data-code-input="">
        <input type="text" class="field-input !w-12 py-3 text-center text-lg font-semibold"
               maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="off"
               aria-label="验证码第 6 位" data-code-input="">
    </div>
    <button class="btn-primary mb-3 w-full"
            hx-post="/auth/totp" hx-swap="none" hx-vals="js:{
                code: readTotpCode(),
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
        // 提交时现读 DOM。密码管理器不保证派发 input 事件,靠事件累积出来的变量会是空的
        function readTotpCode() {
            var code = '';
            document.querySelectorAll('[data-code-input]').forEach(function (input) {
                code += input.value;
            });
            return code.slice(0, 6);
        }

        document.addEventListener('DOMContentLoaded', function () {
            var group = document.querySelector('[data-code-group]');
            var inputs = Array.prototype.slice.call(document.querySelectorAll('[data-code-input]'));

            // 从 start 格起逐位铺开,返回最后落笔的格子下标
            function spread(digits, start) {
                var i = Math.max(start, 0);
                for (var d = 0; d < digits.length && i < inputs.length; d++, i++) {
                    inputs[i].value = digits.charAt(d);
                }
                return Math.min(i, inputs.length - 1);
            }

            inputs.forEach(function (input, i) {
                input.addEventListener('input', function (e) {
                    if (e.isComposing) {
                        return;
                    }
                    var digits = e.target.value.replace(/\D/g, '');
                    // maxlength 只拦用户输入,拦不住脚本赋值:1Password 会把整串写进首格
                    if (digits.length > 1) {
                        e.target.value = '';
                        inputs[spread(digits, i)].focus();
                        return;
                    }
                    e.target.value = digits;
                    if (digits !== '' && i + 1 < inputs.length) {
                        inputs[i + 1].focus();
                    }
                });

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && e.target.value === '' && i > 0) {
                        inputs[i - 1].focus();
                    }
                });
            });

            // 粘贴必须自己接管:maxlength="1" 会把 6 位验证码裁成 1 位
            group.addEventListener('paste', function (e) {
                var digits = (e.clipboardData ? e.clipboardData.getData('text') : '').replace(/\D/g, '');
                if (digits === '') {
                    return;
                }
                e.preventDefault();
                inputs[spread(digits, inputs.indexOf(e.target))].focus();
            });
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
