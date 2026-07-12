{include file='shell/admin_header.tpl' nav='order'}

<a href="/admin/order" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回订单列表
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">订单 #{$order->id}</h2>
        <p class="text-faint mt-1 text-sm">订单详情</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="/admin/user/{$order->user_id}/edit" class="btn-secondary btn-sm">
            <i class="ti ti-user"></i> 关联用户
        </a>
        <a href="/admin/invoice/{$invoice->id}/view" class="btn-secondary btn-sm">
            <i class="ti ti-file-dollar"></i> 关联账单
        </a>
        {if $order->status === 'pending_payment'}
            <button id="cancel-order" class="btn-danger-soft btn-sm">
                <i class="ti ti-x"></i> 取消订单
            </button>
        {/if}
    </div>
</div>

<div class="grid items-start gap-5 lg:grid-cols-2">
    <div class="c-card-pad">
        <h3 class="mb-3 text-base">基本信息</h3>
        <div class="kv-row"><span class="kv-key">提交用户</span><span class="kv-val">#{$order->user_id}</span></div>
        <div class="kv-row"><span class="kv-key">商品</span><span class="kv-val">{$order->product_name} (#{$order->product_id})</span></div>
        <div class="kv-row"><span class="kv-key">商品类型</span><span class="value-pill">{$order->product_type_text}</span></div>
        <div class="kv-row"><span class="kv-key">优惠码</span><span class="kv-val">{$order->coupon|default:'—'}</span></div>
        <div class="kv-row"><span class="kv-key">订单金额</span><span class="text-primary text-base font-semibold">¥ {$order->price}</span></div>
        <div class="kv-row"><span class="kv-key">订单状态</span><span class="badge-neutral">{$order->status_text}</span></div>
        <div class="kv-row"><span class="kv-key">创建时间</span><span class="kv-val">{$order->create_time}</span></div>
        <div class="kv-row"><span class="kv-key">更新时间</span><span class="kv-val">{$order->update_time}</span></div>

        <div class="border-hairline my-3 border-t"></div>
        <h3 class="mb-3 text-base">商品内容</h3>
        {if $order->product_type === 'tabp' || $order->product_type === 'time'}
            <div class="kv-row"><span class="kv-key">商品时长</span><span class="kv-val">{$order->content->time} 天</span></div>
            <div class="kv-row"><span class="kv-key">等级时长</span><span class="kv-val">{$order->content->class_time} 天</span></div>
            <div class="kv-row"><span class="kv-key">等级</span><span class="value-pill">Lv. {$order->content->class}</span></div>
        {/if}
        {if $order->product_type === 'tabp' || $order->product_type === 'bandwidth' || $order->product_type === 'subscription'}
            <div class="kv-row"><span class="kv-key">可用流量</span><span class="kv-val">{$order->content->bandwidth} GB</span></div>
        {/if}
        {if $order->product_type === 'tabp' || $order->product_type === 'time' || $order->product_type === 'subscription'}
            <div class="kv-row"><span class="kv-key">用户分组</span><span class="kv-val">{$order->content->node_group}</span></div>
            <div class="kv-row">
                <span class="kv-key">速率限制</span>
                <span class="kv-val">{if $order->content->speed_limit === '0'}不限制{else}{$order->content->speed_limit} Mbps{/if}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">同时连接 IP 限制</span>
                <span class="kv-val">{if $order->content->ip_limit === '0'}不限制{else}{$order->content->ip_limit}{/if}</span>
            </div>
        {/if}
    </div>

    <div class="c-card-pad">
        <h3 class="mb-3 text-base">关联账单</h3>
        <div class="table-card border-hairline mb-3 overflow-hidden rounded-(--radius-tile) border">
            <table>
                <thead>
                <tr>
                    <th>名称</th>
                    <th class="text-right">价格</th>
                </tr>
                </thead>
                <tbody>
                {foreach $invoice->content as $invoice_content}
                    <tr>
                        <td class="text-ink">{$invoice_content->name}</td>
                        <td class="text-ink text-right font-medium">¥ {$invoice_content->price}</td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
        <div class="kv-row"><span class="kv-key">账单金额</span><span class="text-primary text-base font-semibold">¥ {$invoice->price}</span></div>
        <div class="kv-row"><span class="kv-key">账单状态</span><span class="badge-neutral">{$invoice->status}</span></div>
        <div class="kv-row"><span class="kv-key">创建时间</span><span class="kv-val">{$invoice->create_time}</span></div>
        <div class="kv-row"><span class="kv-key">更新时间</span><span class="kv-val">{$invoice->update_time}</span></div>
        <div class="kv-row"><span class="kv-key">支付时间</span><span class="kv-val">{$invoice->pay_time}</span></div>
    </div>
</div>

{if $order->status === 'pending_payment'}
    <script>
        window.ORDER_ID = {$order->id};
    </script>
    {literal}
    <script>
        document.getElementById('cancel-order').addEventListener('click', function () {
            if (!confirm('确定取消此订单？')) return;
            fetch('/admin/order/' + window.ORDER_ID + '/cancel', { method: 'POST' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    showToast(data.msg, data.ret === 1 ? 'success' : 'danger');
                    if (data.ret === 1) setTimeout(function () { location.reload(); }, 800);
                });
        });
    </script>
    {/literal}
{/if}

{include file='shell/admin_footer.tpl'}
