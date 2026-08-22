{include file='shell/header.tpl' nav='shop'}

<a href="/user/product" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回商店
</a>

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">确认订单</h2>
    <p class="text-faint mt-1 text-sm">核对商品信息并完成下单</p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="flex flex-col gap-5 lg:col-span-2">

        {* ============ 订单内容 ============ *}
        <div class="c-card-pad">
            <h3 class="mb-3 text-base">订单内容</h3>
            <span id="product-id" hidden>{$product->id}</span>
            <div class="kv-row">
                <span class="kv-key">商品名称</span>
                <span class="kv-val">{$product->name}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">商品类型</span>
                <span class="value-pill">{$product->type_text}</span>
            </div>
            {if $product->type === 'tabp' || $product->type === 'time'}
                <div class="kv-row">
                    <span class="kv-key">商品时长</span>
                    <span class="kv-val">{$product->content->time} 天</span>
                </div>
                <div class="kv-row">
                    <span class="kv-key">等级时长</span>
                    <span class="kv-val">{$product->content->class_time} 天</span>
                </div>
                <div class="kv-row">
                    <span class="kv-key">等级</span>
                    <span class="value-pill">Lv. {$product->content->class}</span>
                </div>
            {/if}
            {if $product->type === 'tabp' || $product->type === 'bandwidth'}
                <div class="kv-row">
                    <span class="kv-key">可用流量</span>
                    <span class="kv-val">{$product->content->bandwidth} GB</span>
                </div>
            {/if}
            {if $product->type === 'tabp' || $product->type === 'time'}
                <div class="kv-row">
                    <span class="kv-key">速率限制</span>
                    <span class="kv-val">{if $product->content->speed_limit === '0'}不限制{else}{$product->content->speed_limit} Mbps{/if}</span>
                </div>
                <div class="kv-row">
                    <span class="kv-key">同时连接 IP 限制</span>
                    <span class="kv-val">{if $product->content->ip_limit === '0'}不限制{else}{$product->content->ip_limit}{/if}</span>
                </div>
            {/if}
            {if $isSubscription}
                <div class="kv-row">
                    <span class="kv-key">每月流量</span>
                    <span class="kv-val">{$product->content->bandwidth} GB</span>
                </div>
                <div class="kv-row">
                    <span class="kv-key">等级</span>
                    <span class="value-pill">Lv. {$product->content->class}</span>
                </div>
                <div class="kv-row">
                    <span class="kv-key">速率限制</span>
                    <span class="kv-val">{if $product->content->speed_limit === '0'}不限制{else}{$product->content->speed_limit} Mbps{/if}</span>
                </div>
                <div class="kv-row">
                    <span class="kv-key">同时连接 IP 限制</span>
                    <span class="kv-val">{if $product->content->ip_limit === '0'}不限制{else}{$product->content->ip_limit}{/if}</span>
                </div>
            {/if}
        </div>

        {* ============ 账单周期 ============ *}
        {if $isSubscription}
            <div class="c-card-pad">
                <h3 class="mb-3 text-base">选择账单周期</h3>
                {if $hasActiveSubscription}
                    <div class="bg-warning-tint text-warning rounded-(--radius-tile) px-4 py-3 text-sm">
                        你已有活跃的订阅，无法购买新的订阅。
                    </div>
                {else}
                    <div class="flex flex-col gap-2.5">
                        {if $product->content->billing_cycle->month}
                            <label class="border-hairline has-checked:border-primary has-checked:bg-primary-tint/40 flex cursor-pointer items-center justify-between rounded-xl border p-4 transition-colors">
                                <div class="flex items-center gap-3">
                                    <input type="radio" name="billing_cycle" value="month" class="accent-primary size-4" checked
                                           onchange="updateCyclePrice()">
                                    <span class="text-ink text-sm font-medium">月付</span>
                                </div>
                                <span class="text-body text-sm"><span id="price-month">{$product->price}</span> 元</span>
                            </label>
                        {/if}
                        {if $product->content->billing_cycle->quarter}
                            <label class="border-hairline has-checked:border-primary has-checked:bg-primary-tint/40 flex cursor-pointer items-center justify-between rounded-xl border p-4 transition-colors">
                                <div class="flex items-center gap-3">
                                    <input type="radio" name="billing_cycle" value="quarter" class="accent-primary size-4"
                                           {if !$product->content->billing_cycle->month}checked{/if}
                                           onchange="updateCyclePrice()">
                                    <span class="text-ink text-sm font-medium">季付</span>
                                </div>
                                <span class="text-body text-sm">
                                    {if $product->content->discount->quarter < 1}
                                        <del class="text-faint">{$product->price * 3} 元</del>&nbsp;
                                    {/if}
                                    <span id="price-quarter">{$product->price * 3 * $product->content->discount->quarter}</span> 元
                                </span>
                            </label>
                        {/if}
                        {if $product->content->billing_cycle->year}
                            <label class="border-hairline has-checked:border-primary has-checked:bg-primary-tint/40 flex cursor-pointer items-center justify-between rounded-xl border p-4 transition-colors">
                                <div class="flex items-center gap-3">
                                    <input type="radio" name="billing_cycle" value="year" class="accent-primary size-4"
                                           {if !$product->content->billing_cycle->month && !$product->content->billing_cycle->quarter}checked{/if}
                                           onchange="updateCyclePrice()">
                                    <span class="text-ink text-sm font-medium">年付</span>
                                </div>
                                <span class="text-body text-sm">
                                    {if $product->content->discount->year < 1}
                                        <del class="text-faint">{$product->price * 12} 元</del>&nbsp;
                                    {/if}
                                    <span id="price-year">{$product->price * 12 * $product->content->discount->year}</span> 元
                                </span>
                            </label>
                        {/if}
                    </div>
                    <p class="text-faint mt-3 text-xs leading-relaxed">
                        购买后默认开启自动续费——到期自动续费，优先扣账户余额，余额不足时扣已绑定的银行卡；可随时在『我的订阅』取消。
                    </p>
                {/if}
            </div>
        {/if}
    </div>

    {* ============ 右列:价格 + 优惠码 + 提交 ============ *}
    <div class="flex flex-col gap-5">
        <div class="c-card-pad">
            <h3 class="mb-3 text-base">价格明细</h3>
            <div class="kv-row">
                <span class="kv-key">商品价格</span>
                <span class="kv-val">¥ <span id="product-price-display">{$product->price}</span></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">优惠码</span>
                <span class="kv-val" id="coupon-code"></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">优惠金额</span>
                <span class="kv-val" id="product-buy-discount"></span>
            </div>
            <div class="kv-row">
                <span class="kv-key">实际支付</span>
                <span class="text-primary text-lg font-semibold">¥ <span id="product-buy-total">{$product->price}</span></span>
            </div>
        </div>

        <div class="c-card-pad">
            <h3 class="mb-3 text-base">优惠码</h3>
            <div class="flex gap-2">
                <input id="coupon" type="text" class="field-input flex-1" placeholder="没有请留空">
                <button class="btn-secondary btn-sm shrink-0" type="button"
                        hx-post="/user/coupon" hx-swap="none"
                        hx-vals='js:{
                            coupon: document.getElementById("coupon").value,
                            product_id: {$product->id},
                        }'>
                    应用
                </button>
            </div>
        </div>

        {if $isSubscription}
            <button class="btn-primary w-full" id="btn-create-order"
                    hx-post="/user/order/create" hx-swap="none"
                    hx-vals='js:{
                        type: "subscription",
                        coupon: document.getElementById("coupon").value,
                        product_id: {$product->id},
                        billing_cycle: document.querySelector("input[name=billing_cycle]:checked")?.value || "",
                    }'
                    {if $hasActiveSubscription}disabled{/if}>
                创建订单
            </button>
        {else}
            <button class="btn-primary w-full"
                    hx-post="/user/order/create" hx-swap="none"
                    hx-vals='js:{
                        type: "product",
                        coupon: document.getElementById("coupon").value,
                        product_id: {$product->id},
                    }'>
                创建订单
            </button>
        {/if}
    </div>
</div>

{if $isSubscription}
    <script>
        var monthlyPrice = {$product->price};
        var discountQuarter = {$product->content->discount->quarter|default:1};
        var discountYear = {$product->content->discount->year|default:1};

        function updateCyclePrice() {
            var selected = document.querySelector('input[name=billing_cycle]:checked');
            if (!selected) return;

            var cycle = selected.value;
            var price = 0;

            if (cycle === 'month') {
                price = monthlyPrice;
            } else if (cycle === 'quarter') {
                price = Math.round(monthlyPrice * 3 * discountQuarter * 100) / 100;
            } else if (cycle === 'year') {
                price = Math.round(monthlyPrice * 12 * discountYear * 100) / 100;
            }

            document.getElementById('product-price-display').textContent = price;
            document.getElementById('product-buy-total').textContent = price;
            document.getElementById('product-buy-discount').textContent = '';
            document.getElementById('coupon-code').textContent = '';
        }

        document.addEventListener('DOMContentLoaded', function () {
            updateCyclePrice();
        });
    </script>
{/if}

{include file='shell/footer.tpl'}
