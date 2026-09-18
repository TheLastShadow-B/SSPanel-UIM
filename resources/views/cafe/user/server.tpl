{include file="shell/header.tpl" nav='server'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-2xl font-semibold tracking-tight">节点状态</h2>
    <div class="flex gap-2">
        <a href="/user/rate" class="btn-secondary btn-sm"><i class="ti ti-chart-bar"></i> 流量倍率</a>
        <a href="/user/detect" class="btn-secondary btn-sm"><i class="ti ti-shield-search"></i> 审计规则</a>
    </div>
</div>

{if count($server_groups) > 0}
<div class="node-table" x-data="cafeNodeList()">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-xs">
        {* 移动端：地区跳转（目标已折叠时先展开） *}
        <nav class="node-jumps flex gap-2 overflow-x-auto md:hidden" aria-label="跳转到地区">
            {foreach $server_groups as $group}
                <a href="#region-{$group['code']}" class="node-jump" @click="reveal('{$group['code']}')">{if $group['flag']}<span aria-hidden="true">{$group['flag']}</span>{/if}{$group['name']}<span class="text-faint tabular-nums">{count($group['servers'])}</span></a>
            {/foreach}
        </nav>
        {if $carriers}
        <ul class="text-body ml-auto flex flex-wrap gap-x-3.5 gap-y-1" aria-label="图例">
            <li class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="green" aria-hidden="true"></span>正常</li>
            <li class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="yellow" aria-hidden="true"></span>波动</li>
            <li class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="red" aria-hidden="true"></span>中断</li>
        </ul>
        {/if}
    </div>

    <div class="space-y-4">
        {foreach $server_groups as $group}
            <section id="region-{$group['code']}" class="t-acc c-card scroll-mt-4 overflow-hidden" data-open="true" :data-open="String(isOpen('{$group['code']}'))" aria-labelledby="region-title-{$group['code']}">
                <h3>
                    <button type="button" id="region-title-{$group['code']}" class="node-region-head" aria-controls="region-nodes-{$group['code']}" aria-expanded="true" :aria-expanded="String(isOpen('{$group['code']}'))" @click="toggle('{$group['code']}')">
                        <span class="node-region-flag" aria-hidden="true">{if $group['flag']}{$group['flag']}{else}<i class="ti ti-world text-body"></i>{/if}</span>
                        <span class="node-region-name">{$group['name']}</span>
                        <span class="node-region-meta tabular-nums">{$group['online']}/{count($group['servers'])}</span>
                        <i class="t-acc-chevron ti ti-chevron-down" aria-hidden="true"></i>
                    </button>
                </h3>
                <div id="region-nodes-{$group['code']}" class="t-acc-panel" aria-hidden="false" :inert="!isOpen('{$group['code']}')" :aria-hidden="String(!isOpen('{$group['code']}'))">
                <div class="t-acc-panel-inner">
                <div class="node-head hidden md:flex" aria-hidden="true">
                    <span class="min-w-0 flex-1">节点</span>
                    {foreach $carriers as $code => $name}<span class="node-col">{$name}</span>{/foreach}
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
                                    <span class="node-state" data-online="{$server['online']}" aria-hidden="true"></span><span class="sr-only">{if $server['online'] === 1}在线{else}离线{/if}</span>
                                {/if}
                                <a href="/user/server/{$server['id']}" class="node-link {if $server['locked']}text-body{else}text-ink{/if}" aria-label="查看 {$server['name']|escape} 详情">{$server['display_name']|escape}</a>
                                <span class="node-chip">{$server['proto']}</span>
                                {if $server['connection_type'] !== 0}<span class="node-chip is-v6">IPv6</span>{/if}
                                <i class="ti ti-chevron-right text-faint ml-auto md:hidden" aria-hidden="true"></i>
                            </div>
                            {if $carriers}
                                {if $server['probe_status'] === null}
                                    <div class="node-carriers"><span class="node-unmonitored" style="--cols: {count($carriers)}">未开启检测</span></div>
                                {else}
                                    <div class="node-carriers">
                                        {foreach $carriers as $code => $name}
                                            {$probe = $server['probe_status'][$code]}
                                            <span class="node-col probe-cell" data-probe-carrier="{$code}" data-probe-name="{$name}" data-status="{$probe['status']}" title="{$name}：{$probe['label']}">
                                                <span class="node-col-label md:hidden">{$name}</span>
                                                <span class="probe-dot" aria-hidden="true"></span>
                                                <span class="probe-pill probe-tag" data-status="{$probe['status']}" data-probe-label>{if $probe['status'] === 'green'}正常{elseif $probe['status'] === 'yellow'}波动{else}中断{/if}</span>
                                                <span class="sr-only" data-probe-sr>：{$probe['label']}</span>
                                            </span>
                                        {/foreach}
                                    </div>
                                {/if}
                            {/if}
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
                </div>
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
