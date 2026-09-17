<div class="mb-4 flex flex-wrap items-center justify-between gap-3 text-xs">
    <div class="text-body flex flex-wrap gap-4">
        <span class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="green"></span>正常</span>
        <span class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="yellow"></span>波动 / 高延迟</span>
        <span class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="red"></span>中断</span>
        <span class="inline-flex items-center gap-1.5"><span class="probe-dot" data-status="gray"></span>无数据</span>
    </div>
    <span class="text-faint">最近检测：{$probe['updated_at']}</span>
</div>
<div class="space-y-4">
{foreach $probe['carriers'] as $carrier}
    <section class="c-card-pad" aria-label="{$carrier['name']} 检测状态" x-data="{ selected: '' }">
        <details class="probe-components" data-carrier="{$carrier['carrier']}">
            <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3">
                <span class="inline-flex flex-wrap items-center gap-3">
                    <span class="probe-state-icon" data-status="{$carrier['status']}" aria-label="{$carrier['label']}">
                        <span aria-hidden="true">{if $carrier['status'] === 'green'}✓{elseif $carrier['status'] === 'yellow'}!{elseif $carrier['status'] === 'red'}×{else}−{/if}</span>
                    </span>
                    <span class="text-base font-semibold">{$carrier['name']}</span>
                    <span class="text-faint inline-flex items-center gap-1 text-sm">{count($carrier['targets'])} 个目标 <i class="ti ti-chevron-down text-xs" aria-hidden="true"></i></span>
                </span>
                <span class="text-faint text-sm tabular-nums">{$carrier['history']['uptime']} 可用率</span>
            </summary>
            <div class="border-hairline mt-4 border-t pt-2">
                {foreach $carrier['targets'] as $target}
                    <div class="text-body flex flex-wrap items-center justify-between gap-2 py-2 text-xs">
                        <span class="inline-flex items-center gap-2"><span class="probe-dot" data-status="{$target['status']}"></span>{$target['label']|escape} · <span data-target-state>{$target['status_label']}</span></span>
                        <span class="tabular-nums" data-target-metric>{if $target['latency_ms'] !== null}{$target['latency_ms']} ms{else}—{/if} · 成功 {$target['success']}</span>
                    </div>
                {foreachelse}<p class="text-faint py-3 text-sm">尚未配置测试目标</p>{/foreach}
            </div>
        </details>
        <div class="probe-history mt-5" role="group" aria-label="{$carrier['name']} 历史状态，每格 15 分钟">
            {foreach $carrier['history']['buckets'] as $bucket}
                <button type="button" class="probe-bar" data-status="{$bucket['status']}" title="{$bucket['label']|escape}" aria-label="{$bucket['label']|escape}" @click="selected = $el.title" @focus="selected = $el.title" @mouseenter="selected = $el.title"></button>
            {/foreach}
        </div>
        <div class="text-faint mt-2 flex justify-between gap-2 text-xs"><span>{$carrier['history']['start']}</span><span>每格 15 分钟</span><span>现在</span></div>
        <p class="text-body mt-3 min-h-5 text-xs" x-text="selected || '数据覆盖率 {$carrier['history']['coverage']}% · 点击色块查看详情'">数据覆盖率 {$carrier['history']['coverage']}% · 点击色块查看详情</p>
    </section>
{/foreach}
</div>
<p class="text-faint mt-5 text-xs leading-relaxed">黄色延迟阈值：{$probe['threshold_ms']} ms。可用率按有检测数据的轮次计算，至少一个目标连通即为可用；无数据不计入。历史格保留已确认的最严重状态，正常数据覆盖不足 80% 时显示灰色。TCP 建连耗时包含往返路径，不代表下载速度。</p>
