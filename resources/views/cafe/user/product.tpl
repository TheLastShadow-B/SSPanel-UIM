{include file='shell/header.tpl' nav='shop'}

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">商店</h2>
    <p class="text-faint mt-1 text-sm">选择适合你的订阅套餐或流量包</p>
</div>

<div x-data="{ tab: window.location.hash === '#bandwidth' ? 'bandwidth' : 'subscription' }">
    <div class="pill-tabs mb-6">
        <button class="pill-tab" :class="tab === 'subscription' && 'active'" @click="tab = 'subscription'">
            <i class="ti ti-star"></i> 订阅套餐
        </button>
        <button class="pill-tab" :class="tab === 'bandwidth' && 'active'" @click="tab = 'bandwidth'">
            <i class="ti ti-arrows-down-up"></i> 流量包
        </button>
    </div>

    {* ---------------- 订阅套餐 ---------------- *}
    <div x-show="tab === 'subscription'" class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        {foreach $subscriptions as $sub}
            <div class="c-card hover:border-primary flex flex-col p-6 transition-colors">
                <div class="text-faint text-xs font-medium tracking-wide uppercase">{$sub->name}</div>
                <div class="mt-3 mb-1 flex items-baseline gap-1">
                    <span class="text-ink text-3xl font-semibold tracking-tight">¥ {$sub->price}</span>
                    <span class="text-faint text-sm">/ 月</span>
                </div>
                <div class="mb-4 flex flex-wrap gap-1.5">
                    {if isset($sub->content->billing_cycle->quarter) && $sub->content->billing_cycle->quarter && isset($sub->content->discount->quarter) && $sub->content->discount->quarter < 1}
                        <span class="badge-success">季付 {($sub->content->discount->quarter * 10)|string_format:"%.0f"} 折</span>
                    {/if}
                    {if isset($sub->content->billing_cycle->year) && $sub->content->billing_cycle->year && isset($sub->content->discount->year) && $sub->content->discount->year < 1}
                        <span class="badge-success">年付 {($sub->content->discount->year * 10)|string_format:"%.0f"} 折</span>
                    {/if}
                </div>
                <div class="flex-1">
                    <div class="kv-row">
                        <span class="kv-key">每月流量</span>
                        <span class="kv-val">{$sub->content->bandwidth} GB</span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-key">等级</span>
                        <span class="value-pill">Lv. {$sub->content->class}</span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-key">连接速度</span>
                        <span class="kv-val">{if $sub->content->speed_limit == 0}不限制{else}{$sub->content->speed_limit} Mbps{/if}</span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-key">同时在线 IP</span>
                        <span class="kv-val">{if $sub->content->ip_limit == 0}不限制{else}{$sub->content->ip_limit} 个{/if}</span>
                    </div>
                </div>
                {if $hasActiveSubscription}
                    <button class="btn-secondary mt-5 w-full" disabled>您已有活跃订阅</button>
                {elseif $sub->stock === 0}
                    <button class="btn-secondary mt-5 w-full" disabled>告罄</button>
                {else}
                    <a href="/user/order/create?product_id={$sub->id}" class="btn-primary mt-5 w-full">购买</a>
                {/if}
            </div>
        {foreachelse}
            <div class="c-card-pad text-faint md:col-span-2 xl:col-span-3 flex flex-col items-center gap-2 py-14 text-sm">
                <i class="ti ti-shopping-bag text-2xl"></i>
                暂无可售订阅套餐
            </div>
        {/foreach}
    </div>

    {* ---------------- 流量包 ---------------- *}
    <div x-show="tab === 'bandwidth'" x-cloak class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        {foreach $bandwidths as $bandwidth}
            <div class="c-card hover:border-primary flex flex-col p-6 transition-colors">
                <div class="text-faint text-xs font-medium tracking-wide uppercase">{$bandwidth->name}</div>
                <div class="mt-3 mb-4 flex items-baseline gap-1">
                    <span class="text-ink text-3xl font-semibold tracking-tight">¥ {$bandwidth->price}</span>
                    <span class="text-faint text-sm">/ 次</span>
                </div>
                <div class="flex-1">
                    <div class="kv-row">
                        <span class="kv-key">增加流量</span>
                        <span class="kv-val">{$bandwidth->content->bandwidth} GB</span>
                    </div>
                </div>
                {if !$hasActiveSubscription}
                    <button class="btn-secondary mt-5 w-full" disabled>需要先购买订阅</button>
                {elseif $bandwidth->stock === 0}
                    <button class="btn-secondary mt-5 w-full" disabled>告罄</button>
                {else}
                    <a href="/user/order/create?product_id={$bandwidth->id}" class="btn-primary mt-5 w-full">购买</a>
                {/if}
            </div>
        {foreachelse}
            <div class="c-card-pad text-faint md:col-span-2 xl:col-span-3 flex flex-col items-center gap-2 py-14 text-sm">
                <i class="ti ti-arrows-down-up text-2xl"></i>
                暂无可售流量包
            </div>
        {/foreach}
    </div>
</div>

{include file='shell/footer.tpl'}
