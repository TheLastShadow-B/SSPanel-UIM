{if !$probe['enabled']}
<div class="c-card-pad text-faint flex flex-col items-center gap-2 py-14 text-sm">
    <i class="ti ti-radar-off text-2xl"></i>
    该节点未开启回国检测
</div>
{else}
{* 一张卡：每家运营商一行 + 24 小时总历史条；点「N 个目标」展开每个目标自己的状态与历史条 *}
<section class="c-card overflow-hidden" aria-label="回国线路状态" x-data="{ selected: '' }">
    <div class="border-hairline flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b px-5 py-3">
        <h3 class="text-sm">回国线路</h3>
        <div class="text-body flex flex-wrap items-center gap-x-3.5 gap-y-1 text-xs">
            <span class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="green" aria-hidden="true"></span>正常</span>
            <span class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="yellow" aria-hidden="true"></span>波动</span>
            <span class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="red" aria-hidden="true"></span>中断</span>
            <span class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="gray" aria-hidden="true"></span>无记录</span>
            <span class="text-faint">最近检测 {$probe['updated_at']}</span>
        </div>
    </div>
    {foreach $probe['carriers'] as $carrier}
        <div class="probe-group t-acc" data-carrier="{$carrier['carrier']}" data-open="false">
            <div class="probe-row">
                <span class="probe-state-icon" data-status="{$carrier['status']}" aria-label="{$carrier['label']}"><span aria-hidden="true">{if $carrier['status'] === 'green'}✓{elseif $carrier['status'] === 'yellow'}!{else}×{/if}</span></span>
                <span class="probe-row-name">{$carrier['name']}</span>
                <button type="button" class="probe-row-toggle" data-toggle-targets aria-expanded="false" aria-controls="probe-targets-{$carrier['carrier']}">{count($carrier['targets'])} 个目标 <i class="t-acc-chevron ti ti-chevron-down" aria-hidden="true"></i></button>
                <span class="probe-row-uptime tabular-nums">{$carrier['history']['uptime']} 可用率</span>
            </div>
            <div class="probe-history" role="group" aria-label="{$carrier['name']} 最近 24 小时，每格 15 分钟">
                {foreach $carrier['history']['buckets'] as $bucket}
                    <button type="button" class="probe-bar" data-status="{$bucket['status']}" title="{$bucket['label']|escape}" aria-label="{$bucket['label']|escape}" @click="selected = $el.title" @focus="selected = $el.title" @mouseenter="selected = $el.title"></button>
                {/foreach}
            </div>
            <div id="probe-targets-{$carrier['carrier']}" class="t-acc-panel" inert aria-hidden="true">
                <div class="t-acc-panel-inner">
                    <div class="probe-targets">
                        {foreach $carrier['targets'] as $target}
                            <div class="probe-target">
                                <div class="probe-row is-target">
                                    <span class="probe-state-icon is-sm" data-status="{$target['status']}" aria-label="{$target['status_label']}"><span aria-hidden="true">{if $target['status'] === 'green'}✓{elseif $target['status'] === 'yellow'}!{else}×{/if}</span></span>
                                    <span class="probe-row-name">{$target['label']|escape}</span>
                                    <span class="probe-row-meta tabular-nums">{if $target['latency_ms'] !== null}{$target['latency_ms']} ms · {/if}成功 {$target['success']}</span>
                                    <span class="probe-row-uptime tabular-nums">{$target['history']['uptime']} 可用率</span>
                                </div>
                                <div class="probe-history is-target" role="group" aria-label="{$target['label']|escape} 最近 24 小时，每格 15 分钟">
                                    {foreach $target['history']['buckets'] as $bucket}
                                        <button type="button" class="probe-bar" data-status="{$bucket['status']}" title="{$bucket['label']|escape}" aria-label="{$bucket['label']|escape}" @click="selected = $el.title" @focus="selected = $el.title" @mouseenter="selected = $el.title"></button>
                                    {/foreach}
                                </div>
                            </div>
                        {foreachelse}
                            <p class="text-faint py-2 text-xs">尚未配置测试目标</p>
                        {/foreach}
                    </div>
                </div>
            </div>
        </div>
    {/foreach}
    <div class="border-hairline text-faint flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t px-5 py-2.5 text-xs">
        <span class="text-body" x-text="selected || '悬停或点击色块查看该时段详情'">悬停或点击色块查看该时段详情</span>
        {foreach $probe['carriers'] as $carrier}{if $carrier@first}<span class="tabular-nums">{$carrier['history']['start']} – 现在 · 每格 15 分钟</span>{/if}{/foreach}
    </div>
</section>
<p class="text-faint mt-5 text-xs leading-relaxed">检测周期：{$probe['interval_seconds']} 秒。黄色延迟阈值：{$probe['threshold_ms']} ms。可用率按有检测数据的轮次计算，至少一个目标连通即为可用；无记录的轮次不计入。历史格保留已确认的最严重状态，正常数据覆盖不足 80% 时显示灰色；最右一格为当前时段，按实时状态显示。TCP 建连耗时包含往返路径，不代表下载速度。</p>
{/if}
