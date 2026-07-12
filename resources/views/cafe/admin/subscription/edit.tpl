{include file='shell/admin_header.tpl' nav='subscription'}

<a href="/admin/subscription" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回订阅管理
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">订阅 #{$subscription->id}</h2>
        <p class="text-faint mt-1 text-sm">{$userEmail}</p>
    </div>
    <button id="save-subscription" class="btn-primary btn-sm"
            hx-put="/admin/subscription/{$subscription->id}/price" hx-swap="none"
            hx-vals='js:{
                renewal_price: document.getElementById("renewal_price").value,
                end_date: document.getElementById("end_date").value,
            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

<div class="grid items-start gap-5 lg:grid-cols-2">
    <div class="c-card-pad">
        <h3 class="mb-3 text-base">订阅详情</h3>
        <div class="kv-row">
            <span class="kv-key">订阅 ID</span>
            <span class="kv-val">#{$subscription->id}</span>
        </div>
        <div class="kv-row">
            <span class="kv-key">用户</span>
            <span class="kv-val">{$userEmail} (UID {$subscription->user_id})</span>
        </div>
        <div class="kv-row">
            <span class="kv-key">套餐名称</span>
            <span class="value-pill">{$subscription->content->name}</span>
        </div>
        <div class="kv-row">
            <span class="kv-key">账单周期</span>
            <span class="value-pill">{$subscription->billing_cycle_text}</span>
        </div>
        <div class="kv-row">
            <span class="kv-key">状态</span>
            <span class="badge-neutral">{$subscription->status_text}</span>
        </div>
        <div class="kv-row">
            <span class="kv-key">开始日期</span>
            <span class="kv-val">{$subscription->start_date}</span>
        </div>
        <div class="kv-row">
            <span class="kv-key">流量重置日</span>
            <span class="kv-val">{$subscription->reset_day}</span>
        </div>
    </div>

    <div class="c-card-pad">
        <h3 class="mb-4 text-base">可编辑项</h3>
        <div class="mb-3">
            <label class="field-label" for="end_date">到期日期</label>
            <input id="end_date" type="date" class="field-input" value="{$subscription->end_date}">
        </div>
        <div>
            <label class="field-label" for="renewal_price">续费价格 *</label>
            <input id="renewal_price" type="text" class="field-input" value="{$subscription->renewal_price}">
        </div>
    </div>
</div>

{include file='shell/admin_footer.tpl'}
