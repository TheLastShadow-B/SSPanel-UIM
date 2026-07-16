{include file='shell/header.tpl' nav='settings'}

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">支付方式</h2>
    <p class="text-faint mt-1 text-sm">保存一张信用卡，用于订阅到期时自动续费扣款</p>
</div>

<div class="mx-auto max-w-2xl">
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">已保存的信用卡</h3>
        <div id="pm-current">
            {if $card}
                <div class="bg-tile flex items-center justify-between rounded-(--radius-tile) px-4 py-3.5">
                    <div class="flex items-center gap-3">
                        <span class="bg-primary-tint text-primary flex size-10 items-center justify-center rounded-lg text-lg">
                            <i class="ti ti-credit-card"></i>
                        </span>
                        <span>
                            <span class="text-ink font-semibold uppercase">{$card.brand}</span>
                            <span class="text-faint ml-1.5">•••• •••• •••• {$card.last4}</span>
                        </span>
                    </div>
                    <button id="pm-remove" type="button" class="btn-danger-soft btn-sm">
                        <i class="ti ti-trash"></i> 移除
                    </button>
                </div>
            {else}
                <p class="text-faint text-sm leading-relaxed" id="pm-none">
                    尚未保存任何信用卡。添加一张信用卡后，订阅到期且余额不足时将自动从该卡扣款续费。
                </p>
            {/if}
        </div>

        <div class="border-hairline my-5 border-t"></div>

        {if $publishable_key === ''}
            <p class="text-faint text-sm">站点尚未启用银行卡支付，暂时无法绑定信用卡。</p>
        {else}
            <button id="pm-add" type="button" class="btn-primary">
                <i class="ti ti-credit-card"></i>
                {if $card}更换信用卡{else}添加信用卡{/if}
            </button>

            <div id="pm-form" class="mt-4" style="display:none;">
                <div id="payment-element"></div>
                <div id="pm-error" class="text-danger mt-2 text-sm"></div>
                <button id="pm-submit" type="button" class="btn-primary mt-4">
                    保存卡片
                </button>
            </div>
        {/if}
    </div>
</div>

{if $publishable_key !== ''}
<div id="pm-root"
     data-publishable-key="{$publishable_key}"
     data-return-url="{$config['baseUrl']}/user/payment-method"></div>

<script src="https://js.stripe.com/v3/"></script>
{literal}
<script>
(function () {
    const root = document.getElementById('pm-root');
    if (!root) { return; }

    const publishableKey = root.dataset.publishableKey;
    const returnUrl = root.dataset.returnUrl;
    if (!publishableKey || typeof Stripe === 'undefined') { return; }

    const stripe = Stripe(publishableKey);
    let elements = null;

    const addBtn = document.getElementById('pm-add');
    const form = document.getElementById('pm-form');
    const submitBtn = document.getElementById('pm-submit');
    const errBox = document.getElementById('pm-error');
    const removeBtn = document.getElementById('pm-remove');

    // Reveal the Payment Element, backed by a fresh off-session SetupIntent.
    if (addBtn) {
        addBtn.addEventListener('click', async function () {
            addBtn.disabled = true;
            if (errBox) { errBox.textContent = ''; }
            try {
                const resp = await fetch('/user/payment-method/setup-intent', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: '{}'
                });
                const data = await resp.json();
                if (!data.client_secret) {
                    if (errBox) { errBox.textContent = data.msg || '无法初始化支付，请稍后再试。'; }
                    addBtn.disabled = false;
                    return;
                }
                elements = stripe.elements({ clientSecret: data.client_secret });
                elements.create('payment').mount('#payment-element');
                form.style.display = 'block';
                addBtn.style.display = 'none';
            } catch (e) {
                if (errBox) { errBox.textContent = '网络错误，请稍后再试。'; }
                addBtn.disabled = false;
            }
        });
    }

    // Confirm the SetupIntent. The setup_intent.succeeded webhook — NOT this
    // client call — is the source of truth for setting the default card, so on
    // success we just reload and let the server re-render the saved-card summary.
    if (submitBtn) {
        submitBtn.addEventListener('click', async function () {
            if (!elements) { return; }
            submitBtn.disabled = true;
            if (errBox) { errBox.textContent = ''; }
            const result = await stripe.confirmSetup({
                elements: elements,
                confirmParams: { return_url: returnUrl },
                redirect: 'if_required'
            });
            if (result.error) {
                if (errBox) { errBox.textContent = result.error.message || '保存失败，请检查卡片信息。'; }
                submitBtn.disabled = false;
                return;
            }
            window.location.reload();
        });
    }

    // Remove the saved card. The server derives the target PM from THIS user's
    // customer; the request carries no card id.
    if (removeBtn) {
        removeBtn.addEventListener('click', async function () {
            removeBtn.disabled = true;
            try {
                const resp = await fetch('/user/payment-method/detach', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: '{}'
                });
                const data = await resp.json();
                if (data.ret === 1) {
                    window.location.reload();
                } else {
                    removeBtn.disabled = false;
                }
            } catch (e) {
                removeBtn.disabled = false;
            }
        });
    }
})();
</script>
{/literal}
{/if}

{include file='shell/footer.tpl'}
