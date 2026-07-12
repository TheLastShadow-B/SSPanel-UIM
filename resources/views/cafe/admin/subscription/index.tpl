{include file='shell/admin_header.tpl' nav='subscription'}

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">订阅管理</h2>
    <p class="text-faint mt-1 text-sm">在这里管理用户订阅</p>
</div>

<div x-data="cafeTable('/admin/subscription/ajax', 'subscriptions')" class="c-card">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
        <h3 class="text-base">全部订阅</h3>
        <input type="search" x-model="search" @input="page = 1" placeholder="搜索订阅…"
               class="field-input !w-64">
    </div>

    <div class="table-card overflow-x-auto">
        <table>
            <thead>
            <tr>
                <th>订阅 ID</th>
                <th>用户 ID</th>
                <th>套餐名称</th>
                <th>账单周期</th>
                <th>续费价格</th>
                <th>周期</th>
                <th>状态</th>
                <th class="text-right">操作</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="row in paged" :key="row.id">
                <tr>
                    <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                    <td x-text="'#' + row.user_id"></td>
                    <td class="text-ink max-w-44 truncate font-medium" x-text="row.product_name"></td>
                    <td><span class="value-pill" x-text="row.billing_cycle"></span></td>
                    <td class="text-ink font-medium" x-text="'¥ ' + row.renewal_price"></td>
                    <td class="text-faint text-xs" x-text="row.start_date + ' ~ ' + row.end_date"></td>
                    <td><span :class="badgeClass(row.status)" x-text="row.status"></span></td>
                    <td>
                        <div class="flex justify-end gap-1.5">
                            <a class="btn-secondary btn-sm" :href="'/admin/subscription/' + row.id + '/edit'">编辑</a>
                            <template x-if="row.can_cancel">
                                <button class="btn-danger-soft btn-sm"
                                        @click="action('/admin/subscription/' + row.id + '/cancel', '确定取消此订阅？取消后用户将被降级。')">
                                    取消
                                </button>
                            </template>
                        </div>
                    </td>
                </tr>
            </template>
            </tbody>
        </table>
        <div x-show="loading" class="text-faint px-5 py-10 text-center text-sm">加载中…</div>
        <div x-show="!loading && filtered.length === 0" x-cloak
             class="text-faint flex flex-col items-center gap-2 px-5 py-12 text-sm">
            <i class="ti ti-refresh-off text-2xl"></i>
            暂无订阅
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
