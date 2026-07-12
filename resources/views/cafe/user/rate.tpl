{include file='shell/header.tpl' nav='server'}

<a href="/user/server" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回节点状态
</a>

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">流量倍率</h2>
    <p class="text-faint mt-1 text-sm">查看节点的每小时流量倍率</p>
</div>

<div class="c-card-pad" x-data="{ open: false }">
    <div class="mb-5 flex items-center justify-between gap-3">
        <h3 class="text-base">倍率图表</h3>
        <div class="relative">
            <button class="btn-secondary btn-sm" @click="open = !open">
                <span id="dropdown-toggle">{$node_list[0]['name']}</span>
                <i class="ti ti-chevron-down"></i>
            </button>
            <div x-show="open" x-cloak @click.outside="open = false"
                 class="c-card absolute right-0 z-20 mt-2 max-h-72 w-56 overflow-y-auto p-2 shadow-lg">
                {foreach $node_list as $node}
                    <a class="side-link cursor-pointer" @click="open = false"
                       hx-post="/user/rate" hx-swap="none"
                       hx-vals='{ "node_id": "{$node['id']}" }'>
                        {$node['name']}
                    </a>
                {/foreach}
            </div>
        </div>
    </div>
    <div id="rate-chart" class="h-52"></div>
    {* 初始加载第一个节点的数据 *}
    <div hidden hx-post="/user/rate" hx-swap="none" hx-trigger="load"
         hx-vals='{ "node_id": "{$node_list[0]['id']}" }'></div>
</div>

{literal}
<script>
    // 后端通过 HX-Trigger 派发 drawChart 事件,detail = {msg: 节点名, data: [24 个倍率]}
    document.body.addEventListener('drawChart', function (evt) {
        document.getElementById('dropdown-toggle').textContent = evt.detail.msg;

        const box = document.getElementById('rate-chart');
        const data = evt.detail.data || [];
        const W = box.clientWidth || 640, H = box.clientHeight || 208;
        const padB = 18, n = 24, gap = 6;
        const bw = (W - gap * (n - 1)) / n;
        const max = Math.max.apply(null, data.concat([1]));
        let svg = '<svg width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '">';
        for (let i = 0; i < n; i++) {
            const v = Number(data[i] || 0);
            const h = Math.max(v / max * (H - padB - 20), 3);
            const x = i * (bw + gap), y = H - padB - h;
            svg += '<rect x="' + x + '" y="' + y + '" width="' + bw + '" height="' + h + '" rx="3" fill="var(--color-primary)">' +
                   '<title>' + String(i).padStart(2, '0') + ':00 — ' + v + ' 倍</title></rect>';
            svg += '<text x="' + (x + bw / 2) + '" y="' + (y - 6) + '" text-anchor="middle" font-size="9" ' +
                   'fill="var(--color-faint)">' + v + '</text>';
            if (i % 4 === 0) {
                svg += '<text x="' + (x + bw / 2) + '" y="' + (H - 4) + '" text-anchor="middle" ' +
                       'font-size="10" fill="var(--color-faint)">' + String(i).padStart(2, '0') + '</text>';
            }
        }
        svg += '</svg>';
        box.innerHTML = svg;
    });
</script>
{/literal}

{include file='shell/footer.tpl'}
