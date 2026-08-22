{include file='shell/header.tpl' nav='order'}

<a href="/user/invoice" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回账单记录
</a>

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">账单 #{$invoice->id}</h2>
    <p class="text-faint mt-1 text-sm">账单详情与支付</p>
</div>

{if ($invoice->status === 'unpaid' || $invoice->status === 'partially_paid') && isset($smarty.get.paid)}
    <div class="c-card bg-primary-tint/60 mb-5 flex items-center gap-3 border-0 px-5 py-3.5">
        <span class="border-primary size-4 animate-spin rounded-full border-2 border-t-transparent"></span>
        <span class="text-ink text-sm">支付确认中，通常数秒内生效，页面将自动刷新…</span>
    </div>
{/if}

<div class="grid grid-cols-1 gap-5 {if $invoice->status === 'unpaid' || $invoice->status === 'partially_paid'}lg:grid-cols-3{/if}">
    <div class="flex flex-col gap-5 {if $invoice->status === 'unpaid' || $invoice->status === 'partially_paid'}lg:col-span-2{/if}">
        <div class="c-card-pad">
            <h3 class="mb-3 text-base">基本信息</h3>
            <div class="kv-row">
                <span class="kv-key">订单 ID</span>
                <span class="kv-val">#{$invoice->order_id}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">账单金额</span>
                <span class="text-primary text-base font-semibold">¥ {$invoice->price}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">账单状态</span>
                <span class="badge-neutral">{$invoice->status_text}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">创建时间</span>
                <span class="kv-val">{$invoice->create_time}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">更新时间</span>
                <span class="kv-val">{$invoice->update_time}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">支付时间</span>
                <span class="kv-val">{$invoice->pay_time}</span>
            </div>
            {if $invoice->status === 'paid_gateway'}
                <div class="kv-row">
                    <span class="kv-key">支付网关单号</span>
                    <span class="kv-val font-mono text-xs">{$paylist->tradeno}</span>
                </div>
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

    {if $invoice->status === 'unpaid' || $invoice->status === 'partially_paid'}
        <div x-data="{ paytab: '{if $invoice->type !== 'topup'}balance{else}gateway{/if}' }">
            <div class="c-card-pad">
                <h3 class="mb-3 text-base">支付方式</h3>
                {* 只有一种支付途径时不渲染 tab 栏,免得单个胶囊看起来像可点的按钮 *}
                {if $invoice->type !== 'topup' && count($payments) > 0}
                    <div class="pill-tabs mb-4">
                        <button class="pill-tab" :class="paytab === 'balance' && 'active'" @click="paytab = 'balance'">
                            <i class="ti ti-coins"></i> 余额
                        </button>
                        <button class="pill-tab" :class="paytab === 'gateway' && 'active'" @click="paytab = 'gateway'">
                            <i class="ti ti-credit-card"></i> 在线支付
                        </button>
                    </div>
                {/if}

                {if $invoice->type !== 'topup'}
                    <div x-show="paytab === 'balance'">
                        <div class="stat-tile mb-4 !text-left">
                            <div class="stat-label !mt-0">当前账户余额</div>
                            <div class="stat-value">¥ {$user->money}</div>
                        </div>
                        <button class="btn-primary w-full" type="button"
                                hx-post="/user/invoice/pay_balance" hx-swap="none"
                                hx-confirm="{if $user->money >= $invoice->price}确认使用余额支付本账单？将从账户余额中扣除 {$invoice->price} 元。{else}当前余额不足以全额支付，确认后将扣除全部余额 {$user->money} 元进行部分支付。{/if}"
                                hx-vals='js:{
                                    invoice_id: {$invoice->id},
                                }'>
                            余额支付
                        </button>
                    </div>
                {/if}
                {if count($payments) > 0}
                    <div x-show="paytab === 'gateway'" {if $invoice->type !== 'topup'}x-cloak{/if} class="flex flex-col gap-3">
                        {foreach from=$payments item=payment}
                            <div>
                                {$payment_name = $payment::_name()}
                                {include file="gateway/$payment_name.tpl"}
                            </div>
                        {/foreach}
                    </div>
                {/if}
                {if $invoice->type === 'topup' && count($payments) === 0}
                    <div class="text-faint py-6 text-center text-sm">暂无可用支付方式</div>
                {/if}
            </div>
        </div>
    {/if}
</div>

{if $invoice->status === 'unpaid' || $invoice->status === 'partially_paid'}
    <script>
        window.invoiceStatusUrl = '/user/invoice/{$invoice->id}/status';
    </script>
    {literal}
    <script>
        // 支付落账轮询:账单脱离待支付状态即整页刷新(网关回调通常数秒内到达)。
        // 上限 200 次(约 10 分钟)后停止,避免挂机页面空转。
        (function () {
            var polls = 0;
            var timer = setInterval(function () {
                polls += 1;
                if (polls > 200) {
                    clearInterval(timer);
                    return;
                }
                fetch(window.invoiceStatusUrl, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ret === 1
                            && data.invoice_status !== 'unpaid'
                            && data.invoice_status !== 'partially_paid') {
                            clearInterval(timer);
                            window.location.reload();
                        }
                    })
                    .catch(function () {});
            }, 3000);
        })();
    </script>
    {/literal}
{/if}

{include file='shell/footer.tpl'}
