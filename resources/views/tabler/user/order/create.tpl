{include file='user/header.tpl'}

<div class="page-wrapper">
    <div class="container-xl">
        <div class="page-header d-print-none text-white">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">
                        <span class="home-title">创建订单</span>
                    </h2>
                    <div class="page-pretitle my-3">
                        <span class="home-subtitle">创建商品订单</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <div class="row row-cards">
                <div class="col-sm-12 col-md-6 col-lg-9">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">订单内容</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-transparent table-responsive">
                                <tr hidden>
                                    <td>商品ID</td>
                                    <td id="product-id" class="text-end">{$product->id}</td>
                                </tr>
                                <tr>
                                    <td>商品名称</td>
                                    <td class="text-end">{$product->name}</td>
                                </tr>
                                <tr>
                                    <td>商品类型</td>
                                    <td class="text-end">{$product->type_text}</td>
                                </tr>
                                {if $product->type === 'tabp' || $product->type === 'time'}
                                    <tr>
                                        <td>商品时长</td>
                                        <td class="text-end">{$product->content->time} 天</td>
                                    </tr>
                                    <tr>
                                        <td>等级时长</td>
                                        <td class="text-end">{$product->content->class_time} 天</td>
                                    </tr>
                                    <tr>
                                        <td>等级</td>
                                        <td class="text-end">Lv. {$product->content->class}</td>
                                    </tr>
                                {/if}
                                {if $product->type === 'tabp' || $product->type === 'bandwidth'}
                                    <tr>
                                        <td>可用流量</td>
                                        <td class="text-end">{$product->content->bandwidth} GB</td>
                                    </tr>
                                {/if}
                                {if $product->type === 'tabp' || $product->type === 'time'}
                                    <tr>
                                        <td>速率限制</td>
                                        {if $product->content->speed_limit === '0'}
                                            <td class="text-end">不限制</td>
                                        {else}
                                            <td class="text-end">{$product->content->speed_limit} Mbps</td>
                                        {/if}
                                    </tr>
                                    <tr>
                                        <td>同时连接 IP 限制</td>
                                        {if $product->content->ip_limit === '0'}
                                            <td class="text-end">不限制</td>
                                        {else}
                                            <td class="text-end">{$product->content->ip_limit}</td>
                                        {/if}
                                    </tr>
                                {/if}
                                {if $isSubscription}
                                    <tr>
                                        <td>可用流量</td>
                                        <td class="text-end">{$product->content->bandwidth} GB/月</td>
                                    </tr>
                                    <tr>
                                        <td>等级</td>
                                        <td class="text-end">Lv. {$product->content->class}</td>
                                    </tr>
                                    <tr>
                                        <td>速率限制</td>
                                        {if $product->content->speed_limit === '0'}
                                            <td class="text-end">不限制</td>
                                        {else}
                                            <td class="text-end">{$product->content->speed_limit} Mbps</td>
                                        {/if}
                                    </tr>
                                    <tr>
                                        <td>同时连接 IP 限制</td>
                                        {if $product->content->ip_limit === '0'}
                                            <td class="text-end">不限制</td>
                                        {else}
                                            <td class="text-end">{$product->content->ip_limit}</td>
                                        {/if}
                                    </tr>
                                {/if}
                            </table>
                        </div>
                    </div>
                    {if $isSubscription}
                        <div class="card my-3">
                            <div class="card-header">
                                <h3 class="card-title">选择账单周期</h3>
                            </div>
                            <div class="card-body">
                                {if $hasActiveSubscription}
                                    <div class="alert alert-warning">
                                        你已有活跃的订阅，无法购买新的订阅。
                                    </div>
                                {else}
                                    <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column">
                                        {if $product->content->billing_cycle->month}
                                            <label class="form-selectgroup-item flex-fill">
                                                <input type="radio" name="billing_cycle" value="month"
                                                       class="form-selectgroup-input" checked
                                                       onchange="updateCyclePrice()">
                                                <div class="form-selectgroup-label d-flex align-items-center p-3">
                                                    <div class="me-3">
                                                        <span class="form-selectgroup-check"></span>
                                                    </div>
                                                    <div class="form-selectgroup-label-content d-flex align-items-center">
                                                        <div>
                                                            <div class="font-weight-medium">月付</div>
                                                            <div class="text-secondary">
                                                                <span id="price-month">{$product->price}</span> 元
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        {/if}
                                        {if $product->content->billing_cycle->quarter}
                                            <label class="form-selectgroup-item flex-fill">
                                                <input type="radio" name="billing_cycle" value="quarter"
                                                       class="form-selectgroup-input"
                                                       {if !$product->content->billing_cycle->month}checked{/if}
                                                       onchange="updateCyclePrice()">
                                                <div class="form-selectgroup-label d-flex align-items-center p-3">
                                                    <div class="me-3">
                                                        <span class="form-selectgroup-check"></span>
                                                    </div>
                                                    <div class="form-selectgroup-label-content d-flex align-items-center">
                                                        <div>
                                                            <div class="font-weight-medium">季付</div>
                                                            <div class="text-secondary">
                                                                {if $product->content->discount->quarter < 1}
                                                                    <del>{$product->price * 3} 元</del>&nbsp;
                                                                {/if}
                                                                <span id="price-quarter">{$product->price * 3 * $product->content->discount->quarter}</span> 元
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        {/if}
                                        {if $product->content->billing_cycle->year}
                                            <label class="form-selectgroup-item flex-fill">
                                                <input type="radio" name="billing_cycle" value="year"
                                                       class="form-selectgroup-input"
                                                       {if !$product->content->billing_cycle->month && !$product->content->billing_cycle->quarter}checked{/if}
                                                       onchange="updateCyclePrice()">
                                                <div class="form-selectgroup-label d-flex align-items-center p-3">
                                                    <div class="me-3">
                                                        <span class="form-selectgroup-check"></span>
                                                    </div>
                                                    <div class="form-selectgroup-label-content d-flex align-items-center">
                                                        <div>
                                                            <div class="font-weight-medium">年付</div>
                                                            <div class="text-secondary">
                                                                {if $product->content->discount->year < 1}
                                                                    <del>{$product->price * 12} 元</del>&nbsp;
                                                                {/if}
                                                                <span id="price-year">{$product->price * 12 * $product->content->discount->year}</span> 元
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        {/if}
                                    </div>
                                {/if}
                            </div>
                        </div>
                    {/if}
                </div>
                <div class="col-sm-12 col-md-6 col-lg-3">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">价格明细（元）</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-transparent table-responsive">
                                <tr>
                                    <td>商品价格</td>
                                    <td class="text-end" id="product-price-display">{$product->price}</td>
                                </tr>
                                <tr>
                                    <td>优惠码</td>
                                    <td class="text-end" id="coupon-code"></td>
                                </tr>
                                <tr>
                                    <td>优惠金额</td>
                                    <td class="text-end" id="product-buy-discount"></td>
                                </tr>
                                <tr>
                                    <td>实际支付</td>
                                    <td class="text-end" id="product-buy-total">{$product->price}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <div class="card my-3">
                        <div class="card-header">
                            <h3 class="card-title">优惠码</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="input-group mb-2">
                                    <input id="coupon" type="text" class="form-control"
                                           placeholder="填写优惠码，没有请留空">
                                    <button class="btn" type="button"
                                            hx-post="/user/coupon" hx-swap="none"
                                            hx-vals='js:{
                                                coupon: document.getElementById("coupon").value,
                                                product_id: {$product->id},
                                            }'>
                                        应用
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card my-3">
                        <div class="card-body">
                            {if $isSubscription}
                                <button class="btn btn-primary w-100 my-3"
                                        id="btn-create-order"
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
                                <button class="btn btn-primary w-100 my-3"
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
                </div>
            </div>
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
                // Reset coupon display when cycle changes
                document.getElementById('product-buy-discount').textContent = '';
                document.getElementById('coupon-code').textContent = '';
            }

            // Initialize on page load
            document.addEventListener('DOMContentLoaded', function() {
                updateCyclePrice();
            });
        </script>
    {/if}

    {include file='user/footer.tpl'}
