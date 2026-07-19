{include file='shell/header.tpl' nav='dashboard'}

<a href="/user" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回我的订阅
</a>

{* ============ 待支付账单提醒 ============ *}
{if $pendingInvoice !== null}
    <div class="c-card bg-warning-tint/70 mb-5 flex flex-wrap items-center gap-3 border-0 px-5 py-3.5">
        <i class="ti ti-alert-circle text-warning text-xl"></i>
        <div class="text-ink flex-1 text-sm font-medium">当前有一笔待支付的续费账单，支付后订阅将自动续期。</div>
        <a href="/user/invoice/{$pendingInvoice->id}/view" class="btn-primary btn-sm !bg-warning">前往支付</a>
    </div>
{/if}

{* ============ 实体头(移动端纵向堆叠,桌面端左右布局)============ *}
<div class="mb-6 flex flex-col gap-5 lg:flex-row lg:items-center">
    <div class="flex min-w-0 flex-1 items-center gap-4">
        <span class="bg-primary-tint text-primary flex size-14 shrink-0 items-center justify-center rounded-full text-2xl lg:size-16 lg:text-3xl">
            <i class="ti ti-coffee"></i>
        </span>
        <div class="min-w-0">
            <h2 class="truncate text-xl font-semibold tracking-tight">
                {if $subscription !== null}{$subscription->content->name}{elseif $user->class > 0}LV.{$user->class} 订阅{else}尚未订阅{/if}
            </h2>
            <div class="mt-1.5 flex flex-wrap items-center gap-2 text-sm">
                {if $subscription !== null}
                    {if $subscription->status === 'active'}
                        <span class="badge-success"><i class="ti ti-circle-check"></i> {$subscription->status_text}</span>
                    {elseif $subscription->status === 'pending_renewal'}
                        <span class="badge-warning"><i class="ti ti-clock"></i> {$subscription->status_text}</span>
                    {else}
                        <span class="badge-neutral">{$subscription->status_text}</span>
                    {/if}
                    <span class="text-faint">{$subscription->start_date|substr:0:10} 至 {$subscription->end_date|substr:0:10}</span>
                {elseif $user->class > 0}
                    <span class="badge-success"><i class="ti ti-circle-check"></i> 有效</span>
                    <span class="text-faint">{$user->class_expire|substr:0:10} 到期</span>
                {else}
                    <span class="badge-neutral">未订阅</span>
                {/if}
            </div>
        </div>
    </div>

    {* 速览条 *}
    <div class="c-card flex shrink-0 divide-x divide-(--color-hairline)">
        <div class="flex-1 px-5 py-4 lg:flex-none lg:px-6">
            <div class="text-faint text-xs">剩余流量</div>
            <div class="text-ink mt-0.5 text-lg font-semibold">{$user->unusedTraffic()}</div>
            <div class="mt-2 flex h-8 items-center">
                {if $subscription !== null}
                    <a href="/user/product#bandwidth" class="btn-primary btn-sm h-8"><i class="ti ti-plus"></i> 加购流量包</a>
                {else}
                    <a href="/user/product" class="btn-primary btn-sm h-8"><i class="ti ti-shopping-bag"></i> 订购套餐</a>
                {/if}
            </div>
        </div>
        <div class="flex-1 px-5 py-4 lg:flex-none lg:px-6">
            <div class="text-faint text-xs">流量重置日</div>
            <div class="text-ink mt-0.5 text-lg font-semibold">
                {if $subscription !== null}{$subscription->next_reset_date}{else}--{/if}
            </div>
            <div class="mt-2 flex h-8 items-center">
                {if $subscription !== null && $subscription->auto_renew}
                    <span class="badge-success h-8 px-3.5"><i class="ti ti-circle-check"></i> 自动续费已开启</span>
                {elseif $subscription !== null}
                    <span class="badge-neutral h-8 px-3.5">自动续费已关闭</span>
                {/if}
            </div>
        </div>
    </div>
</div>

{* ============ 胶囊 tab(支持 #settings 锚点直达)============ *}
<div x-data="{ tab: window.location.hash === '#settings' ? 'settings' : 'overview' }">
    <div class="pill-tabs mb-5">
        <button class="pill-tab" :class="tab === 'overview' && 'active'" @click="tab = 'overview'">总览</button>
        <button class="pill-tab" :class="tab === 'settings' && 'active'" @click="tab = 'settings'">设置</button>
    </div>

    {* ---------------- 总览 ---------------- *}
    <div x-show="tab === 'overview'" class="grid gap-5 lg:grid-cols-3">

        <div class="c-card-pad lg:col-span-2">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-base">用量统计</h3>
                <span class="text-faint text-xs">今日各小时用量（MB）</span>
            </div>
            <div class="mb-5 grid grid-cols-3 gap-3">
                <div class="stat-tile">
                    <div class="stat-value">{$user->todayUsedTraffic()}</div>
                    <div class="stat-label">今日已用</div>
                </div>
                <div class="stat-tile">
                    <div class="stat-value">{$user->lastUsedTraffic()}</div>
                    <div class="stat-label">过去已用</div>
                </div>
                <div class="stat-tile">
                    <div class="stat-value">{$user->enableTraffic()}</div>
                    <div class="stat-label">套餐总量</div>
                </div>
            </div>
            {if $public_setting['traffic_log']}
                <div id="hourly-chart" class="h-44"></div>
            {else}
                <div class="text-faint flex h-44 flex-col items-center justify-center gap-2 text-sm">
                    <i class="ti ti-chart-line text-2xl"></i>
                    每小时用量统计未启用
                </div>
            {/if}
        </div>

        <div class="flex flex-col gap-5">
            {* 流量环 *}
            <div class="c-card-pad flex flex-col items-center">
                <h3 class="mb-4 self-start text-base">流量余量</h3>
                <div class="relative">
                    <svg width="150" height="150" viewBox="0 0 120 120" class="-rotate-90">
                        <circle cx="60" cy="60" r="52" fill="none" stroke="var(--color-tile)" stroke-width="10"/>
                        <circle cx="60" cy="60" r="52" fill="none" stroke="var(--color-primary)" stroke-width="10"
                                stroke-linecap="round"
                                stroke-dasharray="326.7"
                                stroke-dashoffset="{(326.7 * (100 - $user->unusedTrafficPercent()) / 100)|string_format:'%.1f'}"/>
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <div class="text-ink text-xl font-semibold">{$user->unusedTrafficPercent()|string_format:'%.0f'}%</div>
                        <div class="text-faint text-xs">剩余</div>
                    </div>
                </div>
                <div class="text-faint mt-3 text-xs">
                    已用 {$user->usedTraffic()} / 共 {$user->enableTraffic()}
                </div>
            </div>

            {* 在线设备 *}
            <div class="c-card flex-1">
                <div class="flex items-center justify-between px-5 pt-4 pb-2">
                    <h3 class="text-base">在线设备</h3>
                    <span class="text-faint text-xs">5 分钟内活跃</span>
                </div>
                {if $online_ips && count($online_ips) > 0}
                    <div class="max-h-56 overflow-y-auto px-5 pb-4">
                        {foreach $online_ips as $ip}
                            <div class="kv-row">
                                <div class="min-w-0">
                                    <div class="text-ink truncate font-mono text-xs">{$ip->formatted_ip}</div>
                                    <div class="text-faint mt-0.5 text-xs">{$ip->location}</div>
                                </div>
                                <span class="badge-primary shrink-0">{$ip->node_name}</span>
                            </div>
                        {/foreach}
                    </div>
                {else}
                    <div class="text-faint flex flex-col items-center gap-2 px-5 pt-4 pb-8 text-sm">
                        <i class="ti ti-plug-off text-2xl"></i>
                        暂无在线连接
                    </div>
                {/if}
            </div>
        </div>
    </div>

    {* ---------------- 设置:客户端导入为主内容(左 2/3),订阅计划靠右 ---------------- *}
    <div x-show="tab === 'settings'" x-cloak class="grid items-start gap-5 lg:grid-cols-3">

        {* 订阅计划(含订阅安全) *}
        <div class="c-card-pad order-last">
            <h3 class="mb-3 text-base">订阅计划</h3>
            {if $subscription !== null}
                <div class="kv-row">
                    <span class="kv-key">套餐</span>
                    <span class="value-pill">{$subscription->content->name}</span>
                </div>
                <div class="kv-row">
                    <span class="kv-key">周期流量</span>
                    <span class="value-pill">{$subscription->content->bandwidth} GB</span>
                </div>
                <div class="kv-row">
                    <span class="kv-key">当前周期</span>
                    <span class="kv-val">{$subscription->start_date|substr:0:10} 至 {$subscription->end_date|substr:0:10}</span>
                </div>
                <div class="kv-row">
                    <span class="kv-key">续费价格</span>
                    <span class="kv-val">¥ {$subscription->renewal_price} · {$subscription->billing_cycle_text}</span>
                </div>
                <div class="kv-row">
                    <span class="kv-key">自动续费</span>
                    {if $subscription->auto_renew}
                        <button class="btn-danger-soft btn-sm"
                                hx-post="/user/subscription/cancel" hx-swap="none"
                                hx-confirm="取消后当前周期结束将不再自动续费，确认取消？">
                            取消自动续费
                        </button>
                    {else}
                        <button class="btn-primary btn-sm"
                                hx-post="/user/subscription/enable" hx-swap="none">
                            开启自动续费
                        </button>
                    {/if}
                </div>
            {else}
                <div class="text-faint flex flex-col items-center gap-3 py-10 text-sm">
                    <i class="ti ti-shopping-bag text-2xl"></i>
                    还没有订阅计划
                    <a href="/user/product" class="btn-primary btn-sm">去商店看看</a>
                </div>
            {/if}

            <div class="border-hairline mt-2 mb-4 border-t"></div>
            <p class="text-faint mb-3 text-xs leading-relaxed">
                订阅链接等同于账户的使用凭证。如怀疑泄露请立即重置，重置后所有客户端需重新导入。
            </p>
            <button class="btn-danger-soft btn-sm"
                    hx-post="/user/edit/url_reset" hx-swap="none"
                    hx-confirm="重置后旧的订阅链接将立即失效，所有客户端需重新导入。确认重置？">
                <i class="ti ti-refresh-alert"></i> 重置订阅链接
            </button>
        </div>

        {* ============ 客户端导入 ============ *}
        <div class="c-card-pad lg:col-span-2" x-data="clientImport()">
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-base">客户端导入</h3>
                <span class="badge-primary"><i class="ti ti-device-desktop"></i> 检测到你正在使用 <span x-text="os"></span></span>
            </div>
            <p class="text-faint mb-4 text-xs">选择你的客户端,一键导入订阅或复制该客户端专用的订阅链接</p>

            {* 推荐客户端 *}
            <div class="mb-5 flex flex-col gap-3">
                <template x-for="c in recommended" :key="c.name">
                    <div class="bg-primary-tint/40 border-primary/20 flex flex-col gap-3 rounded-(--radius-tile) border p-4 sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1">
                            <div class="text-ink flex items-center gap-2 text-sm font-semibold">
                                <i class="ti ti-rocket text-primary"></i>
                                <span x-text="c.name"></span>
                                <span class="badge-primary">推荐</span>
                            </div>
                            <div class="text-faint mt-0.5 text-xs" x-text="c.description"></div>
                        </div>
                        <div class="grid w-full shrink-0 grid-cols-3 gap-1.5 sm:w-[21.5rem]">
                            <a class="btn-primary btn-sm" :href="c.importUrl"><i class="ti ti-link"></i> 一键导入</a>
                            <button class="btn-secondary btn-sm copy" :data-clipboard-text="subUrl(c)"><i class="ti ti-copy"></i> 复制订阅</button>
                            <a class="btn-outline btn-sm" :href="dlUrl(c)" :target="c.isAppStore ? '_blank' : null">
                                <i class="ti" :class="c.isAppStore ? 'ti-brand-appstore' : 'ti-download'"></i>
                                <span x-text="c.isAppStore ? 'App Store' : '下载'"></span>
                            </a>
                        </div>
                    </div>
                </template>
            </div>

            {* 全部平台 *}
            <div class="border-hairline overflow-hidden rounded-(--radius-tile) border">
                <template x-for="platform in platforms" :key="platform">
                    <div class="border-hairline border-b last:border-b-0">
                        <button class="hover:bg-tile/60 flex w-full cursor-pointer items-center gap-2.5 px-4 py-3 text-left transition-colors"
                                @click="open = open === platform ? null : platform">
                            <i class="ti text-body text-lg" :class="icons[platform] || 'ti-devices'"></i>
                            <span class="text-ink flex-1 text-sm font-medium" x-text="platform"></span>
                            <i class="ti ti-chevron-down text-faint transition-transform" :class="open === platform && 'rotate-180'"></i>
                        </button>
                        <div x-show="open === platform" x-cloak class="bg-tile/40">
                            <template x-for="c in data[platform]" :key="c.name">
                                <div class="border-hairline flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-ink text-sm font-medium" x-text="c.name"></div>
                                        <div class="text-faint mt-0.5 text-xs" x-text="c.description"></div>
                                    </div>
                                    <div class="grid w-full shrink-0 grid-cols-3 gap-1.5 sm:w-[21.5rem]">
                                        <a class="btn-primary btn-sm" :href="c.importUrl"><i class="ti ti-link"></i> 一键导入</a>
                                        <button class="btn-secondary btn-sm copy" :data-clipboard-text="subUrl(c)"><i class="ti ti-copy"></i> 复制订阅</button>
                                        <a class="btn-outline btn-sm" :href="dlUrl(c)" :target="c.isAppStore ? '_blank' : null">
                                            <i class="ti" :class="c.isAppStore ? 'ti-brand-appstore' : 'ti-download'"></i>
                                            <span x-text="c.isAppStore ? 'App Store' : '下载'"></span>
                                        </a>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
    window.CAFE_SUB = "{$UniversalSub}";
    window.CAFE_TRAFFIC = {$traffic_logs|default:'[]'};
    window.CAFE_CLIENTS = {$clientData|default:'{ }'};
    window.CAFE_ICONS = {$platformIcons|default:'{ }'};
    window.CAFE_R2 = {if $config['enable_r2_client_download']}true{else}false{/if};
</script>
{include file='shell/client_import.tpl'}
{literal}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // 每小时用量:纯 SVG 柱状图
        const box = document.getElementById('hourly-chart');
        if (!box) return;
        const data = window.CAFE_TRAFFIC || [];
        // 全天无用量:画空坐标轴只会像坏了,给明确的空状态
        if (!data.some(function (v) { return v > 0; })) {
            box.innerHTML = '<div class="text-faint flex h-full flex-col items-center justify-center gap-2 text-sm">' +
                '<i class="ti ti-chart-bar-off text-2xl"></i>今日暂无用量数据</div>';
            return;
        }
        const W = box.clientWidth || 560, H = box.clientHeight || 176;
        const padB = 18, n = 24, gap = 6;
        const bw = (W - gap * (n - 1)) / n;
        const max = Math.max.apply(null, data.concat([1]));
        // width 100% + viewBox:窗口/容器尺寸变化时随容器缩放,不产生横向溢出
        let svg = '<svg width="100%" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">';
        for (let i = 0; i < n; i++) {
            const v = data[i] || 0;
            const h = Math.max(v / max * (H - padB - 8), 3);
            const x = i * (bw + gap), y = H - padB - h;
            const color = v > 0 ? 'var(--color-primary)' : 'var(--color-tile)';
            svg += '<rect x="' + x + '" y="' + y + '" width="' + bw + '" height="' + h + '" rx="3" fill="' + color + '">' +
                   '<title>' + String(i).padStart(2, '0') + ':00 — ' + v + ' MB</title></rect>';
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
