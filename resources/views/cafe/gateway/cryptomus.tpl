<div>
    <p class="text-faint mb-3 text-xs leading-relaxed">
        使用 USDT、BTC、ETH 等加密货币支付,由 Cryptomus 处理结算
    </p>
    <button class="btn-primary w-full" type="button"
            hx-post="/user/payment/purchase/cryptomus" hx-swap="none"
            hx-vals='js:{
                price: {$invoice->price},
                invoice_id: {$invoice->id},
                type: "cryptomus",
                redir: window.location.href
            }'>
        <i class="ti ti-coin"></i> 前往 Cryptomus 支付
    </button>
</div>
