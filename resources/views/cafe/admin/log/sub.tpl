{include file='shell/admin_header.tpl' nav='log-subscribe'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">订阅日志</h2>
        <p class="text-faint mt-1 text-sm">订阅链接请求记录</p>
    </div>
    <span></span>
</div>

<div x-data="cafeServerTable('/admin/subscribe/ajax', 'subscribes', ['id', 'user_id', 'type', 'request_ip', 'location', 'request_time', 'request_user_agent'])" class="c-card">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
        <h3 class="text-base">全部记录</h3>
        <input type="search" x-model="search" @input="onSearch()" placeholder="搜索…"
               class="field-input !w-64">
    </div>

    <div class="table-card overflow-x-auto">
        <table>
            <thead>
            <tr>
                <th>事件ID</th>
                <th>用户</th>
                <th>订阅类型</th>
                <th>请求IP</th>
                <th>归属地</th>
                <th>请求时间</th>
                <th>客户端标识</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="row in paged" :key="row.id">
                <tr>
                    <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                    <td x-text="row.user_id"></td>
                    <td><span class="badge-neutral" x-text="row.type"></span></td>
                    <td class="max-w-52 truncate font-mono text-xs" x-text="row.request_ip"></td>
                    <td class="max-w-64 truncate" x-text="row.location"></td>
                    <td class="text-faint text-xs" x-text="row.request_time"></td>
                    <td class="max-w-64 truncate" x-text="row.request_user_agent"></td>
                </tr>
            </template>
            </tbody>
        </table>
        <div x-show="loading" class="text-faint px-5 py-10 text-center text-sm">加载中…</div>
        <div x-show="!loading && paged.length === 0" x-cloak
             class="text-faint flex flex-col items-center gap-2 px-5 py-12 text-sm">
            <i class="ti ti-rss text-2xl"></i>
            暂无数据
        </div>
    </div>

    <div class="border-hairline flex items-center justify-between border-t px-5 py-3" x-show="pageCount > 1" x-cloak>
        <span class="text-faint text-xs" x-text="'共 ' + filtered + ' 条'"></span>
        <div class="flex items-center gap-2">
            <button class="btn-secondary btn-sm" @click="prev()" :disabled="page <= 1"><i class="ti ti-chevron-left"></i></button>
            <span class="text-body text-xs" x-text="page + ' / ' + pageCount"></span>
            <button class="btn-secondary btn-sm" @click="next()" :disabled="page >= pageCount"><i class="ti ti-chevron-right"></i></button>
        </div>
    </div>
</div>

{include file='shell/admin_footer.tpl'}
