{include file='shell/admin_header.tpl' nav='log-payback'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">返利日志</h2>
        <p class="text-faint mt-1 text-sm">邀请返利记录</p>
    </div>
    <span></span>
</div>

<div x-data="cafeTable('/admin/payback/ajax', 'paybacks')" class="c-card">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
        <h3 class="text-base">全部记录</h3>
        <input type="search" x-model="search" @input="page = 1" placeholder="搜索…"
               class="field-input !w-64">
    </div>

    <div class="table-card overflow-x-auto">
        <table>
            <thead>
            <tr>
                <th>事件ID</th>
                <th>原始金额</th>
                <th>发起用户</th>
                <th>发起用户名</th>
                <th>获利用户</th>
                <th>获利用户名</th>
                <th>获利金额</th>
                <th>账单</th>
                <th>时间</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="row in paged" :key="row.id">
                <tr>
                    <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                    <td class="text-ink font-medium" x-text="'¥ ' + row.total"></td>
                    <td x-text="row.userid"></td>
                    <td class="max-w-64 truncate" x-text="row.user_name"></td>
                    <td x-text="row.ref_by"></td>
                    <td class="max-w-64 truncate" x-text="row.ref_user_name"></td>
                    <td class="text-ink font-medium" x-text="'¥ ' + row.ref_get"></td>
                    <td x-text="row.invoice_id"></td>
                    <td class="text-faint text-xs" x-text="row.datetime"></td>
                </tr>
            </template>
            </tbody>
        </table>
        <div x-show="loading" class="text-faint px-5 py-10 text-center text-sm">加载中…</div>
        <div x-show="!loading && paged.length === 0" x-cloak
             class="text-faint flex flex-col items-center gap-2 px-5 py-12 text-sm">
            <i class="ti ti-gift-off text-2xl"></i>
            暂无数据
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

{include file='shell/admin_footer.tpl'}
