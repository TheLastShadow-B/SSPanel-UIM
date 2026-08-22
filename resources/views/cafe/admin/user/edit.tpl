{include file='shell/admin_header.tpl' nav='users'}

<a href="/admin/user" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回用户列表
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">用户 #{$edit_user->id}</h2>
        <p class="text-faint mt-1 text-sm">{$edit_user->email}</p>
    </div>
    <button id="save_changes" class="btn-primary btn-sm"
            hx-put="/admin/user/{$edit_user->id}" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}
                is_admin: document.getElementById("is_admin").checked,
                is_shadow_banned: document.getElementById("is_shadow_banned").checked,
                is_banned: document.getElementById("is_banned").checked,
            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

<div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-3">

    {* ============ 账户信息 ============ *}
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">账户信息</h3>
        <div class="mb-3">
            <label class="field-label" for="email">邮箱</label>
            <input id="email" type="email" class="field-input" value="{$edit_user->email}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="user_name">用户名</label>
            <input id="user_name" type="text" class="field-input" value="{$edit_user->user_name}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="pass">账户密码</label>
            <input id="pass" type="text" class="field-input" placeholder="若需为此用户重置密码, 填写此栏">
        </div>
        <div class="mb-3">
            <label class="field-label" for="money">账户余额</label>
            <input id="money" type="number" step="1" class="field-input" value="{$edit_user->money}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="ref_by">邀请人</label>
            <input id="ref_by" type="text" class="field-input" value="{$edit_user->ref_by}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="port">SS 端口</label>
            <input id="port" type="text" class="field-input" value="{$edit_user->port}">
        </div>
        <div class="mb-4">
            <label class="field-label" for="method">SS 加密方式</label>
            <select id="method" class="field-input">
                {foreach $ss_methods as $method}
                    <option value="{$method}" {if $edit_user->method === $method}selected{/if}>{$method}</option>
                {/foreach}
            </select>
        </div>
        <div class="border-hairline border-t pt-3">
            <div class="kv-row">
                <span class="kv-key">注册 IP</span>
                <span class="kv-val font-mono text-xs">{$edit_user->reg_ip}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">注册日期</span>
                <span class="kv-val">{$edit_user->reg_date}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">最后使用</span>
                <span class="kv-val">{$edit_user->last_use_time}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">最后登录</span>
                <span class="kv-val">{$edit_user->last_login_time}</span>
            </div>
        </div>
    </div>

    {* ============ 使用限制 ============ *}
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">使用限制</h3>
        <div class="mb-3">
            <label class="field-label" for="transfer_enable">流量限制</label>
            <input id="transfer_enable" type="text" class="field-input" value="{$edit_user->enableTraffic()}">
        </div>
        <div class="mb-3 grid grid-cols-2 gap-3">
            <div class="stat-tile !text-left">
                <div class="stat-label !mt-0">当期用量</div>
                <div class="stat-value !text-base">{$edit_user->usedTraffic()}</div>
            </div>
            <div class="stat-tile !text-left">
                <div class="stat-label !mt-0">累计用量</div>
                <div class="stat-value !text-base">{$edit_user->totalTraffic()}</div>
            </div>
        </div>
        <div class="mb-3">
            <label class="field-label" for="node_group">节点群组</label>
            <input id="node_group" type="text" class="field-input" value="{$edit_user->node_group}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="class">账户等级</label>
            <input id="class" type="text" class="field-input" value="{$edit_user->class}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="class_expire">等级过期时间</label>
            <input id="class_expire" type="text" class="field-input" value="{$edit_user->class_expire}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="auto_reset_day">免费用户流量重置日</label>
            <input id="auto_reset_day" type="text" class="field-input" value="{$edit_user->auto_reset_day}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="auto_reset_bandwidth">重置的免费流量 (GB)</label>
            <input id="auto_reset_bandwidth" type="text" class="field-input" value="{$edit_user->auto_reset_bandwidth}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="node_speedlimit">速度限制 (Mbps)</label>
            <input id="node_speedlimit" type="text" class="field-input" value="{$edit_user->node_speedlimit}">
        </div>
        <div>
            <label class="field-label" for="node_iplimit">同时连接 IP 限制</label>
            <input id="node_iplimit" type="text" class="field-input" value="{$edit_user->node_iplimit}">
        </div>
    </div>

    {* ============ 其他设置 ============ *}
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">其他设置</h3>
        <div class="mb-4">
            <label class="field-label" for="locale">显示语言</label>
            <select id="locale" class="field-input">
                {foreach $locales as $locale}
                    <option value="{$locale}" {if $edit_user->locale === $locale}selected{/if}>{$locale}</option>
                {/foreach}
            </select>
        </div>

        <label class="mb-3 flex cursor-pointer items-center justify-between gap-3">
            <span class="text-body text-sm font-medium">管理员</span>
            <input id="is_admin" type="checkbox" class="accent-primary size-4" {if $edit_user->is_admin}checked{/if}>
        </label>
        <label class="mb-3 flex cursor-pointer items-center justify-between gap-3">
            <span class="text-body text-sm font-medium">账户异常状态（Shadow Banned）</span>
            <input id="is_shadow_banned" type="checkbox" class="accent-primary size-4" {if $edit_user->is_shadow_banned}checked{/if}>
        </label>
        <label class="mb-4 flex cursor-pointer items-center justify-between gap-3">
            <span class="text-body text-sm font-medium">封禁用户</span>
            <input id="is_banned" type="checkbox" class="accent-primary size-4" {if $edit_user->is_banned}checked{/if}>
        </label>

        <div class="mb-3">
            <label class="field-label" for="banned_reason">手动封禁理由</label>
            <textarea id="banned_reason" class="field-input" rows="3">{$edit_user->banned_reason}</textarea>
        </div>
        <div class="mb-4">
            <label class="field-label" for="remark">账户备注</label>
            <textarea id="remark" class="field-input" rows="3" placeholder="仅管理员可见">{$edit_user->remark}</textarea>
        </div>

        <div class="border-hairline border-t pt-4">
            <label class="field-label">两步认证 (MFA) 设备</label>
            {if $mfa_devices->count() > 0}
                <div class="flex flex-col gap-2.5">
                    {foreach $mfa_devices as $device}
                        <div class="bg-tile flex items-center gap-3 rounded-(--radius-tile) px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <div class="text-ink truncate text-sm font-medium">{$device->name}</div>
                                <div class="text-faint text-xs">类型: {$device->type} · 创建于: {$device->created_at}</div>
                                {if $device->used_at}
                                    <div class="text-faint text-xs">最后使用: {$device->used_at}</div>
                                {/if}
                            </div>
                            <button class="btn-danger-soft btn-sm shrink-0" onclick="deleteMFADevice({$device->id})">
                                <i class="ti ti-trash"></i> 删除
                            </button>
                        </div>
                    {/foreach}
                </div>
            {else}
                <p class="text-faint text-sm">该用户未启用两步认证</p>
            {/if}
        </div>
    </div>
</div>

{literal}
<script>
    function deleteMFADevice(device_id) {
        if (!confirm('确定要删除此 MFA 设备吗？')) return;
        fetch('/admin/user/mfa/' + device_id, { method: 'DELETE' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                showToast(data.msg, data.ret === 1 ? 'success' : 'danger');
                if (data.ret === 1) setTimeout(function () { location.reload(); }, 800);
            });
    }
</script>
{/literal}

{include file='shell/admin_footer.tpl'}
