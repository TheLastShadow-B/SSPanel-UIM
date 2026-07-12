{include file="shell/header.tpl" nav='server'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">节点状态</h2>
        <p class="text-faint mt-1 text-sm">查看节点在线情况与倍率</p>
    </div>
    <div class="flex gap-2">
        <a href="/user/rate" class="btn-secondary btn-sm"><i class="ti ti-chart-bar"></i> 流量倍率</a>
        <a href="/user/detect" class="btn-secondary btn-sm"><i class="ti ti-shield-search"></i> 审计规则</a>
    </div>
</div>

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    {foreach $servers as $server}
        <div class="c-card p-5 {if $user->class < $server['class']}opacity-70{/if}">
            <div class="mb-3 flex items-center gap-3">
                {if $server['color'] === 'green'}
                    <span class="relative flex size-2.5 shrink-0">
                        <span class="bg-success absolute inline-flex h-full w-full animate-ping rounded-full opacity-60"></span>
                        <span class="bg-success relative inline-flex size-2.5 rounded-full"></span>
                    </span>
                {elseif $server['color'] === 'red'}
                    <span class="bg-danger inline-flex size-2.5 shrink-0 rounded-full"></span>
                {else}
                    <span class="bg-warning inline-flex size-2.5 shrink-0 rounded-full"></span>
                {/if}
                <h3 class="min-w-0 flex-1 truncate text-sm font-semibold">{$server['name']}</h3>
                {if $server['class'] === 0}
                    <span class="badge-neutral shrink-0">免费</span>
                {else}
                    <span class="badge-primary shrink-0">LV. {$server['class']}</span>
                {/if}
            </div>
            <div class="text-faint mb-3 text-xs">
                流量 {$server['node_bandwidth']} / {$server['node_bandwidth_limit']}
            </div>
            <div class="flex flex-wrap gap-1.5">
                <span class="badge-neutral"><i class="ti ti-users"></i> {$server['online_user']}</span>
                <span class="badge-neutral">
                    {if $server['is_dynamic_rate']}动态倍率{else}{$server['traffic_rate']} 倍{/if}
                </span>
                <span class="badge-neutral">{$server['sort']}</span>
                {if $server['connection_type'] !== 0}
                    <span class="badge-neutral">IPv6</span>
                {/if}
            </div>
            {if $user->class < $server['class']}
                <div class="bg-warning-tint text-warning mt-3 rounded-(--radius-tile) px-3 py-2 text-xs">
                    当前账户等级不足，<a href="/user/product" class="font-medium underline">前往商店</a>升级订阅后可用
                </div>
            {/if}
        </div>
    {foreachelse}
        <div class="c-card-pad text-faint md:col-span-2 xl:col-span-3 flex flex-col items-center gap-2 py-14 text-sm">
            <i class="ti ti-server-off text-2xl"></i>
            暂无节点
        </div>
    {/foreach}
</div>

{include file="shell/footer.tpl"}
