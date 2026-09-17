{include file="shell/header.tpl" nav='server'}

<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">节点状态</h2>
        <p class="text-faint mt-1 text-sm">按国家 / 地区查看节点与回国线路状态 · 每分钟自动刷新</p>
    </div>
    <div class="flex gap-2">
        <a href="/user/rate" class="btn-secondary btn-sm"><i class="ti ti-chart-bar"></i> 流量倍率</a>
        <a href="/user/detect" class="btn-secondary btn-sm"><i class="ti ti-shield-search"></i> 审计规则</a>
    </div>
</div>

{if count($server_groups) > 0}
<div class="node-table" x-data="cafeNodeList()" data-carrier="" :data-carrier="picked">

    <section class="mb-6" aria-label="按运营商查看回国线路">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-xs">
            <p class="text-body" x-text="picked ? '已选择 ' + names[picked] + '：各地区内可用节点排在前面' : '选择你的运营商，只看对你有效的线路'">选择你的运营商，只看对你有效的线路</p>
            <ul class="text-body flex flex-wrap gap-x-3.5 gap-y-1" aria-label="图例">
                <li class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="green" aria-hidden="true"></span>正常</li>
                <li class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="yellow" aria-hidden="true"></span>波动</li>
                <li class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="red" aria-hidden="true"></span>中断</li>
                <li class="inline-flex items-center gap-1.5"><span class="probe-dot is-hollow" data-status="gray" aria-hidden="true"></span>无数据</li>
            </ul>
        </div>

        {* 桌面端：全部 + 三家运营商磁贴，点选即筛选 *}
        <div class="hidden grid-cols-2 gap-3 md:grid md:grid-cols-4" role="group" aria-label="选择运营商">
            <button type="button" class="node-tile is-on" :class="{ 'is-on': picked === '' }" aria-pressed="true" :aria-pressed="String(picked === '')" @click="select('')">
                <span class="text-ink flex items-center justify-between text-sm font-semibold">全部线路<span class="node-tile-check" aria-hidden="true"><i class="ti ti-check"></i></span></span>
                <span class="text-ink text-2xl leading-none font-semibold tabular-nums">{$node_online} <span class="text-faint text-sm font-medium">/ {$node_total}</span></span>
                <span class="text-body text-xs">{if $node_online === $node_total}节点全部在线{else}个节点在线{/if}</span>
            </button>
            {foreach $carrier_tally as $carrier}
                <button type="button" class="node-tile" :class="{ 'is-on': picked === '{$carrier['carrier']}' }" aria-pressed="false" :aria-pressed="String(picked === '{$carrier['carrier']}')" @click="select('{$carrier['carrier']}')">
                    <span class="text-ink flex items-center justify-between text-sm font-semibold">{$carrier['name']}<span class="node-tile-check" aria-hidden="true"><i class="ti ti-check"></i></span></span>
                    {include file="shell/node_tally.tpl" carrier=$carrier}
                </button>
            {/foreach}
        </div>

        {* 移动端：分段控件 + 地区跳转 + 汇总卡 *}
        <div class="md:hidden">
            <div class="node-seg-track" role="group" aria-label="选择运营商">
                <button type="button" class="node-seg is-on" :class="{ 'is-on': picked === '' }" aria-pressed="true" :aria-pressed="String(picked === '')" @click="select('')">全部</button>
                {foreach $carrier_tally as $carrier}
                    <button type="button" class="node-seg" :class="{ 'is-on': picked === '{$carrier['carrier']}' }" aria-pressed="false" :aria-pressed="String(picked === '{$carrier['carrier']}')" @click="select('{$carrier['carrier']}')">{$carrier['name']}</button>
                {/foreach}
            </div>
            <nav class="node-jumps mt-3 flex gap-2 overflow-x-auto" aria-label="跳转到地区">
                {foreach $server_groups as $group}
                    <a href="#region-{$group['code']}" class="node-jump">{if $group['flag']}<span aria-hidden="true">{$group['flag']}</span>{/if}{$group['name']}<span class="text-faint tabular-nums">{count($group['servers'])}</span></a>
                {/foreach}
            </nav>
            <div class="c-card mt-3 space-y-3 p-4" aria-label="线路总览">
                <p class="text-ink text-sm font-semibold tabular-nums">{$node_online} / {$node_total} {if $node_online === $node_total}节点全部在线{else}个节点在线{/if}</p>
                {foreach $carrier_tally as $carrier}
                    <div class="space-y-1.5">
                        <p class="text-ink text-xs font-semibold">{$carrier['name']}</p>
                        {include file="shell/node_tally.tpl" carrier=$carrier}
                    </div>
                {/foreach}
            </div>
        </div>
    </section>

    <div class="space-y-4">
        {foreach $server_groups as $group}
            <section id="region-{$group['code']}" class="c-card scroll-mt-4 overflow-hidden" aria-labelledby="region-title-{$group['code']}">
                <div class="flex items-center gap-3.5 px-5 py-4">
                    <span class="bg-tile flex size-10 shrink-0 items-center justify-center rounded-xl text-xl leading-none" aria-hidden="true">{if $group['flag']}{$group['flag']}{else}<i class="ti ti-world text-body"></i>{/if}</span>
                    <div class="min-w-0 flex-1">
                        <h3 id="region-title-{$group['code']}" class="text-base">{$group['name']}</h3>
                        <p class="text-faint mt-0.5 text-xs">{count($group['servers'])} 个节点 · {if $group['online'] === count($group['servers'])}全部在线{else}{$group['online']} 个在线{/if}</p>
                    </div>
                </div>
                <div class="node-head hidden md:flex" aria-hidden="true">
                    <span class="min-w-0 flex-1">节点</span>
                    {foreach $carrier_tally as $carrier}<span class="node-col" data-col="{$carrier['carrier']}">{$carrier['name']}</span>{/foreach}
                    <span class="node-rate">倍率</span>
                    <span class="node-users">在线人数</span>
                    <span class="node-chev"></span>
                </div>
                <div class="node-rows">
                    {foreach $group['servers'] as $server}
                        <div class="node-row" data-probe-node="{$server['id']}">
                            <div class="node-name">
                                {if $server['locked']}
                                    <i class="ti ti-lock text-faint shrink-0" aria-hidden="true"></i><span class="sr-only">需要更高等级</span>
                                {else}
                                    <span class="node-state" data-online="{$server['online']}" aria-hidden="true"></span><span class="sr-only">{if $server['online'] === 1}在线{elseif $server['online'] === -1}离线{else}暂无数据{/if}</span>
                                {/if}
                                <a href="/user/server/{$server['id']}" class="node-link {if $server['locked']}text-body{else}text-ink{/if}" aria-label="查看 {$server['name']|escape} 详情">{$server['display_name']|escape}</a>
                                <span class="node-chip">{$server['proto']}</span>
                                {if $server['connection_type'] !== 0}<span class="node-chip is-v6">IPv6</span>{/if}
                                <i class="ti ti-chevron-right text-faint ml-auto md:hidden" aria-hidden="true"></i>
                            </div>
                            <div class="node-carriers">
                                {foreach $carrier_tally as $carrier}
                                    {$probe = $server['probe_status'][$carrier['carrier']]}
                                    <span class="node-col probe-cell" data-col="{$carrier['carrier']}" data-probe-carrier="{$carrier['carrier']}" data-probe-name="{$carrier['name']}" data-status="{$probe['status']}" title="{$carrier['name']}：{$probe['label']}">
                                        <span class="node-col-label md:hidden">{$carrier['name']}</span>
                                        <span class="probe-dot" aria-hidden="true"></span>
                                        <span class="probe-pill probe-tag" data-status="{$probe['status']}" data-probe-label>{if $probe['status'] === 'green'}正常{elseif $probe['status'] === 'yellow'}波动{elseif $probe['status'] === 'red'}中断{else}无数据{/if}</span>
                                        <span class="sr-only" data-probe-sr>：{$probe['label']}</span>
                                    </span>
                                {/foreach}
                            </div>
                            {if $server['locked']}
                                <div class="node-meta node-meta-lock">
                                    <span class="probe-pill" data-status="gray">需 LV.{$server['class']}</span>
                                    <a href="/user/product" class="text-primary relative z-10 inline-flex items-center gap-0.5 font-medium hover:underline">升级订阅 <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
                                </div>
                            {else}
                                <div class="node-meta">
                                    <span class="node-rate">{if $server['is_dynamic_rate']}<span class="node-chip">动态倍率</span>{else}{$server['traffic_rate']} 倍{/if}</span>
                                    <span class="md:hidden" aria-hidden="true">·</span>
                                    <span class="node-users">{$server['online_user']}<span class="md:hidden"> 人在线</span></span>
                                </div>
                            {/if}
                            <span class="node-chev hidden md:flex" aria-hidden="true"><i class="ti ti-chevron-right"></i></span>
                        </div>
                    {/foreach}
                </div>
            </section>
        {/foreach}
    </div>
</div>
{else}
    <div class="c-card-pad text-faint flex flex-col items-center gap-2 py-14 text-sm">
        <i class="ti ti-server-off text-2xl"></i>
        暂无节点
    </div>
{/if}

<script src="/theme/cafe/js/node-probe.js?v={$config['assets_version']}"></script>
{include file="shell/footer.tpl"}
