{include file='shell/admin_header.tpl' nav='dashboard'}

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">站点概况</h2>
    <p class="text-faint mt-1 text-sm">站点运营状态总览</p>
</div>

{* ============ 统计瓦片 ============ *}
<div class="mb-5 grid grid-cols-2 gap-4 xl:grid-cols-4">
    <div class="c-card p-5">
        <div class="text-faint text-xs">本日流水</div>
        <div class="text-ink mt-1 text-2xl font-semibold tracking-tight">¥ {$today_income}</div>
        <div class="text-faint mt-1.5 text-xs">昨日 ¥ {$yesterday_income}</div>
    </div>
    <div class="c-card p-5">
        <div class="text-faint text-xs">本月流水</div>
        <div class="text-ink mt-1 text-2xl font-semibold tracking-tight">¥ {$this_month_income}</div>
        <div class="text-faint mt-1.5 text-xs">累计 ¥ {$total_income}</div>
    </div>
    <div class="c-card p-5">
        <div class="text-faint text-xs">节点在线</div>
        <div class="mt-1 flex items-baseline gap-1.5">
            <span class="text-ink text-2xl font-semibold tracking-tight">{$alive_node}</span>
            <span class="text-faint text-sm">/ {$total_node}</span>
        </div>
        <div class="meter mt-2.5">
            <span class="!bg-success" style="width: {if $total_node > 0}{(100 * $alive_node / $total_node)|string_format:'%.0f'}{else}0{/if}%"></span>
        </div>
        {if $total_node - $alive_node > 0}
            <div class="text-danger mt-1.5 text-xs">{$total_node - $alive_node} 个节点离线</div>
        {else}
            <div class="text-faint mt-1.5 text-xs">全部在线</div>
        {/if}
    </div>
    <div class="c-card p-5">
        <div class="text-faint text-xs">活跃用户</div>
        <div class="mt-1 flex items-baseline gap-1.5">
            <span class="text-ink text-2xl font-semibold tracking-tight">{$active_user}</span>
            <span class="text-faint text-sm">/ {$total_user}</span>
        </div>
        <div class="meter mt-2.5">
            <span style="width: {if $total_user > 0}{(100 * $active_user / $total_user)|string_format:'%.0f'}{else}0{/if}%"></span>
        </div>
        <div class="text-faint mt-1.5 text-xs">闲置 {$inactive_user} 个账户</div>
    </div>
</div>

{* ============ 近 14 天趋势 ============ *}
<div class="grid gap-5 xl:grid-cols-3">
    <div class="c-card-pad">
        <div class="mb-1 flex items-baseline justify-between">
            <h3 class="text-base">收入趋势</h3>
            <span class="text-faint text-xs">近 14 天 · ¥/天</span>
        </div>
        <div id="trend-income" class="mt-3 h-48"></div>
    </div>
    <div class="c-card-pad">
        <div class="mb-1 flex items-baseline justify-between">
            <h3 class="text-base">流量趋势</h3>
            <span class="text-faint text-xs">近 14 天 · GB/天</span>
        </div>
        <div id="trend-traffic" class="mt-3 h-48"></div>
    </div>
    <div class="c-card-pad">
        <div class="mb-1 flex items-baseline justify-between">
            <h3 class="text-base">新增用户</h3>
            <span class="text-faint text-xs">近 14 天 · 人/天</span>
        </div>
        <div id="trend-reg" class="mt-3 h-48"></div>
    </div>
</div>

<script>
    window.ADMIN_TRENDS = {
        income: { data: {$income_trend}, prefix: "¥ ", suffix: "" },
        traffic: { data: {$traffic_trend}, prefix: "", suffix: " GB" },
        reg: { data: {$reg_trend}, prefix: "", suffix: " 人" }
    };
</script>
{literal}
<script>
    // 单系列柱状趋势图:细柱、顶端 4px 圆角、基线平直、发丝网格、
    // 只直标峰值与最新值,其余靠悬停提示
    function cafeTrend(elId, cfg) {
        const box = document.getElementById(elId);
        if (!box) return;
        const data = cfg.data || [];
        const values = data.map(function (d) { return d.value; });
        const max = Math.max.apply(null, values.concat([0]));

        if (max <= 0) {
            box.innerHTML = '<div class="text-faint flex h-full flex-col items-center justify-center gap-2 text-sm">' +
                '<i class="ti ti-chart-bar-off text-2xl"></i>近 14 天暂无数据</div>';
            return;
        }

        // 整洁刻度:向上取整到 1/2/5 × 10^k
        const pow = Math.pow(10, Math.floor(Math.log10(max)));
        let niceMax = pow;
        [1, 2, 5, 10].some(function (m) { if (max <= m * pow) { niceMax = m * pow; return true; } return false; });

        const W = box.clientWidth || 360, H = box.clientHeight || 192;
        const padL = 34, padB = 18, padT = 14;
        const plotW = W - padL - 6, plotH = H - padT - padB;
        const n = data.length;
        const slot = plotW / n;
        const bw = Math.min(24, Math.max(6, slot - 4));
        const y0 = padT + plotH;

        function fmt(v) {
            const num = v >= 100 ? Math.round(v).toLocaleString() : (Math.round(v * 100) / 100);
            return cfg.prefix + num + cfg.suffix;
        }
        function bar(x, y, w, h) {
            const r = Math.min(4, w / 2, h);
            if (h <= 0.5) return '';
            return 'M' + x + ',' + y0 + ' L' + x + ',' + (y + r) +
                ' Q' + x + ',' + y + ' ' + (x + r) + ',' + y +
                ' L' + (x + w - r) + ',' + y +
                ' Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r) +
                ' L' + (x + w) + ',' + y0 + ' Z';
        }

        const maxIdx = values.indexOf(Math.max.apply(null, values));
        let svg = '<svg width="100%" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '">';

        // 网格 + y 刻度(0 / 半 / 满)
        [0, 0.5, 1].forEach(function (f) {
            const gy = y0 - plotH * f;
            svg += '<line x1="' + padL + '" y1="' + gy + '" x2="' + W + '" y2="' + gy +
                '" stroke="var(--color-hairline)" stroke-width="1"/>';
            const tick = niceMax * f;
            svg += '<text x="' + (padL - 6) + '" y="' + (gy + 3) + '" text-anchor="end" font-size="10" ' +
                'fill="var(--color-faint)">' + (tick >= 100 ? Math.round(tick).toLocaleString() : Math.round(tick * 10) / 10) + '</text>';
        });

        data.forEach(function (d, i) {
            const h = d.value / niceMax * plotH;
            const x = padL + i * slot + (slot - bw) / 2;
            const y = y0 - h;
            svg += '<path class="cbar" d="' + bar(x, y, bw, h) + '" fill="var(--color-primary)">' +
                '<title>' + d.date + ' · ' + fmt(d.value) + '</title></path>';

            // x 轴:每 3 天 + 最后一天
            if (i % 3 === 0 || i === n - 1) {
                svg += '<text x="' + (x + bw / 2) + '" y="' + (H - 4) + '" text-anchor="middle" font-size="10" ' +
                    'fill="var(--color-faint)">' + d.date + '</text>';
            }
            // 直标:峰值 + 最新值
            if ((i === maxIdx || i === n - 1) && d.value > 0) {
                svg += '<text x="' + (x + bw / 2) + '" y="' + (y - 5) + '" text-anchor="middle" font-size="10" ' +
                    'font-weight="600" fill="var(--color-ink)">' + fmt(d.value) + '</text>';
            }
        });

        svg += '</svg>';
        box.innerHTML = svg;
    }

    function drawTrends() {
        cafeTrend('trend-income', window.ADMIN_TRENDS.income);
        cafeTrend('trend-traffic', window.ADMIN_TRENDS.traffic);
        cafeTrend('trend-reg', window.ADMIN_TRENDS.reg);
    }

    document.addEventListener('DOMContentLoaded', drawTrends);
    let trendResizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(trendResizeTimer);
        trendResizeTimer = setTimeout(drawTrends, 200);
    });
</script>
{/literal}

{include file='shell/admin_footer.tpl'}
