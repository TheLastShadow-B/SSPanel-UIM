<script src="https://www.paypal.com/sdk/js?client-id={$public_setting['paypal_client_id']}&currency={$public_setting['paypal_currency']}"></script>

<div id="paypal-gateway" data-jump-delay="{$config['jump_delay']}" data-invoice-id="{$invoice->id}">
    <p class="text-faint mb-3 text-xs leading-relaxed">
        使用 PayPal 余额或绑定的信用卡支付
    </p>
    <div id="paypal-button-container"></div>
</div>

{literal}
<script>
    (function () {
        const root = document.getElementById('paypal-gateway');

        paypal.Buttons({
            createOrder() {
                return fetch('/user/payment/purchase/paypal', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ invoice_id: root.dataset.invoiceId }),
                })
                    .then((response) => response.json())
                    .then((order) => order.id);
            },
            onApprove() {
                showToast('支付成功,正在跳转…', 'success');
                // 原实现写成 setTimeout(location.href = '...', delay),赋值会立即求值,
                // 等于没有延迟就跳走 —— 必须包一层函数
                setTimeout(function () {
                    location.href = '/user/invoice';
                }, Number(root.dataset.jumpDelay) || 0);
            },
        }).render('#paypal-button-container');
    })();
</script>
{/literal}
