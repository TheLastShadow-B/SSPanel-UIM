{include file='shell/admin_header.tpl' nav='dashboard'}

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">站点概况</h2>
    <p class="text-faint mt-1 text-sm">站点运营状态总览</p>
</div>

{* ============ 流水统计 ============ *}
<div class="mb-5 grid grid-cols-2 gap-4 xl:grid-cols-4">
    <div class="c-card flex items-center gap-3 p-5">
        <span class="bg-primary-tint text-primary flex size-11 shrink-0 items-center justify-center rounded-full text-lg">
            <i class="ti ti-calendar-event"></i>
        </span>
        <div class="min-w-0">
            <div class="text-ink truncate text-lg font-semibold">¥ {$today_income}</div>
            <div class="text-faint text-xs">本日流水</div>
        </div>
    </div>
    <div class="c-card flex items-center gap-3 p-5">
        <span class="bg-primary-tint text-primary flex size-11 shrink-0 items-center justify-center rounded-full text-lg">
            <i class="ti ti-calendar-minus"></i>
        </span>
        <div class="min-w-0">
            <div class="text-ink truncate text-lg font-semibold">¥ {$yesterday_income}</div>
            <div class="text-faint text-xs">昨日流水</div>
        </div>
    </div>
    <div class="c-card flex items-center gap-3 p-5">
        <span class="bg-warning-tint text-warning flex size-11 shrink-0 items-center justify-center rounded-full text-lg">
            <i class="ti ti-calendar-stats"></i>
        </span>
        <div class="min-w-0">
            <div class="text-ink truncate text-lg font-semibold">¥ {$this_month_income}</div>
            <div class="text-faint text-xs">本月流水</div>
        </div>
    </div>
    <div class="c-card flex items-center gap-3 p-5">
        <span class="bg-success-tint text-success flex size-11 shrink-0 items-center justify-center rounded-full text-lg">
            <i class="ti ti-calendar-plus"></i>
        </span>
        <div class="min-w-0">
            <div class="text-ink truncate text-lg font-semibold">¥ {$total_income}</div>
            <div class="text-faint text-xs">累计流水</div>
        </div>
    </div>
</div>

{* ============ 环形图 ============ *}
<div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">{$total_node} 个节点在线情况</h3>
        <div id="chart-node" class="flex flex-col items-center"></div>
    </div>
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">{$total_user} 位用户活跃情况</h3>
        <div id="chart-user" class="flex flex-col items-center"></div>
    </div>
    <div class="c-card-pad md:col-span-2 xl:col-span-1">
        <h3 class="mb-4 text-base">流量用量</h3>
        <div id="chart-traffic" class="flex flex-col items-center"></div>
    </div>
</div>

<script>
    window.ADMIN_CHARTS = {
        node: [
            { label: "在线", value: {$alive_node}, color: "var(--color-success)" },
            { label: "离线", value: {$total_node-$alive_node}, color: "var(--color-danger)" }
        ],
        user: [
            { label: "活动账户", value: {$active_user}, color: "var(--color-primary)" },
            { label: "闲置账户", value: {$inactive_user}, color: "var(--color-warning)" }
        ],
        traffic: [
            { label: "今日已用({$today_traffic})", value: {$raw_today_traffic}, color: "var(--color-primary)" },
            { label: "过去已用({$last_traffic})", value: {$raw_last_traffic}, color: "var(--color-warning)" },
            { label: "剩余流量({$unused_traffic})", value: {$raw_unused_traffic}, color: "var(--color-success)" }
        ]
    };
</script>
{literal}
<script>
    // 纯 SVG 环形图:分段圆环 + 图例
    function cafeDonut(elId, segments) {
        const box = document.getElementById(elId);
        if (!box) return;
        const total = segments.reduce(function (s, x) { return s + Math.max(0, x.value); }, 0) || 1;
        const R = 52, C = 2 * Math.PI * R;
        let offset = 0;
        let rings = '';
        segments.forEach(function (seg) {
            const frac = Math.max(0, seg.value) / total;
            rings += '<circle cx="60" cy="60" r="' + R + '" fill="none" stroke="' + seg.color + '"' +
                ' stroke-width="12" stroke-dasharray="' + (frac * C) + ' ' + C + '"' +
                ' stroke-dashoffset="' + (-offset * C) + '" />';
            offset += frac;
        });
        let legend = '';
        segments.forEach(function (seg) {
            const pct = (Math.max(0, seg.value) / total * 100).toFixed(0);
            legend += '<div class="flex items-center gap-2 text-xs">' +
                '<span class="inline-block size-2.5 rounded-full" style="background:' + seg.color + '"></span>' +
                '<span class="text-body">' + seg.label + '</span>' +
                '<span class="text-faint ml-auto">' + seg.value + ' · ' + pct + '%</span></div>';
        });
        box.innerHTML =
            '<svg width="140" height="140" viewBox="0 0 120 120" class="-rotate-90">' +
            '<circle cx="60" cy="60" r="52" fill="none" stroke="var(--color-tile)" stroke-width="12"/>' + rings +
            '</svg><div class="mt-4 flex w-full max-w-56 flex-col gap-1.5">' + legend + '</div>';
    }

    document.addEventListener('DOMContentLoaded', function () {
        cafeDonut('chart-node', window.ADMIN_CHARTS.node);
        cafeDonut('chart-user', window.ADMIN_CHARTS.user);
        cafeDonut('chart-traffic', window.ADMIN_CHARTS.traffic);
    });
</script>
{/literal}

{include file='shell/admin_footer.tpl'}
