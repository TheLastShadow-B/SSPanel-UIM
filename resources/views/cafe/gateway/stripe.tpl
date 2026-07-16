<link rel="stylesheet"
      href="//{$config['jsdelivr_url']}/npm/@tabler/core@latest/dist/css/tabler-payments.min.css">

<div>
    <p class="text-faint mb-3 text-xs leading-relaxed">
        支持
        <span class="payment payment-xs payment-provider-unionpay"></span>
        <span class="payment payment-xs payment-provider-mastercard"></span>
        <span class="payment payment-xs payment-provider-visa"></span>
        等标识的信用卡或借记卡
    </p>
    <div class="flex flex-col gap-2">
        <button class="btn-primary w-full" type="button"
                hx-post="/user/payment/purchase/stripe" hx-swap="none"
                hx-vals='js:{
                    invoice_id: {$invoice->id},
                }'>
            <i class="ti ti-credit-card"></i> 前往 Stripe 支付
        </button>
        <button class="btn-secondary w-full" type="button"
                hx-post="/user/payment/purchase/stripe" hx-swap="none"
                hx-confirm="确认使用已绑定的支付方式立即扣款 ¥{$invoice->price}？"
                hx-vals='js:{
                    invoice_id: {$invoice->id},
                    use_saved_card: true,
                }'>
            <i class="ti ti-bolt"></i> 已绑卡快捷支付
        </button>
    </div>
    <p class="text-faint mt-2 text-xs">
        快捷支付将直接从你在
        <a href="/user/payment-method" class="text-primary">个人设置 → 支付方式</a>
        绑定的卡扣款
    </p>
</div>
