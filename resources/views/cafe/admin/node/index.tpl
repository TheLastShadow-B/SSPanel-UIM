{include file='shell/admin_header.tpl' nav='nodes'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">节点</h2>
        <p class="text-faint mt-1 text-sm">系统中所有节点的列表</p>
    </div>
    <a href="/admin/node/create" class="btn-primary btn-sm">
        <i class="ti ti-plus"></i> 创建节点
    </a>
</div>

<div x-data="cafeTable('/admin/node/ajax', 'nodes')" class="c-card">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
        <h3 class="text-base">全部节点</h3>
        <input type="search" x-model="search" @input="page = 1" placeholder="搜索名称 / 地址…"
               class="field-input !w-64">
    </div>

    <div class="table-card overflow-x-auto">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>名称</th>
                <th>地址</th>
                <th>状态</th>
                <th>在线</th>
                <th>类型</th>
                <th>倍率</th>
                <th>等级 / 组别</th>
                <th>流量 (已用/限制 GB)</th>
                <th>重置日</th>
                <th class="text-right">操作</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="row in paged" :key="row.id">
                <tr>
                    <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                    <td class="text-ink max-w-44 truncate font-medium" x-text="row.name"></td>
                    <td class="max-w-44 truncate font-mono text-xs" x-text="row.server"></td>
                    <td><span class="badge-neutral" x-text="row.type"></span></td>
                    <td>
                        <span :class="row.online_status === 1 ? 'badge-success' : (row.online_status === -1 ? 'badge-danger' : 'badge-warning')"
                              x-text="row.online_status === 1 ? '在线' : (row.online_status === -1 ? '离线' : '无心跳')"></span>
                    </td>
                    <td><span class="badge-primary" x-text="row.sort"></span></td>
                    <td class="whitespace-nowrap">
                        <span x-text="row.traffic_rate + ' 倍'"></span>
                        <template x-if="row.is_dynamic_rate === '是'">
                            <span class="badge-warning" x-text="'动态·' + row.dynamic_rate_type"></span>
                        </template>
                    </td>
                    <td class="text-xs" x-text="'Lv.' + row.node_class + ' / ' + row.node_group"></td>
                    <td class="text-xs" x-text="row.node_bandwidth + ' / ' + row.node_bandwidth_limit"></td>
                    <td x-text="row.bandwidthlimit_resetday"></td>
                    <td>
                        <div class="flex justify-end gap-1.5">
                            <a class="btn-secondary btn-sm" :href="'/admin/node/' + row.id + '/edit'">编辑</a>
                            <button class="btn-outline btn-sm"
                                    @click="action('/admin/node/' + row.id + '/copy', '确定复制此节点？')">
                                复制
                            </button>
                            <button class="btn-danger-soft btn-sm"
                                    @click="destroy('/admin/node/' + row.id, '确定删除此节点？', row.id)">
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
            <i class="ti ti-server-off text-2xl"></i>
            暂无节点
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
