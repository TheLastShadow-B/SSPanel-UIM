{include file='shell/admin_header.tpl' nav='node-probe'}

<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">节点检测</h2>
        <p class="text-body mt-1 text-sm">由节点上的 XrayR 执行，配置保存后自动下发 · 每轮每个目标 3 次，单次超时 3 秒</p>
    </div>
    {if $installed}
        <span class="probe-pill !text-sm" data-status="{if $overview['reporting']}green{else}gray{/if}">
            <span class="probe-dot" data-status="{if $overview['reporting']}green{else}gray{/if}"></span>
            {if $overview['reporting']}{$overview['reporting']} 个节点在上报 · {$updated}{else}暂无节点上报{/if}
        </span>
    {/if}
</div>

{if !$installed}
    <section class="c-card-pad bg-warning-tint border-warning/30">
        <h3 class="text-warning flex items-center gap-2 text-base font-semibold">
            <i class="ti ti-alert-triangle" aria-hidden="true"></i> 需要先执行数据库迁移
        </h3>
        <p class="text-body mt-2 text-sm">节点检测的数据表尚未创建，请在面板服务器执行：</p>
        <code class="bg-card text-ink mt-3 block rounded-xl px-4 py-3 text-sm">php xcat Migration latest</code>
        <p class="text-faint mt-3 text-xs leading-relaxed">迁移完成后刷新本页，即可配置测试目标与节点开关。</p>
    </section>
{else}

{* ============ 概览:三网汇总 + 覆盖 ============ *}
{$node_percent = count($nodes) ? $node_enabled * 100 / count($nodes) : 0}
<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    {foreach $overview['carriers'] as $c}
        <div class="c-card p-4">
            <div class="flex items-center justify-between gap-2">
                <span class="inline-flex items-center gap-2">
                    <span class="probe-code">{$c['code']}</span>
                    <span class="text-ink text-sm font-semibold">{$c['name']}</span>
                </span>
                <span class="probe-pill" data-status="{$c['status']}">
                    <span class="probe-dot" data-status="{$c['status']}"></span>{$c['label']}
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-1.5">
                <span class="text-ink text-2xl font-semibold tracking-tight tabular-nums">{if $c['latency_ms'] !== null}{$c['latency_ms']|string_format:"%d"}{else}—{/if}</span>
                <span class="text-faint text-xs">{if $c['latency_ms'] !== null}ms 中位延迟{else}暂无有效数据{/if}</span>
            </div>
            <div class="probe-split mt-3" role="img"
                 aria-label="{$c['name']}：正常 {$c['counts']['green']}、波动 {$c['counts']['yellow']}、中断 {$c['counts']['red']}、无数据 {$c['counts']['gray']}（按节点计）">
                {foreach ['green', 'yellow', 'red', 'gray'] as $key}{if $c['counts'][$key]}<span data-status="{$key}" style="flex: {$c['counts'][$key]}"></span>{/if}{/foreach}
            </div>
            <p class="text-faint mt-2 text-xs">{$c['targets']} 个目标 · {$c['counts']['green']} / {$overview['reporting']} 个节点正常</p>
        </div>
    {/foreach}

    <div class="c-card p-4">
        <div class="flex items-center justify-between gap-2">
            <span class="text-ink text-sm font-semibold">检测覆盖</span>
            <span class="text-faint text-xs">周期 {$interval_seconds} 秒</span>
        </div>
        <div class="mt-3 flex items-baseline gap-1.5">
            <span class="text-ink text-2xl font-semibold tracking-tight tabular-nums">{$node_enabled}</span>
            <span class="text-faint text-xs">/ {count($nodes)} 个节点已开启</span>
        </div>
        <div class="meter mt-3.5"><span style="width: {$node_percent|string_format:"%.1f"}%"></span></div>
        <p class="text-faint mt-2 text-xs">{count($targets)} 个测试目标 · {foreach $overview['carriers'] as $c}{$c['code']} {$c['targets']}{if !$c@last} · {/if}{/foreach}</p>
    </div>
</div>

<div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
<div class="flex flex-col gap-6">

{* ============ 测试目标 ============ *}
<section class="c-card-pad" x-data="probeTargets()">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-base font-semibold">三网测试目标</h3>
            <p class="text-faint mt-1 text-xs">{count($targets)} 个目标{if $managed} · {$managed} 个由 TaierSpeedtest 自动维护{/if}</p>
        </div>
        <button type="button" class="btn-primary btn-sm" @click="create()"><i class="ti ti-plus" aria-hidden="true"></i> 新建目标</button>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <div class="bg-tile inline-flex gap-0.5 rounded-full p-1" role="group" aria-label="按运营商筛选">
            <button type="button" class="probe-seg" @click="carrier = 'all'"
                    :class="carrier === 'all' ? 'is-on' : ''" :aria-pressed="carrier === 'all'">全部 <span class="tabular-nums opacity-60" x-text="count('all')"></span></button>
            {foreach $carrier_codes as $key => $code}
                <button type="button" class="probe-seg" @click="carrier = '{$key}'"
                        :class="carrier === '{$key}' ? 'is-on' : ''" :aria-pressed="carrier === '{$key}'">{$carriers[$key]} <span class="tabular-nums opacity-60" x-text="count('{$key}')"></span></button>
            {/foreach}
        </div>
        <label class="relative flex flex-1 items-center sm:max-w-xs">
            <span class="sr-only">搜索测试目标</span>
            <i class="ti ti-search text-faint absolute left-3 text-base" aria-hidden="true"></i>
            <input type="search" class="field-input pl-9" placeholder="搜索名称或 IP" x-model="q">
        </label>
    </div>

    <div class="border-hairline text-faint mt-4 flex items-center gap-3 border-b pb-2 text-xs">
        <span class="min-w-0 flex-1">目标</span>
        <span class="hidden w-44 shrink-0 md:block">地址</span>
        <span class="w-36 shrink-0">最近一轮</span>
        <span class="w-24 shrink-0 text-right">操作</span>
    </div>

    <div class="max-h-[22rem] overflow-y-auto overscroll-contain">
        {foreach $targets as $target}
            <div class="border-hairline flex items-center gap-3 border-b py-2" x-show="match($el)"
                 data-probe-target data-carrier="{$target['carrier']}"
                 data-search="{$target['label']|lower|escape} {$target['ip']|escape}:{$target['port']}">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="probe-code">{$carrier_codes[$target['carrier']]}</span>
                        <span class="text-ink truncate text-sm font-semibold">{$target['label']|escape}</span>
                    </div>
                    <p class="text-faint mt-0.5 truncate text-xs">
                        {if $target['managed']}TaierSpeedtest 同步{else}手动添加{/if}<span class="md:hidden"> · {$target['ip']|escape}:{$target['port']}</span>
                    </p>
                </div>
                <div class="text-body hidden w-44 shrink-0 truncate text-sm tabular-nums md:block">{$target['ip']|escape}:{$target['port']}</div>
                <div class="w-36 shrink-0">
                    <span class="probe-pill" data-status="{$target['status']}">
                        <span class="probe-dot" data-status="{$target['status']}"></span>{$target['status_label']}
                    </span>
                    <p class="text-faint mt-0.5 text-xs tabular-nums">{if $target['latency_ms'] !== null}{$target['latency_ms']|string_format:"%d"} ms · {/if}{$target['reach']} 节点</p>
                </div>
                <div class="flex w-24 shrink-0 justify-end">
                    {if $target['managed'] && $taier['enabled']}
                        <button type="button" class="probe-icon-btn" disabled
                                title="由 TaierSpeedtest 自动维护，关闭自动同步后可编辑或删除"
                                aria-label="{$target['label']|escape} 由 TaierSpeedtest 自动维护，关闭自动同步后可编辑或删除"><i class="ti ti-lock" aria-hidden="true"></i></button>
                    {else}
                        <button type="button" class="probe-icon-btn" @click="edit($el)" aria-label="编辑 {$target['label']|escape}"
                                data-id="{$target['id']}" data-carrier="{$target['carrier']}" data-label="{$target['label']|escape}"
                                data-ip="{$target['ip']|escape}" data-port="{$target['port']}"><i class="ti ti-pencil" aria-hidden="true"></i></button>
                        <button type="button" class="probe-icon-btn" aria-label="删除 {$target['label']|escape}"
                                hx-delete="/admin/node/probe/targets/{$target['id']}" hx-params="none"
                                hx-confirm="确定删除「{$target['label']|escape}」？"
                                hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}'><i class="ti ti-trash" aria-hidden="true"></i></button>
                    {/if}
                </div>
            </div>
        {foreachelse}
            <div class="c-ghost-card my-2" role="group" aria-label="尚未配置测试目标">
                <i class="ti ti-plus text-2xl" aria-hidden="true"></i>
                <p class="text-ink text-sm font-semibold">尚未配置测试目标</p>
                <p class="text-faint px-6 text-center text-xs leading-relaxed">添加第一个目标后，已开启的节点会在下一轮开始检测</p>
                <button type="button" class="btn-primary btn-sm" @click="create()">新建目标</button>
            </div>
        {/foreach}
    </div>

    {if $targets}
        <p class="border-hairline text-faint mt-3 border-t pt-3 text-xs">共 <span class="tabular-nums" x-text="visible"></span> 个目标，列表内滚动查看</p>
    {/if}

    {* ---- 新建 / 编辑抽屉 ---- *}
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="probe-drawer-title">
            <div class="absolute inset-0 bg-black/40" x-show="open" x-transition.opacity.duration.250ms @click="open = false"></div>
            <div class="bg-card border-hairline relative flex h-full w-full max-w-md flex-col border-l shadow-xl"
                 x-show="open" @keydown.escape.window="open = false"
                 x-transition:enter="transition duration-300 ease-drawer" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition duration-200 ease-drawer" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">
                <form class="flex h-full flex-col" hx-post="/admin/node/probe/targets" hx-swap="none"
                      hx-disabled-elt="find button[type=submit]" hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}'>
                    <div class="border-hairline flex items-start justify-between gap-3 border-b p-5">
                        <div>
                            <h3 id="probe-drawer-title" class="text-base font-semibold" x-text="form.id ? '编辑测试目标' : '新建测试目标'">新建测试目标</h3>
                            <p class="text-faint mt-1 text-xs">保存后在下一轮检测生效</p>
                        </div>
                        <button type="button" class="probe-icon-btn -mt-1 -mr-2" aria-label="关闭" @click="open = false"><i class="ti ti-x" aria-hidden="true"></i></button>
                    </div>
                    <div class="flex-1 overflow-y-auto p-5">
                        <input type="hidden" name="id" x-model="form.id">
                        {include file='admin/node/probe_target_fields.tpl'}
                    </div>
                    <div class="border-hairline flex gap-2 border-t p-5">
                        <button type="button" class="btn-secondary flex-1" @click="open = false">取消</button>
                        <button type="submit" class="btn-primary flex-[2]" x-text="form.id ? '保存修改' : '创建目标'">创建目标</button>
                    </div>
                </form>
            </div>
        </div>
    </template>
</section>

{* ============ 节点开关与阈值 ============ *}
<section class="c-card-pad" x-data="probeNodes()">
    <form hx-post="/admin/node/probe/nodes" hx-swap="none" hx-disabled-elt="find button[type=submit]"
          hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}' @change="recount()" @input="recount()">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold">节点开关与阈值</h3>
                <p class="text-faint mt-1 text-xs">{$node_enabled} / {count($nodes)} 个节点已开启 · 需要升级到支持节点检测的 XrayR，复用现有 SSPanel API 配置</p>
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn-secondary btn-sm" @click="setAll(true)">全部开启</button>
                <button type="button" class="btn-secondary btn-sm" @click="setAll(false)">全部关闭</button>
            </div>
        </div>

        <label class="relative mt-4 flex items-center sm:max-w-xs">
            <span class="sr-only">搜索节点</span>
            <i class="ti ti-search text-faint absolute left-3 text-base" aria-hidden="true"></i>
            <input type="search" class="field-input pl-9" placeholder="搜索节点名称或 ID" x-model="q">
        </label>

        <div class="border-hairline text-faint mt-4 flex items-center gap-3 border-b pb-2 text-xs">
            <span class="min-w-0 flex-1">节点</span>
            <span class="hidden w-32 shrink-0 sm:block">最近三网</span>
            <span class="hidden w-20 shrink-0 lg:block">最近上报</span>
            <span class="w-28 shrink-0">黄色阈值</span>
            <span class="w-14 shrink-0 text-right">检测</span>
        </div>

        <div class="max-h-[19rem] overflow-y-auto overscroll-contain">
            {foreach $nodes as $node}
                <div class="border-hairline flex items-center gap-3 border-b py-1" x-show="match($el)"
                     data-probe-node data-search="#{$node['id']} {$node['name']|lower|escape}">
                    <div class="flex min-w-0 flex-1 items-baseline gap-2">
                        <span class="text-faint text-xs tabular-nums">#{$node['id']}</span>
                        <span class="text-ink truncate text-sm">{$node['name']|escape}</span>
                    </div>
                    <div class="hidden w-32 shrink-0 items-center gap-2.5 sm:flex">
                        {foreach $carrier_codes as $key => $code}
                            <span class="text-faint inline-flex items-center gap-1 text-xs"
                                  title="{$carriers[$key]}：{$node['state_labels'][$key]|default:'暂无有效数据'}">
                                <span class="probe-dot" data-status="{$node['states'][$key]|default:'gray'}"></span>{$code}
                            </span>
                        {/foreach}
                    </div>
                    <div class="text-faint hidden w-20 shrink-0 text-xs tabular-nums lg:block">{$node['reported']}</div>
                    <div class="flex w-28 shrink-0 items-center gap-1.5">
                        <input class="field-input !w-20 !px-2.5 !py-1.5 tabular-nums" type="number" min="1" max="3000" required
                               name="threshold_ms[{$node['id']}]" value="{$node['threshold_ms']}" data-initial="{$node['threshold_ms']}"
                               aria-label="{$node['name']|escape} 黄色延迟阈值，毫秒">
                        <span class="text-faint text-xs">ms</span>
                    </div>
                    <div class="flex w-14 shrink-0 justify-end">
                        <input type="hidden" name="enabled[{$node['id']}]" value="0">
                        <label class="probe-switch">
                            <span class="sr-only">{$node['name']|escape} 检测开关</span>
                            <input type="checkbox" name="enabled[{$node['id']}]" value="1"
                                   data-initial="{if $node['enabled']}1{else}0{/if}" {if $node['enabled']}checked{/if}>
                            <span class="probe-switch-track"><span class="probe-switch-thumb"></span></span>
                        </label>
                    </div>
                </div>
            {foreachelse}
                <p class="text-faint py-6 text-center text-sm">请先创建节点</p>
            {/foreach}
        </div>

        {if $nodes}
            <div class="border-hairline mt-3 flex items-center justify-between gap-3 border-t pt-3">
                <p class="text-faint text-xs" x-show="!changed">共 {count($nodes)} 个节点</p>
                <p class="text-ink inline-flex items-center gap-2 text-sm" x-show="changed" x-cloak>
                    <span class="probe-dot" data-status="yellow"></span><span x-text="changed + ' 项未保存的更改'"></span>
                </p>
                <div class="flex gap-2">
                    <button type="button" class="btn-secondary btn-sm" x-show="changed" x-cloak @click="undo()">撤销</button>
                    <button type="submit" class="btn-primary btn-sm" :disabled="!changed">保存更改</button>
                </div>
            </div>
        {/if}
    </form>
</section>

</div>
<div class="flex flex-col gap-6">

{* ============ TaierSpeedtest 自动同步 ============ *}
{if $taier_installed}
<section class="c-card-pad" x-data="{ enabled: {if $taier['enabled']}true{else}false{/if} }">
    <form hx-post="/admin/node/probe/source" hx-swap="none" hx-disabled-elt="find button[type=submit]"
          hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}'>
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-base font-semibold">TaierSpeedtest 目标自动同步</h3>
            <input type="hidden" name="enabled" value="0">
            <label class="probe-switch shrink-0">
                <span class="sr-only">TaierSpeedtest 目标自动同步</span>
                <input type="checkbox" name="enabled" value="1" x-model="enabled">
                <span class="probe-switch-track"><span class="probe-switch-thumb"></span></span>
            </label>
        </div>
        <p class="text-faint mt-1 text-xs leading-relaxed">每个所选地区同步 CT、CM、CU 各一个目标，自动更新 IP 和端口。接口失败时保留旧配置；与手动目标重复的地址会跳过。</p>

        <fieldset class="mt-5">
            <legend class="field-label">同步地区</legend>
            <div class="flex flex-wrap gap-2">
                {foreach $taier_cities as $city}
                    <label class="probe-chip">
                        <input type="checkbox" name="cities[]" value="{$city}" {if in_array($city, $taier['cities'])}checked{/if}>
                        {$city}
                    </label>
                {/foreach}
            </div>
        </fieldset>

        <fieldset class="mt-5">
            <legend class="field-label">更新间隔</legend>
            <div class="bg-tile flex gap-0.5 rounded-full p-1">
                {foreach [6, 12, 24] as $hours}
                    <label class="probe-radio">
                        <input type="radio" name="interval_hours" value="{$hours}" {if $taier['interval_hours'] == $hours}checked{/if}>
                        {$hours} 小时
                    </label>
                {/foreach}
            </div>
        </fieldset>

        <div class="border-hairline mt-5 border-t pt-4">
            <p class="text-ink flex items-start gap-2 text-sm" role="status">
                <span class="probe-dot mt-1.5" data-status="{$taier_status}"></span>{$taier['last_message']|escape}
            </p>
            <p class="text-faint mt-1.5 pl-4 text-xs leading-relaxed">
                上次成功：{if $taier['last_success']}{$taier['last_success']|date_format:'%Y-%m-%d %H:%M:%S'}{else}尚未同步{/if}
                · {if !$taier['enabled']}自动同步已关闭{elseif !$taier['next_sync_at']}等待下一次定时任务{else}下次 {$taier['next_sync_at']|date_format:'%m-%d %H:%M'}{/if}
            </p>
        </div>

        <button type="submit" class="btn-primary mt-4 w-full">保存同步设置</button>
    </form>

    <form class="mt-2" hx-post="/admin/node/probe/source/sync" hx-swap="none" hx-disabled-elt="find button"
          hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}'>
        <button type="submit" class="btn-secondary w-full" :disabled="!enabled"><i class="ti ti-refresh" aria-hidden="true"></i> 立即同步</button>
        <span class="htmx-indicator text-faint mt-2 block text-center text-xs" role="status">正在获取目标…</span>
    </form>
</section>
{else}
<section class="c-card-pad">
    <h3 class="text-base font-semibold">TaierSpeedtest 目标自动同步</h3>
    <p class="text-warning mt-2 text-sm">启用前请先运行 <code>php xcat Migration latest</code>。</p>
</section>
{/if}

{* ============ 检测参数 ============ *}
<section class="c-card-pad">
    <h3 class="text-base font-semibold">检测参数</h3>
    <div class="mt-2">
        <div class="kv-row"><span class="kv-key">检测周期</span><span class="kv-val tabular-nums">{$interval_seconds} 秒 <span class="text-faint font-normal">自动</span></span></div>
        <div class="kv-row"><span class="kv-key">每轮次数</span><span class="kv-val tabular-nums">每目标 3 次</span></div>
        <div class="kv-row"><span class="kv-key">单次超时</span><span class="kv-val tabular-nums">3 秒</span></div>
        <div class="kv-row"><span class="kv-key">历史粒度</span><span class="kv-val tabular-nums">15 分钟 / 格</span></div>
    </div>
    <p class="border-hairline text-faint mt-3 border-t pt-3 text-xs leading-relaxed">目标较多时周期自动延长，避免占满节点出口。TCP 建连耗时包含往返路径，不代表下载速度。</p>
</section>

<section class="bg-tile rounded-(--radius-card) p-5">
    <p class="text-body flex items-start gap-2.5 text-xs leading-relaxed">
        <i class="ti ti-info-circle text-faint mt-0.5 shrink-0 text-sm" aria-hidden="true"></i>
        <span>自动同步依赖面板每五分钟运行的 Cron。查询会把面板服务器的公网 IP 与所选地区发送至 TaierSpeedtest 接口。</span>
    </p>
</section>

</div>
</div>
{/if}

{literal}
<script>
    // 目标表:运营商分段 + 搜索在前端过滤已渲染的行;新建/编辑共用一个抽屉
    function probeTargets() {
        return {
            carrier: 'all',
            q: '',
            open: false,
            form: { id: '', carrier: 'telecom', label: '', ip: '', port: 443 },
            rows: [],
            init() {
                this.rows = Array.from(this.$root.querySelectorAll('[data-probe-target]'));
            },
            match(el) {
                return (this.carrier === 'all' || this.carrier === el.dataset.carrier)
                    && el.dataset.search.includes(this.q.trim().toLowerCase());
            },
            count(key) {
                return key === 'all' ? this.rows.length : this.rows.filter(el => el.dataset.carrier === key).length;
            },
            get visible() {
                return this.rows.filter(el => this.match(el)).length;
            },
            create() {
                this.form = { id: '', carrier: 'telecom', label: '', ip: '', port: 443 };
                this.open = true;
            },
            edit(el) {
                this.form = {
                    id: el.dataset.id, carrier: el.dataset.carrier,
                    label: el.dataset.label, ip: el.dataset.ip, port: el.dataset.port,
                };
                this.open = true;
            },
        };
    }

    // 节点表:整表一次提交,未保存的行数按 data-initial 逐个比对
    function probeNodes() {
        return {
            q: '',
            changed: 0,
            fields() {
                return Array.from(this.$root.querySelectorAll('[data-initial]'));
            },
            match(el) {
                return el.dataset.search.includes(this.q.trim().toLowerCase());
            },
            recount() {
                this.changed = this.fields().filter(
                    el => (el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value) !== el.dataset.initial
                ).length;
            },
            setAll(on) {
                this.fields().filter(el => el.type === 'checkbox' && this.match(el.closest('[data-probe-node]')))
                    .forEach(el => { el.checked = on; });
                this.recount();
            },
            undo() {
                this.fields().forEach(el => {
                    if (el.type === 'checkbox') { el.checked = el.dataset.initial === '1'; } else { el.value = el.dataset.initial; }
                });
                this.changed = 0;
            },
        };
    }
</script>
{/literal}

{include file='shell/admin_footer.tpl'}
