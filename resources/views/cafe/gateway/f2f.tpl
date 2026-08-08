{* 支付宝当面付:POST 换二维码串,前端用已 vendor 的 QRCode.js 本地渲染 *}
<script src="/theme/cafe/js/qrcode.min.js"></script>

<div id="f2f-gateway" data-invoice-id="{$invoice->id}">
    <p class="text-faint mb-3 text-xs leading-relaxed">
        生成二维码后,用手机支付宝扫码完成支付
    </p>

    <button class="btn-primary w-full" type="button" data-f2f-start>
        <i class="ti ti-qrcode"></i> 生成付款二维码
    </button>

    <div class="mt-3 flex-col items-center gap-2 hidden" data-f2f-result>
        <div class="rounded-xl bg-white p-3 shadow-sm" data-f2f-qrcode></div>
        <p class="text-faint text-center text-xs">手机支付宝扫描上方二维码支付</p>
        <button class="btn-secondary btn-sm" type="button" onclick="location.reload()">
            <i class="ti ti-refresh"></i> 我已支付,刷新页面
        </button>
    </div>
</div>

{literal}
<script>
    (function () {
        const root = document.getElementById('f2f-gateway');
        const startBtn = root.querySelector('[data-f2f-start]');
        const result = root.querySelector('[data-f2f-result]');
        const holder = root.querySelector('[data-f2f-qrcode]');

        startBtn.addEventListener('click', function () {
            startBtn.disabled = true;

            // 表单编码提交:后端走 $request->getParam(),与原 jQuery $.ajax 默认行为一致
            fetch('/user/payment/purchase/f2f', {
                method: 'POST',
                body: new URLSearchParams({ invoice_id: root.dataset.invoiceId }),
            })
                .then(function (resp) { return resp.json(); })
                .then(function (data) {
                    if (data.ret !== 1) {
                        showToast(data.msg || '生成二维码失败', 'danger');
                        startBtn.disabled = false;
                        return;
                    }
                    holder.innerHTML = '';
                    new QRCode(holder, {
                        text: data.qrcode,
                        width: 200,
                        height: 200,
                        colorDark: '#000000',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.H,
                    });
                    startBtn.classList.add('hidden');
                    result.classList.remove('hidden');
                    result.classList.add('flex');
                })
                .catch(function () {
                    showToast('网络错误,请重试', 'danger');
                    startBtn.disabled = false;
                });
        });
    })();
</script>
{/literal}
