{include file='shell/admin_header.tpl' nav='nodes'}

<script src="//{$config['jsdelivr_url']}/npm/jsoneditor@10/dist/jsoneditor.min.js"></script>
<link href="//{$config['jsdelivr_url']}/npm/jsoneditor@10/dist/jsoneditor.min.css" rel="stylesheet"/>

<a href="/admin/node" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回节点列表
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">创建节点</h2>
        <p class="text-faint mt-1 text-sm">创建各类节点</p>
    </div>
    <button id="create-node" class="btn-primary btn-sm"
            hx-post="/admin/node" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {if $key !== 'is_dynamic_rate'}
                {$key}: document.getElementById("{$key}").value,
                {/if}
                {/foreach}
                is_dynamic_rate: document.getElementById("is_dynamic_rate").checked,
                type: document.getElementById("type").checked,
                custom_config: JSON.stringify(editor.get()),
            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

<div class="grid items-start gap-5 lg:grid-cols-2">

    {* ============ 基础信息 ============ *}
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">基础信息</h3>
        <div class="mb-3">
            <label class="field-label" for="name">名称 *</label>
            <input id="name" type="text" class="field-input" value="">
        </div>
        <div class="mb-3">
            <label class="field-label" for="server">连接地址 *</label>
            <input id="server" type="text" class="field-input" value="">
        </div>
        <div class="mb-3">
            <label class="field-label" for="traffic_rate">流量倍率 *</label>
            <input id="traffic_rate" type="text" class="field-input" value="">
        </div>
        <div class="mb-3">
            <label class="field-label" for="sort">接入类型</label>
            <select id="sort" class="field-input">
                <option value="15">Hysteria2</option>
                <option value="14">Trojan</option>
                <option value="11">Vmess</option>
                <option value="2">TUIC</option>
                <option value="1">Shadowsocks2022</option>
                <option value="0">Shadowsocks</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="field-label">自定义配置</label>
            <div id="custom_config" class="h-64 overflow-hidden rounded-xl"></div>
            <p class="text-faint mt-1.5 text-xs">
                请参考 <a href="https://docs.sspanel.io/docs/configuration/nodes" target="_blank" class="text-primary">节点自定义配置文档</a> 修改节点自定义配置
            </p>
        </div>
        <label class="mb-4 flex cursor-pointer items-center justify-between gap-3">
            <span class="text-body text-sm font-medium">显示此节点</span>
            <input id="type" type="checkbox" class="accent-primary size-4" checked>
        </label>

        <div class="border-hairline border-t pt-4">
            <label class="mb-3 flex cursor-pointer items-center justify-between gap-3">
                <span class="text-body text-sm font-medium">启用动态流量倍率</span>
                <input id="is_dynamic_rate" type="checkbox" class="accent-primary size-4" checked>
            </label>
            <div class="mb-3">
                <label class="field-label" for="dynamic_rate_type">动态倍率计算方式</label>
                <select id="dynamic_rate_type" class="field-input">
                    <option value="0">Logistic</option>
                    <option value="1">Linear</option>
                </select>
            </div>
            <div class="mb-3 grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="max_rate">最大倍率</label>
                    <input id="max_rate" type="text" class="field-input" value="">
                </div>
                <div>
                    <label class="field-label" for="max_rate_time">最大倍率时间（时）</label>
                    <input id="max_rate_time" type="text" class="field-input" value="">
                </div>
                <div>
                    <label class="field-label" for="min_rate">最小倍率</label>
                    <input id="min_rate" type="text" class="field-input" value="">
                </div>
                <div>
                    <label class="field-label" for="min_rate_time">最小倍率时间（时）</label>
                    <input id="min_rate_time" type="text" class="field-input" value="">
                </div>
            </div>
            <p class="text-faint text-xs">最大倍率时间必须大于最小倍率时间，否则将不会生效</p>
        </div>
    </div>

    {* ============ 其他信息 ============ *}
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">其他信息</h3>
        <div class="mb-3 grid grid-cols-2 gap-3">
            <div>
                <label class="field-label" for="node_class">等级 *</label>
                <input id="node_class" type="text" class="field-input" value="">
            </div>
            <div>
                <label class="field-label" for="node_group">组别 *</label>
                <input id="node_group" type="text" class="field-input" value="">
            </div>
        </div>
        <div class="border-hairline mt-4 border-t pt-4">
            <div class="mb-3">
                <label class="field-label" for="node_bandwidth_limit">可用流量 (GB) *</label>
                <input id="node_bandwidth_limit" type="text" class="field-input" value="">
            </div>
            <div class="mb-3">
                <label class="field-label" for="bandwidthlimit_resetday">流量重置日 *</label>
                <input id="bandwidthlimit_resetday" type="text" class="field-input" value="">
            </div>
            <div>
                <label class="field-label" for="node_speedlimit">速率限制 (Mbps) *</label>
                <input id="node_speedlimit" type="text" class="field-input" value="">
            </div>
        </div>
    </div>
</div>

{literal}
<script>
    const container = document.getElementById('custom_config');
    const editor = new JSONEditor(container, { modes: ['code', 'tree'] });
</script>
{/literal}

{include file='shell/admin_footer.tpl'}
