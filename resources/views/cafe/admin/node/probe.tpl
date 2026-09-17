{include file='shell/admin_header.tpl' nav='nodes'}
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div><h2 class="text-2xl font-semibold tracking-tight">回国 TCP 检测</h2><p class="text-faint mt-1 text-sm">由节点上的 XrayR 测试，配置保存后自动下发</p></div>
    <a href="/admin/node" class="btn-secondary btn-sm">返回节点</a>
</div>
{if !$installed}
    <div class="c-card-pad text-warning">请先执行数据库迁移：<code>php xcat Migration latest</code></div>
{else}
<form class="c-card-pad mb-6" hx-post="/admin/node/probe/targets" hx-swap="none" hx-headers='{ldelim}"X-CSRF-Token":"{$csrf_token}"{rdelim}'>
    <h3 class="text-base font-semibold">三网测试目标</h3>
    <p class="text-body mt-2 text-sm">每家最多 3 个目标，建议选择不同地区的稳定端点。留空 IP 即停用该目标；目标需开放对应 TCP 端口，并确认实际所属运营商。</p>
    <div class="mt-5 space-y-3">
        {foreach $targets as $target}
        <fieldset class="border-hairline rounded-xl border p-4">
            <legend class="text-body px-2 text-xs">{$target['carrier_name']} · 目标 {$target['position']}</legend>
            <div class="grid gap-3 md:grid-cols-[1fr_1fr_120px]">
                <label class="text-body text-xs">地区 / 名称<input class="field-input mt-1" name="targets[{$target['id']}][label]" value="{$target['label']|escape}" maxlength="80" placeholder="例如：广东电信"></label>
                <label class="text-body text-xs">公网 IPv4<input class="field-input mt-1" name="targets[{$target['id']}][ip]" value="{$target['ip']|escape}" placeholder="未配置" autocomplete="off"></label>
                <label class="text-body text-xs">TCP 端口<input class="field-input mt-1" name="targets[{$target['id']}][port]" value="{$target['port']}" type="number" min="1" max="65535"></label>
            </div>
        </fieldset>
        {/foreach}
    </div>
    <button type="submit" class="btn-primary btn-sm mt-5">保存测试目标</button>
</form>
<section class="c-card-pad">
    <h3 class="text-base font-semibold">节点检测设置</h3>
    <p class="text-body mt-2 text-sm">升级到支持回国检测的 XrayR 后启用。复用现有 SSPanel API 配置，无需单独安装探针。每分钟检测一轮，每个目标 3 次，单次超时 3 秒。</p>
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
