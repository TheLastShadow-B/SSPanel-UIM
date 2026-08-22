{include file='shell/auth_top.tpl' page_title='二步验证' brand_title='再确认一步<br>确保是你本人' brand_sub='你的账户已启用二步验证，完成附加身份验证后即可进入用户中心。'}

<script src="https://unpkg.com/@simplewebauthn/browser/dist/bundle/index.umd.min.js"></script>

<h2 class="text-2xl font-semibold tracking-tight">二步验证</h2>
<p class="text-faint mt-1.5 mb-7 text-sm">您的账户已启用二步验证，请完成附加身份验证</p>

{if $method['totp']}
    <form id="totpForm" hx-post="/auth/totp" hx-swap="none" hx-vals="js:{
                code: readTotpCode(),
            }">
        {* 只有一个真输入框;六个格子纯展示,透明文字的 input 覆盖在上面。
           分格 input 在移动端自动填充里识别不出来,详见 app.css .otp-cell *}
        <div class="relative mb-6">
            <div class="pointer-events-none flex justify-between gap-2" aria-hidden="true" data-otp-cells>
                <div class="otp-cell"></div>
                <div class="otp-cell"></div>
                <div class="otp-cell"></div>
                <div class="otp-cell"></div>
                <div class="otp-cell"></div>
                <div class="otp-cell"></div>
            </div>
            <input type="text" id="otpCode" name="code" maxlength="6"
                   inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                   aria-label="六位验证码"
                   class="absolute inset-0 h-full w-full bg-transparent text-transparent
                          caret-transparent outline-none">
        </div>
        <button type="submit" class="btn-primary mb-3 w-full">提交</button>
    </form>
{/if}
{if $method['fido']}
    <button class="btn-outline w-full" id="webauthnLogin">
        <i class="ti ti-fingerprint"></i> 使用 FIDO2 验证
    </button>
{/if}

{if $method['totp']}
    {literal}
    <script>
        // 提交时现读 DOM 并只取数字。密码管理器不保证派发事件,不能依赖累积出来的变量
        function readTotpCode() {
            var input = document.getElementById('otpCode');
            return input ? input.value.replace(/\D/g, '').slice(0, 6) : '';
        }

        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('totpForm');
            var input = document.getElementById('otpCode');
            var cells = Array.prototype.slice.call(document.querySelectorAll('[data-otp-cells] .otp-cell'));
            var lastAutoSubmitted = '';

            // 把真输入框的值画到展示格里,并高亮当前光标所在格
            function render() {
                var code = readTotpCode();
                if (input.value !== code) {
                    input.value = code;               // 顺手清掉非数字与超出的部分
                }
                var focused = document.activeElement === input;
                var active = Math.min(code.length, cells.length - 1);
                cells.forEach(function (cell, i) {
                    cell.textContent = code.charAt(i);
                    cell.classList.toggle('is-active', focused && i === active);
                });
            }

            // 凑齐就自己提交:密码管理器的"填完自动点登录"是启发式的,靠不住。
            // 记住已提交过的码避免重复打;换了新码仍会再提交
            function autoSubmitWhenComplete() {
                var code = readTotpCode();
                if (code.length !== cells.length || code === lastAutoSubmitted) {
                    return;
                }
                lastAutoSubmitted = code;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                }
            }

            ['input', 'change'].forEach(function (evt) {
                input.addEventListener(evt, function () {
                    render();
                    autoSubmitWhenComplete();
                });
            });

            // 光标恒定停在末尾,否则高亮格会和实际插入位置对不上
            ['focus', 'click', 'keyup'].forEach(function (evt) {
                input.addEventListener(evt, function () {
                    var end = input.value.length;
                    if (input.selectionStart !== end || input.selectionEnd !== end) {
                        input.setSelectionRange(end, end);
                    }
                    render();
                });
            });
            input.addEventListener('blur', render);

            // maxlength=6 会把 " 482 913 " 这类带空格的粘贴裁成 6 个字符再交给我们,
            // 数字就丢了,所以粘贴要自己接管:先剥非数字再写入
            input.addEventListener('paste', function (e) {
                var digits = (e.clipboardData ? e.clipboardData.getData('text') : '')
                    .replace(/\D/g, '').slice(0, cells.length);
                if (digits === '') {
                    return;
                }
                e.preventDefault();
                input.value = digits;
                render();
                autoSubmitWhenComplete();
            });

            render();
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
