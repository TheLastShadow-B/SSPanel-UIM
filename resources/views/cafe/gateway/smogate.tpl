{* Smogate 支付宝:桌面端返回二维码串本地渲染,移动端后端返回 type=url 直接跳转 *}
<script src="/theme/cafe/js/qrcode.min.js"></script>

<div id="smogate-gateway" data-invoice-id="{$invoice->id}" data-amount="{$invoice->price}">
    <p class="text-faint mb-3 text-xs leading-relaxed">
        生成二维码后,用手机支付宝扫码完成支付
    </p>

    <button class="btn-primary w-full" type="button" data-smogate-start>
        <i class="ti ti-qrcode"></i> 生成付款二维码
    </button>

    <div class="mt-3 flex-col items-center gap-2 hidden" data-smogate-result>
        <div class="rounded-xl bg-white p-3 shadow-sm" data-smogate-qrcode></div>
        <p class="text-faint text-center text-xs">手机支付宝扫描上方二维码支付</p>
        <button class="btn-secondary btn-sm" type="button" onclick="location.reload()">
            <i class="ti ti-refresh"></i> 我已支付,刷新页面
        </button>
    </div>
</div>

{literal}
<script>
    (function () {
        const root = document.getElementById('smogate-gateway');
        const startBtn = root.querySelector('[data-smogate-start]');
        const result = root.querySelector('[data-smogate-result]');
        const holder = root.querySelector('[data-smogate-qrcode]');

        startBtn.addEventListener('click', function () {
            startBtn.disabled = true;

            // 表单编码提交:后端走 $request->getParam(),与原 jQuery $.ajax 默认行为一致
            fetch('/user/payment/purchase/smogate', {
                method: 'POST',
                body: new URLSearchParams({
                    amount: root.dataset.amount,
                    invoice_id: root.dataset.invoiceId,
                }),
            })
                .then(function (resp) { return resp.json(); })
                .then(function (data) {
                    if (data.ret !== 1) {
                        showToast(data.msg || '生成二维码失败', 'danger');
                        startBtn.disabled = false;
                        return;
                    }
                    // 后端对移动端 UA 返回 type=url(收银台地址),此时应直接跳转而非画码
                    if (data.type === 'url') {
                        location.href = data.qrcode;
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
