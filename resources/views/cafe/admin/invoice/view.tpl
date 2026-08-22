{include file='shell/admin_header.tpl' nav='invoice'}

<a href="/admin/invoice" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回账单列表
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">账单 #{$invoice->id}</h2>
        <p class="text-faint mt-1 text-sm">账单详情</p>
    </div>
    {if $invoice->status === 'unpaid'}
        <button id="mark-paid" class="btn-primary btn-sm">
            <i class="ti ti-checklist"></i> 标记为已支付
        </button>
    {/if}
</div>

<div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-2">
    <div class="c-card-pad">
        <h3 class="mb-3 text-base">基本信息</h3>
        <div class="kv-row"><span class="kv-key">提交用户</span><span class="kv-val">#{$invoice->user_id}</span></div>
        <div class="kv-row"><span class="kv-key">关联订单</span><span class="kv-val">#{$invoice->order_id}</span></div>
        <div class="kv-row"><span class="kv-key">账单金额</span><span class="text-primary text-base font-semibold">¥ {$invoice->price}</span></div>
        <div class="kv-row"><span class="kv-key">账单状态</span><span class="badge-neutral">{$invoice->status_text}</span></div>
        <div class="kv-row"><span class="kv-key">创建时间</span><span class="kv-val">{$invoice->create_time}</span></div>
        <div class="kv-row"><span class="kv-key">更新时间</span><span class="kv-val">{$invoice->update_time}</span></div>
        <div class="kv-row"><span class="kv-key">支付时间</span><span class="kv-val">{$invoice->pay_time}</span></div>
        {if $invoice->status === 'paid_gateway'}
            <div class="kv-row"><span class="kv-key">支付网关单号</span><span class="kv-val font-mono text-xs">{$paylist->tradeno}</span></div>
        {/if}
    </div>

    <div class="c-card-pad">
        <h3 class="mb-3 text-base">账单明细</h3>
        <div class="table-card border-hairline overflow-hidden rounded-(--radius-tile) border">
            <table>
                <thead>
                <tr>
                    <th>名称</th>
                    <th class="text-right">价格</th>
                </tr>
                </thead>
                <tbody>
                {foreach $invoice_content as $invoice_content_detail}
                    <tr>
                        <td class="text-ink">{$invoice_content_detail->name}</td>
                        <td class="text-ink text-right font-medium">¥ {$invoice_content_detail->price}</td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    </div>
</div>

{if $invoice->status === 'unpaid'}
    <script>
        window.INVOICE_ID = {$invoice->id};
    </script>
    {literal}
    <script>
        document.getElementById('mark-paid').addEventListener('click', function () {
            if (!confirm('确定将此账单标记为已支付？关联订单将被激活。')) return;
            fetch('/admin/invoice/' + window.INVOICE_ID + '/mark_paid', { method: 'POST' })
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
