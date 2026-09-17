{include file="shell/header.tpl" nav='server'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">节点状态</h2>
        <p class="text-faint mt-1 text-sm">按国家 / 地区查看节点在线情况与倍率</p>
    </div>
    <div class="flex gap-2">
        <a href="/user/rate" class="btn-secondary btn-sm"><i class="ti ti-chart-bar"></i> 流量倍率</a>
        <a href="/user/detect" class="btn-secondary btn-sm"><i class="ti ti-shield-search"></i> 审计规则</a>
    </div>
</div>

{if count($server_groups) > 0}
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2" x-data>
        <p class="text-body text-xs">{count($server_groups)} 个国家 / 地区 · 点击展开节点</p>
        <div class="flex gap-1" x-cloak>
            <button type="button" class="btn-secondary btn-sm" @click="$dispatch('cafe:regions-toggle', true)">全部展开</button>
            <button type="button" class="btn-secondary btn-sm" @click="$dispatch('cafe:regions-toggle', false)">全部收起</button>
        </div>
    </div>
{/if}

<div class="space-y-3">
    {foreach $server_groups as $group}
        <section id="region-{$group['code']}" class="t-acc c-card overflow-hidden" aria-labelledby="region-title-{$group['code']}"
                 x-data="{ open: false }" data-open="false" :data-open="String(open)"
                 @cafe:regions-toggle.window="open = $event.detail">
            <h3>
                <button type="button" id="region-title-{$group['code']}"
                        class="t-acc-head bg-tile/40 hover:bg-tile focus-visible:outline-primary flex w-full cursor-pointer items-center gap-3 px-5 py-4 text-left focus-visible:outline-2 focus-visible:-outline-offset-2 sm:px-6"
                        aria-expanded="false" :aria-expanded="open" aria-controls="region-nodes-{$group['code']}" @click="open = !open">
                    <span class="bg-card border-hairline flex size-10 shrink-0 items-center justify-center rounded-xl border text-xl" aria-hidden="true">
                        {if $group['flag']}{$group['flag']}{else}<i class="ti ti-world text-body"></i>{/if}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-base">{$group['name']}</span>
                        <span class="text-body mt-0.5 flex items-center gap-2 text-xs font-normal">
                            <span>{count($group['servers'])} 个节点</span>
                            <span class="text-faint" aria-hidden="true">/</span>
                            <span>{$group['online']} 个在线</span>
                        </span>
                    </span>
                    <i class="t-acc-chevron ti ti-chevron-down text-body shrink-0" aria-hidden="true"></i>
                </button>
            </h3>
            <div id="region-nodes-{$group['code']}" class="t-acc-panel" inert :inert="!open" aria-hidden="true" :aria-hidden="!open">
                <div class="t-acc-panel-inner">
                    <div class="divide-hairline border-hairline divide-y border-t">
                        {foreach $group['servers'] as $server}
                            <article class="px-5 py-5 sm:px-6" aria-labelledby="node-title-{$server['id']}">
                                <div class="grid items-center gap-4 xl:grid-cols-2 xl:gap-6">
                                    <div class="min-w-0">
                                        <h4 id="node-title-{$server['id']}" class="text-sm font-semibold wrap-anywhere">{$server['name']|escape}</h4>
                                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs">
                                            {if $server['color'] === 'green'}
                                                <span class="text-success inline-flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>在线</span>
                                            {elseif $server['color'] === 'red'}
                                                <span class="text-danger inline-flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>离线</span>
                                            {else}
                                                <span class="text-warning inline-flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>暂无数据</span>
                                            {/if}
                                            <span class="text-body">{$server['sort']|escape}</span>
                                            {if $server['connection_type'] !== 0}<span class="text-body">IPv6</span>{/if}
                                            {if $server['class'] === 0}
                                                <span class="text-body">免费节点</span>
                                            {else}
                                                <span class="text-body inline-flex items-center gap-1">
                                                    {if $user->class < $server['class']}<i class="ti ti-lock" aria-hidden="true"></i>{/if}
                                                    LV. {$server['class']}
                                                </span>
                                            {/if}
                                        </div>
                                    </div>
                                    <dl class="grid grid-cols-[1fr_1fr_1.6fr] gap-3 text-xs">
                                        <div class="min-w-0">
                                            <dt class="text-body">流量倍率</dt>
                                            <dd class="text-ink mt-1.5 font-medium">{if $server['is_dynamic_rate']}动态倍率{else}{$server['traffic_rate']} 倍{/if}</dd>
                                        </div>
                                        <div class="min-w-0">
                                            <dt class="text-body">在线人数</dt>
                                            <dd class="text-ink mt-1.5 font-medium tabular-nums">{$server['online_user']}</dd>
                                        </div>
                                        <div class="min-w-0">
                                            <dt class="text-body">已用 / 总流量</dt>
                                            <dd class="text-ink mt-1.5 font-medium tabular-nums">
                                                <span class="inline-block">{$server['node_bandwidth']}</span>
                                                <span class="text-body inline-block font-normal whitespace-nowrap"> / {$server['node_bandwidth_limit']}</span>
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                                {if $user->class < $server['class']}
                                    <p class="text-body mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                        <span>需要 LV. {$server['class']} 订阅</span>
                                        <a href="/user/product" class="text-primary inline-flex items-center gap-1 font-medium hover:underline">升级订阅 <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
                                    </p>
                                {/if}
                            </article>
                        {/foreach}
                    </div>
                </div>
            </div>
        </section>
    {foreachelse}
        <div class="c-card-pad text-faint flex flex-col items-center gap-2 py-14 text-sm">
            <i class="ti ti-server-off text-2xl"></i>
            暂无节点
        </div>
    {/foreach}
</div>

{include file="shell/footer.tpl"}
