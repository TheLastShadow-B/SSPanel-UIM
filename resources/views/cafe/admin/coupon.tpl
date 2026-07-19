{include file='shell/admin_header.tpl' nav='coupon'}

<div x-data="{ showCreate: false }">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight">优惠码</h2>
            <p class="text-faint mt-1 text-sm">优惠码管理</p>
        </div>
        <button class="btn-primary btn-sm" @click="showCreate = true">
            <i class="ti ti-plus"></i> 创建优惠码
        </button>
    </div>

    <div x-data="cafeTable('/admin/coupon/ajax', 'coupons')" class="c-card">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
            <h3 class="text-base">全部优惠码</h3>
            <input type="search" x-model="search" @input="page = 1" placeholder="搜索…" class="field-input !w-64">
        </div>

        <div class="table-card overflow-x-auto">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>优惠码</th>
                    <th>类型</th>
                    <th>额度</th>
                    <th>可用商品</th>
                    <th>次数(每人/累计/已用)</th>
                    <th>限制</th>
                    <th>过期时间</th>
                    <th class="text-right">操作</th>
                </tr>
                </thead>
                <tbody>
                <template x-for="row in paged" :key="row.id">
                    <tr>
                        <td class="text-ink font-medium" x-text="'#' + row.id"></td>
                        <td class="font-mono text-xs" x-text="row.code"></td>
                        <td><span class="badge-neutral" x-text="row.type"></span></td>
                        <td class="text-ink font-medium" x-text="row.value"></td>
                        <td class="max-w-32 truncate" x-text="row.product_id"></td>
                        <td class="text-xs" x-text="row.use_time + ' / ' + row.total_use_time + ' / ' + row.use_count"></td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <template x-if="row.new_user === '是'"><span class="badge-warning">限新用户</span></template>
                                <template x-if="row.no_balance_pay === '是'"><span class="badge-warning">禁余额支付</span></template>
                                <template x-if="row.disabled === '是'"><span class="badge-danger">已禁用</span></template>
                            </div>
                        </td>
                        <td class="text-faint text-xs" x-text="row.expire_time"></td>
                        <td>
                            <div class="flex justify-end gap-1.5">
                                <button class="btn-outline btn-sm"
                                        @click="action('/admin/coupon/' + row.id + '/disable', '确定禁用此优惠码？')">
                                    禁用
                                </button>
                                <button class="btn-danger-soft btn-sm"
                                        @click="destroy('/admin/coupon/' + row.id, '确定删除此优惠码？', row.id)">
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
                <i class="ti ti-ticket-off text-2xl"></i>
                暂无优惠码
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

    {* ============ 创建优惠码模态 ============ *}
    <template x-teleport="body">
        <div x-show="showCreate" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="showCreate = false"></div>
            <div class="c-card modal-pop relative max-h-[90vh] w-full max-w-md overflow-y-auto p-6 shadow-xl"
                 @keydown.escape.window="showCreate = false">
                <h3 class="mb-4 text-base">创建优惠码</h3>
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
                <div class="mb-5">
                    <label class="field-label" for="expire_time">过期时间（留空为不过期）</label>
                    <input id="expire_time" type="datetime-local" class="field-input">
                </div>
                <div class="flex justify-end gap-2">
                    <button class="btn-secondary btn-sm" @click="showCreate = false">取消</button>
                    <button class="btn-primary btn-sm" @click="showCreate = false"
                            hx-post="/admin/coupon" hx-swap="none"
                            hx-vals='js:{
                                {foreach $details['create_dialog'] as $from}
                                {$from['id']}: document.getElementById("{$from['id']}").value,
                                {/foreach}
                                expire_time: document.getElementById("expire_time").value === "" ? "" : String(Math.floor(new Date(document.getElementById("expire_time").value).getTime() / 1000)),
                            }'>
                        创建
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>

{include file='shell/admin_footer.tpl'}
