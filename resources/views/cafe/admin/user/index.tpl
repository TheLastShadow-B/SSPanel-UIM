{include file='shell/admin_header.tpl' nav='users'}

<div x-data="{ showCreate: false }">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight">用户</h2>
            <p class="text-faint mt-1 text-sm">系统中所有用户的列表</p>
        </div>
        <button class="btn-primary btn-sm" @click="showCreate = true">
            <i class="ti ti-plus"></i> 创建用户
        </button>
    </div>

    <div x-data="cafeTable('/admin/user/ajax', 'users')" class="c-card">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
            <h3 class="text-base">全部用户</h3>
            <input type="search" x-model="search" @input="page = 1" placeholder="搜索邮箱 / 昵称 / ID…"
                   class="field-input !w-64">
        </div>

        <div class="table-card overflow-x-auto">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>用户</th>
                    <th>余额</th>
                    <th>邀请人</th>
                    <th>流量(已用/限制)</th>
                    <th>等级</th>
                    <th>状态</th>
                    <th>等级过期</th>
                    <th class="text-right">操作</th>
                </tr>
                </thead>
                <tbody>
                <template x-for="row in paged" :key="row.id">
                    <tr>
                        <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                        <td>
                            <div class="text-ink max-w-44 truncate font-medium" x-text="row.user_name"></div>
                            <div class="text-faint max-w-44 truncate text-xs" x-text="row.email"></div>
                        </td>
                        <td x-text="'¥ ' + row.money"></td>
                        <td x-text="row.ref_by"></td>
                        <td class="text-xs" x-text="row.transfer_used + ' / ' + row.transfer_enable"></td>
                        <td><span class="value-pill" x-text="'Lv. ' + row.class"></span></td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <template x-if="row.is_admin === '是'"><span class="badge-primary">管理员</span></template>
                                <template x-if="row.is_banned === '是'"><span class="badge-danger">封禁</span></template>
                                <template x-if="row.is_inactive === '是'"><span class="badge-warning">闲置</span></template>
                                <template x-if="row.is_banned === '否' && row.is_inactive === '否'"><span class="badge-success">正常</span></template>
                            </div>
                        </td>
                        <td class="text-faint text-xs" x-text="row.class_expire"></td>
                        <td>
                            <div class="flex justify-end gap-1.5">
                                <a class="btn-secondary btn-sm" :href="'/admin/user/' + row.id + '/edit'">编辑</a>
                                <a class="btn-outline btn-sm" :href="'/admin/user/' + row.id + '/switch'">切换</a>
                                <button class="btn-danger-soft btn-sm"
                                        @click="destroy('/admin/user/' + row.id, '确定删除用户 #' + row.id + '？', row.id)">
                                    删除
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
                </tbody>
            </table>
            <div x-show="loading" class="text-faint px-5 py-10 text-center text-sm">加载中…</div>
            <div x-show="!loading && filtered.length === 0" x-cloak
                 class="text-faint flex flex-col items-center gap-2 px-5 py-12 text-sm">
                <i class="ti ti-users text-2xl"></i>
                暂无用户
            </div>
        </div>

        <div class="border-hairline flex items-center justify-between border-t px-5 py-3" x-show="pageCount > 1" x-cloak>
            <span class="text-faint text-xs" x-text="'共 ' + filtered.length + ' 条'"></span>
            <div class="flex items-center gap-2">
                <button class="btn-secondary btn-sm" @click="prev()" :disabled="page <= 1"><i class="ti ti-chevron-left"></i></button>
                <span class="text-body text-xs" x-text="page + ' / ' + pageCount"></span>
                <button class="btn-secondary btn-sm" @click="next()" :disabled="page >= pageCount"><i class="ti ti-chevron-right"></i></button>
            </div>
        </div>
    </div>

    {* ============ 创建用户模态 ============ *}
    <template x-teleport="body">
        <div x-show="showCreate" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="showCreate = false"></div>
            <div class="c-card modal-pop relative w-full max-w-md p-6 shadow-xl" @keydown.escape.window="showCreate = false">
                <h3 class="mb-4 text-base">添加用户</h3>
                {foreach $details['create_dialog'] as $from}
                    <div class="mb-3">
                        <label class="field-label" for="{$from['id']}">{$from['info']}</label>
                        {if $from['type'] === 'input'}
                            <input id="{$from['id']}" type="text" class="field-input" placeholder="{$from['placeholder']}">
                        {elseif $from['type'] === 'textarea'}
                            <textarea id="{$from['id']}" class="field-input" rows="{$from['rows']}" placeholder="{$from['placeholder']}"></textarea>
                        {elseif $from['type'] === 'select'}
                            <select id="{$from['id']}" class="field-input">
                                {foreach $from['select'] as $key => $value}
                                    <option value="{$key}">{$value}</option>
                                {/foreach}
                            </select>
                        {/if}
                    </div>
                {/foreach}
                <div class="mt-5 flex justify-end gap-2">
                    <button class="btn-secondary btn-sm" @click="showCreate = false">取消</button>
                    <button class="btn-primary btn-sm" @click="showCreate = false"
                            hx-post="/admin/user/create" hx-swap="none"
                            hx-vals='js:{
                                {foreach $details['create_dialog'] as $from}
                                {$from['id']}: document.getElementById("{$from['id']}").value,
                                {/foreach}
                            }'>
                        添加
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>

{include file='shell/admin_footer.tpl'}
