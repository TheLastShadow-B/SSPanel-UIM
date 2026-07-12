{include file='shell/header.tpl' nav='money'}

<div x-data="{ showTopup: false, showGiftcard: false }">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight">余额</h2>
            <p class="text-faint mt-1 text-sm">查看账户余额变动记录</p>
        </div>
        <div class="flex gap-2">
            <button class="btn-primary btn-sm" @click="showTopup = true">
                <i class="ti ti-plus"></i> 余额充值
            </button>
            <button class="btn-secondary btn-sm" @click="showGiftcard = true">
                <i class="ti ti-gift-card"></i> 兑换礼品卡
            </button>
        </div>
    </div>

    {* ============ 余额大数字 ============ *}
    <div class="c-card-pad mb-5 flex items-center gap-4">
        <span class="bg-primary-tint text-primary flex size-12 items-center justify-center rounded-full text-xl">
            <i class="ti ti-wallet"></i>
        </span>
        <div>
            <div class="text-faint text-xs">当前余额</div>
            <div class="text-ink text-2xl font-semibold tracking-tight">¥ {$user->money}</div>
        </div>
    </div>

    {* ============ 变动记录 ============ *}
    <div class="c-card">
        <div class="p-5 pb-3">
            <h3 class="text-base">变动记录</h3>
        </div>
        <div class="table-card overflow-x-auto">
            <table>
                <thead>
                <tr>
                    <th>事件 ID</th>
                    <th>变动前</th>
                    <th>变动后</th>
                    <th>变动金额</th>
                    <th>备注</th>
                    <th>时间</th>
                </tr>
                </thead>
                <tbody>
                {foreach $moneylogs as $moneylog}
                    <tr>
                        <td class="text-ink font-medium">#{$moneylog->id}</td>
                        <td>¥ {$moneylog->before}</td>
                        <td>¥ {$moneylog->after}</td>
                        <td>
                            {if $moneylog->amount >= 0}
                                <span class="text-success font-medium">+{$moneylog->amount}</span>
                            {else}
                                <span class="text-danger font-medium">{$moneylog->amount}</span>
                            {/if}
                        </td>
                        <td class="text-ink">{$moneylog->remark}</td>
                        <td class="text-faint">{$moneylog->create_time}</td>
                    </tr>
                {foreachelse}
                    <tr>
                        <td colspan="6">
                            <div class="text-faint flex flex-col items-center gap-2 py-10 text-sm">
                                <i class="ti ti-wallet-off text-2xl"></i>
                                暂无余额变动记录
                            </div>
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    </div>

    {* ============ 充值模态 ============ *}
    <template x-teleport="body">
        <div x-show="showTopup" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="showTopup = false"></div>
            <div class="c-card relative w-full max-w-sm p-6 shadow-xl" @keydown.escape.window="showTopup = false">
                <h3 class="mb-1 text-base">余额充值</h3>
                <p class="text-faint mb-4 text-xs">充值订单创建后前往账单页完成支付</p>
                <input id="topup_amount" type="number" step="10" min="1" class="field-input mb-5"
                       placeholder="请输入要充值的金额">
                <div class="flex justify-end gap-2">
                    <button class="btn-secondary btn-sm" @click="showTopup = false">取消</button>
                    <button class="btn-primary btn-sm" @click="showTopup = false"
                            hx-post="/user/order/create" hx-swap="none"
                            hx-vals='js:{
                                amount: document.getElementById("topup_amount").value,
                                type: "topup"
                            }'>
                        充值
                    </button>
                </div>
            </div>
        </div>
    </template>

    {* ============ 礼品卡模态 ============ *}
    <template x-teleport="body">
        <div x-show="showGiftcard" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="showGiftcard = false"></div>
            <div class="c-card relative w-full max-w-sm p-6 shadow-xl" @keydown.escape.window="showGiftcard = false">
                <h3 class="mb-1 text-base">兑换礼品卡</h3>
                <p class="text-faint mb-4 text-xs">兑换成功后金额自动计入余额</p>
                <input id="giftcard" type="text" class="field-input mb-5"
                       placeholder="输入礼品卡卡号">
                <div class="flex justify-end gap-2">
                    <button class="btn-secondary btn-sm" @click="showGiftcard = false">取消</button>
                    <button class="btn-primary btn-sm" @click="showGiftcard = false"
                            hx-post="/user/giftcard" hx-swap="none"
                            hx-vals='js:{ giftcard: document.getElementById("giftcard").value }'>
                        兑换
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>

{include file='shell/footer.tpl'}
