{include file='shell/admin_header.tpl' nav='nodes'}
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div><h2 class="text-2xl font-semibold tracking-tight">回国 TCP 检测</h2><p class="text-faint mt-1 text-sm">由节点上的 XrayR 测试，配置保存后自动下发</p></div>
    <a href="/admin/node" class="btn-secondary btn-sm">返回节点</a>
</div>
{if !$installed}
    <div class="c-card-pad text-warning">请先执行数据库迁移：<code>php xcat Migration latest</code></div>
{else}
{if $taier_installed}
<section class="c-card-pad mb-6">
    <h3 class="text-base font-semibold">泰尔目标自动同步</h3>
    <p class="text-body mt-2 text-sm">每个所选地区同步 CT、CM、CU 各一个目标，自动更新 IP 和端口。接口失败时保留旧配置；与手动目标重复的地址会跳过。</p>
    <form class="mt-4 space-y-4" hx-post="/admin/node/probe/source" hx-swap="none" hx-disabled-elt="find button" hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}'>
        <div class="flex flex-wrap gap-4">
            <label class="text-body text-xs">自动同步<select class="field-input mt-1" name="enabled"><option value="0" {if !$taier['enabled']}selected{/if}>关闭</option><option value="1" {if $taier['enabled']}selected{/if}>开启</option></select></label>
            <label class="text-body text-xs">更新间隔<select class="field-input mt-1" name="interval_hours">{foreach [6, 12, 24] as $hours}<option value="{$hours}" {if $taier['interval_hours'] == $hours}selected{/if}>每 {$hours} 小时</option>{/foreach}</select></label>
        </div>
        <fieldset><legend class="text-body mb-2 text-xs">同步地区</legend><div class="flex flex-wrap gap-4">{foreach $taier_cities as $city}<label class="text-body inline-flex items-center gap-2 text-sm"><input type="checkbox" name="cities[]" value="{$city}" {if in_array($city, $taier['cities'])}checked{/if}>{$city}</label>{/foreach}</div></fieldset>
        <button type="submit" class="btn-primary btn-sm">保存同步设置</button>
    </form>
    <div class="border-hairline mt-4 border-t pt-4">
        <p class="text-body text-sm" role="status">{$taier['last_message']|escape}</p>
        <p class="text-faint mt-1 text-xs">上次成功：{if $taier['last_success']}{$taier['last_success']|date_format:'%Y-%m-%d %H:%M:%S'}{else}尚未同步{/if} · {if !$taier['enabled']}自动同步已关闭{elseif !$taier['next_sync_at']}等待下一次定时任务{else}下次同步：{$taier['next_sync_at']|date_format:'%Y-%m-%d %H:%M:%S'}{/if}</p>
        <form class="mt-3" hx-post="/admin/node/probe/source/sync" hx-swap="none" hx-disabled-elt="find button" hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}'>
            <button type="submit" class="btn-secondary btn-sm" {if !$taier['enabled']}disabled{/if}>立即同步</button><span class="htmx-indicator text-faint ml-3 text-xs" role="status">正在获取目标…</span>
        </form>
        <p class="text-faint mt-3 text-xs leading-relaxed">自动更新依赖面板每五分钟运行的 Cron 任务。修改地区后，在下次成功同步时更新目标；关闭同步会保留现有目标。查询会将面板服务器的公网 IP 和所选地区发送至泰尔接口。</p>
    </div>
</section>
{else}
<p class="text-warning mb-6 text-sm">启用泰尔自动同步前，请先运行 <code>php xcat Migration latest</code>。</p>
{/if}
<section class="c-card-pad mb-6" x-data="{ showCreate: false }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-base font-semibold">三网测试目标 · {count($targets)} 个</h3>
        <button type="button" class="btn-primary btn-sm" @click="showCreate = true; $nextTick(() => $refs.newLabel.focus())"><i class="ti ti-plus" aria-hidden="true"></i> 新建测试目标</button>
    </div>
    <p class="text-body mt-2 text-sm">按需添加目标并选择运营商。目标需开放对应 TCP 端口，并确认实际所属运营商。</p>
    <p class="text-faint mt-1 text-xs">当前检测周期：{$interval_seconds} 秒。目标较多时自动延长周期。</p>
    <form x-show="showCreate" x-cloak class="border-hairline mt-5 rounded-xl border p-4" hx-post="/admin/node/probe/targets" hx-swap="none" hx-disabled-elt="find button" hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}'>
        <h4 class="mb-4 text-sm font-semibold">新建测试目标</h4>
        {include file='admin/node/probe_target_fields.tpl' target=['carrier' => 'telecom', 'label' => '', 'ip' => '', 'port' => 443] is_new=true}
        <div class="mt-4 flex gap-2">
            <button type="submit" class="btn-primary btn-sm">创建目标</button>
            <button type="button" class="btn-secondary btn-sm" @click="showCreate = false">取消</button>
        </div>
    </form>
    <div class="mt-5 space-y-3">
        {foreach $targets as $target}
        <details class="border-hairline rounded-xl border p-4">
            <summary class="text-body cursor-pointer text-sm"><span class="font-semibold">{$carriers[$target['carrier']]} · {$target['label']|escape}</span> <span class="text-faint break-all">{$target['ip']|escape}:{$target['port']}</span> <span class="text-faint text-xs">{if ($target['source']|default:'manual') === 'taier'}泰尔同步{else}手动{/if}</span></summary>
            {if ($target['source']|default:'manual') === 'taier' && $taier['enabled']}
            <p class="text-faint mt-3 text-xs">此目标由泰尔自动维护。如需编辑，请先关闭自动同步；修改后会转为手动目标。</p>
            {else}
            <form class="mt-4" hx-post="/admin/node/probe/targets/{$target['id']}" hx-swap="none" hx-disabled-elt="find button" hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}'>
                {include file='admin/node/probe_target_fields.tpl' target=$target is_new=false}
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="submit" class="btn-secondary btn-sm">保存修改</button>
                    <button type="button" class="btn-danger-soft btn-sm" hx-delete="/admin/node/probe/targets/{$target['id']}" hx-params="none" hx-confirm="确定删除这个测试目标？">删除目标</button>
                </div>
            </form>
            {/if}
        </details>
        {foreachelse}
        <p class="text-faint py-5 text-center text-sm">尚未配置测试目标，点击「新建测试目标」开始添加。</p>
        {/foreach}
    </div>
</section>
<section class="c-card-pad">
    <h3 class="text-base font-semibold">节点检测设置</h3>
    <p class="text-body mt-2 text-sm">升级到支持回国检测的 XrayR 后启用。复用现有 SSPanel API 配置，无需单独安装探针。每轮每个目标检测 3 次，单次超时 3 秒。</p>
    <div class="divide-hairline mt-3 divide-y">
    {foreach $nodes as $node}
        <form class="flex flex-wrap items-end gap-3 py-4" hx-post="/admin/node/{$node['id']}/probe" hx-swap="none" hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}'>
            <div class="min-w-40 flex-1 pb-2 text-sm">#{$node['id']} {$node['name']|escape}</div>
            <label class="text-body text-xs">检测开关<select class="field-input mt-1" name="enabled"><option value="0" {if !$node['enabled']}selected{/if}>关闭</option><option value="1" {if $node['enabled']}selected{/if}>开启</option></select></label>
            <label class="text-body text-xs">黄色延迟阈值（ms）<input class="field-input mt-1" name="threshold_ms" value="{$node['threshold_ms']}" type="number" min="1" max="3000" required></label>
            <button type="submit" class="btn-secondary btn-sm">保存</button>
        </form>
    {foreachelse}<p class="text-faint py-5 text-sm">请先创建节点</p>{/foreach}
    </div>
</section>
{/if}
{include file='shell/admin_footer.tpl'}
