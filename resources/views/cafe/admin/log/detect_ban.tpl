{include file='shell/admin_header.tpl' nav='detect-ban'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">审计封禁记录</h2>
        <p class="text-faint mt-1 text-sm">因审计触发的自动封禁</p>
    </div>
    <span></span>
</div>

<div x-data="cafeServerTable('/admin/detect/ban/ajax', 'bans', ["id", "user_id", "detect_number", "ban_time", "start_time", "end_time", "ban_end_time", "all_detect_number"])" class="c-card">
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
                <th>违规次数</th>
                <th>封禁时长(分)</th>
                <th>统计开始</th>
                <th>封禁开始</th>
                <th>封禁结束</th>
                <th>累计违规</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="row in paged" :key="row.id">
                <tr>
                    <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                    <td x-text="row.user_id"></td>
                    <td x-text="row.detect_number"></td>
                    <td x-text="row.ban_time"></td>
                    <td class="text-faint text-xs" x-text="row.start_time"></td>
                    <td class="text-faint text-xs" x-text="row.end_time"></td>
                    <td class="text-faint text-xs" x-text="row.ban_end_time"></td>
                    <td x-text="row.all_detect_number"></td>
                </tr>
            </template>
            </tbody>
        </table>
        <div x-show="loading" class="text-faint px-5 py-10 text-center text-sm">加载中…</div>
        <div x-show="!loading && paged.length === 0" x-cloak
             class="text-faint flex flex-col items-center gap-2 px-5 py-12 text-sm">
            <i class="ti ti-ban text-2xl"></i>
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
