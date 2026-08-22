{include file='shell/admin_header.tpl' nav='nodes'}

<script src="//{$config['jsdelivr_url']}/npm/jsoneditor@10/dist/jsoneditor.min.js"></script>
<link href="//{$config['jsdelivr_url']}/npm/jsoneditor@10/dist/jsoneditor.min.css" rel="stylesheet"/>

<a href="/admin/node" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回节点列表
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">节点 #{$node->id}</h2>
        <p class="text-faint mt-1 text-sm">{$node->name}</p>
    </div>
    <button id="save-node" class="btn-primary btn-sm"
            hx-put="/admin/node/{$node->id}" hx-swap="none"
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

<div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-2">

    {* ============ 基础信息 ============ *}
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">基础信息</h3>
        <div class="mb-3">
            <label class="field-label" for="name">名称</label>
            <input id="name" type="text" class="field-input" value="{$node->name}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="server">连接地址</label>
            <input id="server" type="text" class="field-input" value="{$node->server}">
        </div>
        <div class="mb-3 grid grid-cols-2 gap-3">
            <div class="stat-tile !text-left">
                <div class="stat-label !mt-0">IPv4</div>
                <div class="stat-value truncate font-mono !text-xs">{$node->ipv4|default:'—'}</div>
            </div>
            <div class="stat-tile !text-left">
                <div class="stat-label !mt-0">IPv6</div>
                <div class="stat-value truncate font-mono !text-xs">{$node->ipv6|default:'—'}</div>
            </div>
        </div>
        <div class="mb-3">
            <label class="field-label" for="traffic_rate">流量倍率</label>
            <input id="traffic_rate" type="text" class="field-input" value="{$node->traffic_rate}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="sort">接入类型</label>
            <select id="sort" class="field-input">
                <option value="15" {if $node->sort === 15}selected{/if}>Hysteria2</option>
                <option value="14" {if $node->sort === 14}selected{/if}>Trojan</option>
                <option value="12" {if $node->sort === 12}selected{/if}>VLESS</option>
                <option value="11" {if $node->sort === 11}selected{/if}>Vmess</option>
                <option value="2" {if $node->sort === 2}selected{/if}>TUIC</option>
                <option value="1" {if $node->sort === 1}selected{/if}>Shadowsocks2022</option>
                <option value="0" {if $node->sort === 0}selected{/if}>Shadowsocks</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="field-label">自定义配置</label>
            <div id="custom_config" class="h-64"></div>
            <p class="text-faint mt-1.5 text-xs">
                请参考 <a href="https://docs.sspanel.io/docs/configuration/nodes" target="_blank" class="text-primary">节点自定义配置文档</a> 修改节点自定义配置
            </p>
        </div>
        <label class="mb-4 flex cursor-pointer items-center justify-between gap-3">
            <span class="text-body text-sm font-medium">显示此节点</span>
            <input id="type" type="checkbox" class="accent-primary size-4" {if $node->type}checked{/if}>
        </label>

        <div class="border-hairline border-t pt-4">
            <label class="mb-3 flex cursor-pointer items-center justify-between gap-3">
                <span class="text-body text-sm font-medium">启用动态流量倍率</span>
                <input id="is_dynamic_rate" type="checkbox" class="accent-primary size-4" {if $node->is_dynamic_rate}checked{/if}>
            </label>
            <div class="mb-3">
                <label class="field-label" for="dynamic_rate_type">动态倍率计算方式</label>
                <select id="dynamic_rate_type" class="field-input">
                    <option value="0" {if $node->dynamic_rate_type === 0}selected{/if}>Logistic</option>
                    <option value="1" {if $node->dynamic_rate_type === 1}selected{/if}>Linear</option>
                </select>
            </div>
            <div class="mb-3 grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="max_rate">最大倍率</label>
                    <input id="max_rate" type="text" class="field-input" value="{$node->max_rate}">
                </div>
                <div>
                    <label class="field-label" for="max_rate_time">最大倍率时间（时）</label>
                    <input id="max_rate_time" type="text" class="field-input" value="{$node->max_rate_time}">
                </div>
                <div>
                    <label class="field-label" for="min_rate">最小倍率</label>
                    <input id="min_rate" type="text" class="field-input" value="{$node->min_rate}">
                </div>
                <div>
                    <label class="field-label" for="min_rate_time">最小倍率时间（时）</label>
                    <input id="min_rate_time" type="text" class="field-input" value="{$node->min_rate_time}">
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
                <label class="field-label" for="node_class">等级</label>
                <input id="node_class" type="text" class="field-input" value="{$node->node_class}">
            </div>
            <div>
                <label class="field-label" for="node_group">组别</label>
                <input id="node_group" type="text" class="field-input" value="{$node->node_group}">
            </div>
        </div>

        <div class="border-hairline mt-4 border-t pt-4">
            <div class="mb-3">
                <label class="field-label">已用流量</label>
                <div class="flex gap-2">
                    <input id="node_bandwidth" type="text" class="field-input flex-1" value="{$node->node_bandwidth}" disabled>
                    <button id="reset-bandwidth" class="btn-danger-soft btn-sm shrink-0">重置</button>
                </div>
            </div>
            <div class="mb-3">
                <label class="field-label" for="node_bandwidth_limit">可用流量 (GB)</label>
                <input id="node_bandwidth_limit" type="text" class="field-input" value="{$node->node_bandwidth_limit}">
            </div>
            <div class="mb-3">
                <label class="field-label" for="bandwidthlimit_resetday">流量重置日</label>
                <input id="bandwidthlimit_resetday" type="text" class="field-input" value="{$node->bandwidthlimit_resetday}">
            </div>
            <div class="mb-3">
                <label class="field-label" for="node_speedlimit">速率限制 (Mbps)</label>
                <input id="node_speedlimit" type="text" class="field-input" value="{$node->node_speedlimit}">
            </div>
        </div>

        <div class="border-hairline mt-4 border-t pt-4">
            <label class="field-label">节点通讯密钥</label>
            <div class="bg-tile spoiler mb-3 truncate rounded-(--radius-tile) px-3.5 py-2.5 font-mono text-xs" id="password">
                {$node->password}
            </div>
            <div class="flex gap-2">
                <button class="btn-primary btn-sm copy" data-clipboard-text="{$node->password}">
                    <i class="ti ti-copy"></i> 复制
                </button>
                <button id="reset-password" class="btn-danger-soft btn-sm">
                    <i class="ti ti-refresh-alert"></i> 重置
                </button>
            </div>
            <p class="text-faint mt-2 text-xs">通讯密钥用于 NodeAPI 鉴权，如需更改请点击重置</p>
        </div>
    </div>
</div>

<script>
    const container = document.getElementById('custom_config');
    const editor = new JSONEditor(container, {ldelim} modes: ['code', 'tree'] {rdelim});
    editor.set({if $node->custom_config}{$node->custom_config}{else}{ldelim}{rdelim}{/if});

    document.getElementById('reset-bandwidth').addEventListener('click', function () {
        if (!confirm('确定重置此节点的已用流量？')) return;
        fetch('/admin/node/{$node->id}/reset_bandwidth', {ldelim} method: 'POST' {rdelim})
            .then(function (r) {ldelim} return r.json(); {rdelim})
            .then(function (data) {ldelim}
                showToast(data.msg, data.ret === 1 ? 'success' : 'danger');
                if (data.ret === 1) setTimeout(function () {ldelim} location.reload(); {rdelim}, 800);
            {rdelim});
    });

    document.getElementById('reset-password').addEventListener('click', function () {
        if (!confirm('确定重置此节点的通讯密钥？节点服务端需同步更新。')) return;
        fetch('/admin/node/{$node->id}/reset_password', {ldelim} method: 'POST' {rdelim})
            .then(function (r) {ldelim} return r.json(); {rdelim})
            .then(function (data) {ldelim}
                showToast(data.msg, data.ret === 1 ? 'success' : 'danger');
                if (data.ret === 1) setTimeout(function () {ldelim} location.reload(); {rdelim}, 800);
            {rdelim});
    });
</script>

{include file='shell/admin_footer.tpl'}
