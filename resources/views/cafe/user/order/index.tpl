{include file='shell/header.tpl' nav='order'}

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">订单记录</h2>
    <p class="text-faint mt-1 text-sm">查看并管理账户中的订单</p>
</div>

<div x-data="cafeTable('/user/order/ajax', 'orders')" class="c-card">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
        <h3 class="text-base">全部订单</h3>
        <input type="search" x-model="search" @input="page = 1" placeholder="搜索订单…"
               class="field-input !w-56">
    </div>

    <div class="table-card overflow-x-auto">
        <table>
            <thead>
            <tr>
                <th>订单 ID</th>
                <th>商品</th>
                <th>类型</th>
                <th>金额</th>
                <th>状态</th>
                <th>创建时间</th>
                <th class="text-right">操作</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="row in paged" :key="row.id">
                <tr>
                    <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                    <td class="text-ink" x-text="row.product_name"></td>
                    <td x-text="row.product_type"></td>
                    <td class="text-ink font-medium" x-text="'¥ ' + row.price"></td>
                    <td><span :class="badgeClass(row.status)" x-text="row.status"></span></td>
                    <td class="text-faint" x-text="row.create_time"></td>
                    <td>
                        <div class="flex justify-end gap-1.5">
                            <a class="btn-secondary btn-sm" :href="'/user/order/' + row.id + '/view'">查看</a>
                            <template x-if="row.invoice_id">
                                <a class="btn-primary btn-sm" :href="'/user/invoice/' + row.invoice_id + '/view'">支付</a>
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
            <i class="ti ti-receipt-off text-2xl"></i>
            暂无订单
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

{include file='shell/datatable.tpl'}
{include file='shell/footer.tpl'}
