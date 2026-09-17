{include file='shell/admin_header.tpl' nav='giftcard'}

<div x-data="{ showCreate: false }" @cafe:giftcard-created.window="showCreate = false">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight">礼品卡</h2>
            <p class="text-faint mt-1 text-sm">礼品卡管理</p>
        </div>
        <button class="btn-primary btn-sm" @click="showCreate = true">
            <i class="ti ti-plus"></i> 生成礼品卡
        </button>
    </div>

    <div x-data="cafeTable('/admin/giftcard/ajax', 'giftcards')" class="c-card"
         @cafe:giftcard-created.window="search = ''; page = 1; loading = true; init()">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
            <h3 class="text-base">全部礼品卡</h3>
            <input type="search" x-model="search" @input="page = 1" placeholder="搜索卡号…" class="field-input !w-64">
        </div>

        <div class="table-card overflow-x-auto">
            <table data-column-storage="cafe.admin.giftcard.columns.v1" data-column-widths="100,240,130,160,220,220,100,110" data-column-minimums="80,160,80,110,160,160,100,110" data-fixed-last>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>卡号</th>
                    <th>面值</th>
                    <th>状态</th>
                    <th>创建时间</th>
                    <th>使用时间</th>
                    <th>使用用户</th>
                    <th class="text-right">操作</th>
                </tr>
                </thead>
                <tbody>
                <template x-for="row in paged" :key="row.id">
                    <tr>
                        <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                        <td class="font-mono text-xs" x-text="row.card"></td>
                        <td class="text-ink font-medium" x-text="'¥ ' + row.balance"></td>
                        <td><span :class="badgeClass(row.status)" x-text="row.status"></span></td>
                        <td class="text-faint text-xs" x-text="row.create_time"></td>
                        <td class="text-faint text-xs" x-text="row.use_time"></td>
                        <td x-text="row.use_user"></td>
                        <td>
                            <div class="flex justify-end">
                                <button class="btn-danger-soft btn-sm"
                                        @click="destroy('/admin/giftcard/' + row.id, '确定删除此礼品卡？', row.id)">
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
                <i class="ti ti-gift-off text-2xl"></i>
                暂无礼品卡
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

    {* ============ 生成礼品卡模态 ============ *}
    <template x-teleport="body">
        <div x-show="showCreate" x-cloak {include file='shell/motion_backdrop.tpl'} class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="showCreate = false"></div>
            <div x-show="showCreate" {include file='shell/motion_modal.tpl'} class="c-card t-modal is-open relative w-full max-w-md p-6 shadow-xl" @keydown.escape.window="showCreate = false">
                <h3 class="mb-4 text-base">生成礼品卡</h3>
                {foreach $details['create_dialog'] as $from}
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
                    <button class="btn-primary btn-sm" hx-disabled-elt="this"
                            hx-post="/admin/giftcard" hx-swap="none"
                            hx-on::after-request="afterGiftCardCreate(event)"
                            hx-vals='js:{
                                {foreach $details['create_dialog'] as $from}
                                {$from['id']}: document.getElementById("{$from['id']}").value,
                                {/foreach}
                            }'>
                        生成
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>

{literal}
<script>
    function afterGiftCardCreate(event) {
        if (!event.detail.successful) return;
        try {
            const result = JSON.parse(event.detail.xhr.responseText);
            if (result.ret === 1) {
                // 弹窗被 teleport 到 body，使用窗口事件通知原位置的列表刷新。
                window.dispatchEvent(new Event('cafe:giftcard-created'));
            }
        } catch (e) { /* 非 JSON 响应交给通用 toast 处理。 */ }
    }
</script>
{/literal}

{include file='shell/admin_footer.tpl'}
