{include file='shell/header.tpl' nav='invite'}

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">邀请返利</h2>
    <p class="text-faint mt-1 text-sm">分享邀请链接，好友消费后获得返利</p>
</div>

<div class="mb-5 grid gap-5 lg:grid-cols-3">

    {* ============ 累计返利大数字 ============ *}
    <div class="c-card-pad from-primary to-primary-hover flex flex-col justify-center bg-gradient-to-br !border-0 text-white">
        <div class="mb-1 flex items-center gap-2 text-sm text-white/75">
            <i class="ti ti-gift"></i>
            累计获得返利
        </div>
        <div class="text-3xl font-semibold tracking-tight text-white">¥ {$paybacks_sum}</div>
        <p class="mt-3 text-xs leading-relaxed text-white/70">
            被邀请用户的账单确认后，其金额的 <span class="font-semibold text-white">{$invite_reward_rate}%</span>
            将作为返利计入你的账户余额。部分商品的返利比例可能不同。
        </p>
    </div>

    {* ============ 邀请链接 ============ *}
    <div class="c-card-pad lg:col-span-2">
        <h3 class="mb-1 text-base">我的邀请链接</h3>
        <p class="text-faint mb-4 text-xs">好友通过此链接注册后自动与你绑定</p>
        <div class="bg-tile text-body mb-4 truncate rounded-(--radius-tile) px-3.5 py-2.5 font-mono text-xs" id="invite-url">
            {$invite_url}
        </div>
        <div class="flex flex-wrap gap-2">
            <button class="btn-primary btn-sm copy" data-clipboard-text="{$invite_url}">
                <i class="ti ti-copy"></i> 复制链接
            </button>
            <button class="btn-danger-soft btn-sm"
                    hx-post="/user/invite/reset" hx-swap="none"
                    hx-confirm="重置后旧的邀请链接将失效，确认重置？">
                <i class="ti ti-refresh-alert"></i> 重置链接
            </button>
        </div>
    </div>
</div>

{* ============ 返利记录 ============ *}
<div class="c-card">
    <div class="p-5 pb-3">
        <h3 class="text-base">返利记录</h3>
    </div>
    <div class="table-card overflow-x-auto">
        <table>
            <thead>
            <tr>
                <th>记录 ID</th>
                <th>被邀请用户</th>
                <th>返利金额</th>
                <th>时间</th>
            </tr>
            </thead>
            <tbody>
            {foreach $paybacks as $payback}
                <tr>
                    <td class="text-ink font-medium">#{$payback->id}</td>
                    <td class="text-ink">{$payback->user_name} <span class="text-faint">(UID {$payback->userid})</span></td>
                    <td><span class="text-success font-medium">+ ¥ {$payback->ref_get}</span></td>
                    <td class="text-faint">{$payback->datetime}</td>
                </tr>
            {foreachelse}
                <tr>
                    <td colspan="4">
                        <div class="text-faint flex flex-col items-center gap-2 py-10 text-sm">
                            <i class="ti ti-gift-off text-2xl"></i>
                            还没有返利记录，快去邀请好友吧
                        </div>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>

{include file='shell/footer.tpl'}
