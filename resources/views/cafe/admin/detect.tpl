{include file='shell/admin_header.tpl' nav='detect'}

<div x-data="{ showCreate: false }">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight">审计规则</h2>
            <p class="text-faint mt-1 text-sm">站点审计规则管理</p>
        </div>
        <button class="btn-primary btn-sm" @click="showCreate = true">
            <i class="ti ti-plus"></i> 添加规则
        </button>
    </div>

    <div x-data="cafeTable('/admin/detect/ajax', 'rules')" class="c-card">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
            <h3 class="text-base">全部规则</h3>
            <input type="search" x-model="search" @input="page = 1" placeholder="搜索规则…" class="field-input !w-64">
        </div>

        <div class="table-card overflow-x-auto">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>规则名称</th>
                    <th>规则介绍</th>
                    <th>正则表达式</th>
                    <th>类型</th>
                    <th class="text-right">操作</th>
                </tr>
                </thead>
                <tbody>
                <template x-for="row in paged" :key="row.id">
                    <tr>
                        <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                        <td class="text-ink font-medium" x-text="row.name"></td>
                        <td class="max-w-52 truncate" x-text="row.text"></td>
                        <td><code class="bg-tile rounded px-1.5 py-0.5 font-mono text-xs" x-text="row.regex"></code></td>
                        <td><span class="badge-neutral" x-text="row.type"></span></td>
                        <td>
                            <div class="flex justify-end">
                                <button class="btn-danger-soft btn-sm"
                                        @click="destroy('/admin/detect/' + row.id, '确定删除此规则？', row.id)">
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
                <i class="ti ti-shield-off text-2xl"></i>
                暂无规则
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

    {* ============ 添加规则模态 ============ *}
    <template x-teleport="body">
        <div x-show="showCreate" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="showCreate = false"></div>
            <div class="c-card modal-pop relative w-full max-w-md p-6 shadow-xl" @keydown.escape.window="showCreate = false">
                <h3 class="mb-4 text-base">添加审计规则</h3>
                {foreach $details['add_dialog'] as $from}
                    <div class="mb-3">
                        <label class="field-label" for="{$from['id']}">{$from['info']}</label>
                        {if $from['type'] === 'input'}
                            <input id="{$from['id']}" type="text" class="field-input" placeholder="{$from['placeholder']}">
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
                            hx-post="/admin/detect/add" hx-swap="none"
                            hx-vals='js:{
                                {foreach $details['add_dialog'] as $from}
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
