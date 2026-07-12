{include file='shell/admin_header.tpl' nav='product'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">商品</h2>
        <p class="text-faint mt-1 text-sm">系统中所有商品的列表</p>
    </div>
    <a href="/admin/product/create" class="btn-primary btn-sm">
        <i class="ti ti-plus"></i> 创建商品
    </a>
</div>

<div x-data="cafeTable('/admin/product/ajax', 'products')" class="c-card">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
        <h3 class="text-base">全部商品</h3>
        <input type="search" x-model="search" @input="page = 1" placeholder="搜索商品…"
               class="field-input !w-64">
    </div>

    <div class="table-card overflow-x-auto">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>名称</th>
                <th>类型</th>
                <th>售价</th>
                <th>销售状态</th>
                <th>累计销售</th>
                <th>库存</th>
                <th>创建时间</th>
                <th class="text-right">操作</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="row in paged" :key="row.id">
                <tr>
                    <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                    <td class="text-ink max-w-52 truncate font-medium" x-text="row.name"></td>
                    <td><span class="badge-primary" x-text="row.type"></span></td>
                    <td class="text-ink font-medium" x-text="'¥ ' + row.price"></td>
                    <td><span :class="badgeClass(row.status)" x-text="row.status"></span></td>
                    <td x-text="row.sale_count"></td>
                    <td x-text="row.stock"></td>
                    <td class="text-faint text-xs" x-text="row.create_time"></td>
                    <td>
                        <div class="flex justify-end gap-1.5">
                            <a class="btn-secondary btn-sm" :href="'/admin/product/' + row.id + '/edit'">编辑</a>
                            <button class="btn-outline btn-sm"
                                    @click="action('/admin/product/' + row.id + '/copy', '确定复制此商品？')">
                                复制
                            </button>
                            <button class="btn-danger-soft btn-sm"
                                    @click="destroy('/admin/product/' + row.id, '确定删除此商品？', row.id)">
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
            <i class="ti ti-package-off text-2xl"></i>
            暂无商品
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
