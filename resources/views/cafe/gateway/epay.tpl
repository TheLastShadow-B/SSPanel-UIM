<div>
    <p class="text-faint mb-3 text-xs leading-relaxed">
        选择支付方式,将跳转至收银台完成付款
    </p>
    <div class="grid grid-cols-2 gap-2">
        {if $public_setting['epay_alipay']}
            <button class="btn-outline flex-col gap-2 rounded-xl px-3 py-4" type="button"
                    hx-post="/user/payment/purchase/epay" hx-swap="none"
                    hx-vals='js:{
                        invoice_id: {$invoice->id},
                        type: "alipay",
                        redir: window.location.href
                    }'>
                <img src="/images/alipay.svg" alt="" class="h-8 w-auto">
                <span class="text-xs">支付宝</span>
            </button>
        {/if}
        {if $public_setting['epay_wechat']}
            <button class="btn-outline flex-col gap-2 rounded-xl px-3 py-4" type="button"
                    hx-post="/user/payment/purchase/epay" hx-swap="none"
                    hx-vals='js:{
                        invoice_id: {$invoice->id},
                        type: "wxpay",
                        redir: window.location.href
                    }'>
                <img src="/images/wechat.svg" alt="" class="h-8 w-auto">
                <span class="text-xs">微信支付</span>
            </button>
        {/if}
        {if $public_setting['epay_qq']}
            <button class="btn-outline flex-col gap-2 rounded-xl px-3 py-4" type="button"
                    hx-post="/user/payment/purchase/epay" hx-swap="none"
                    hx-vals='js:{
                        invoice_id: {$invoice->id},
                        type: "qqpay",
                        redir: window.location.href
                    }'>
                <img src="/images/qq.svg" alt="" class="h-8 w-auto">
                <span class="text-xs">QQ 钱包</span>
            </button>
        {/if}
        {if $public_setting['epay_usdt']}
            <button class="btn-outline flex-col gap-2 rounded-xl px-3 py-4" type="button"
                    hx-post="/user/payment/purchase/epay" hx-swap="none"
                    hx-vals='js:{
                        invoice_id: {$invoice->id},
                        type: "usdt",
                        redir: window.location.href
                    }'>
                <img src="/images/tether.svg" alt="" class="h-8 w-auto">
                <span class="text-xs">USDT</span>
            </button>
        {/if}
    </div>
</div>
