{include file='shell/admin_header.tpl' nav='ticket'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">工单</h2>
        <p class="text-faint mt-1 text-sm">用户提交的所有工单</p>
    </div>
    <span></span>
</div>

<div x-data="cafeTable('/admin/ticket/ajax', 'tickets')" class="c-card">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
        <h3 class="text-base">全部记录</h3>
        <input type="search" x-model="search" @input="page = 1" placeholder="搜索…"
               class="field-input !w-64">
    </div>

    <div class="table-card overflow-x-auto">
        <table>
            <thead>
            <tr>
                <th>工单ID</th>
                <th>主题</th>
                <th>状态</th>
                <th>类型</th>
                <th>提交用户</th>
                <th>创建时间</th>
                <th class="text-right">操作</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="row in paged" :key="row.id">
                <tr>
                    <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                    <td class="text-ink max-w-52 truncate font-medium" x-text="row.title"></td>
                    <td><span :class="badgeClass(row.status)" x-text="row.status"></span></td>
                    <td><span class="badge-neutral" x-text="row.type"></span></td>
                    <td x-text="row.userid"></td>
                    <td class="text-faint text-xs" x-text="row.datetime"></td>
                    <td><div class="flex justify-end gap-1.5">
                        <a class="btn-secondary btn-sm" :href="'/admin/ticket/' + row.id + '/view'">查看</a>
                        <template x-if="row.can_close"><button class="btn-outline btn-sm" @click="closeTicket(row.id)">关闭</button></template>
                        <button class="btn-danger-soft btn-sm" @click="destroy('/admin/ticket/' + row.id, '确定删除此工单？', row.id)">删除</button>
                    </div></td>
                </tr>
            </template>
            </tbody>
        </table>
        <div x-show="loading" class="text-faint px-5 py-10 text-center text-sm">加载中…</div>
        <div x-show="!loading && paged.length === 0" x-cloak
             class="text-faint flex flex-col items-center gap-2 px-5 py-12 text-sm">
            <i class="ti ti-messages-off text-2xl"></i>
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

{literal}
<script>
    function closeTicket(id) {
        if (!confirm('确定关闭此工单？')) return;
        fetch('/admin/ticket/' + id + '/close', { method: 'PUT' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                showToast(data.msg, data.ret === 1 ? 'success' : 'danger');
                if (data.ret === 1) setTimeout(function () { location.reload(); }, 800);
            });
    }
</script>
{/literal}

{include file='shell/admin_footer.tpl'}
