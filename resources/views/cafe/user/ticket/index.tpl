{include file='shell/header.tpl' nav='ticket'}

<div x-data="{ showCreate: false }">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight">工单</h2>
            <p class="text-faint mt-1 text-sm">你可以在这里联系管理员获取支持</p>
        </div>
        <button class="btn-primary btn-sm" @click="showCreate = true">
            <i class="ti ti-plus"></i> 创建工单
        </button>
    </div>

    <div class="c-card">
        {if $tickets !== 0 && count($tickets) > 0}
            <div class="divide-y divide-(--color-hairline)">
                {foreach $tickets as $ticket}
                    <a href="/user/ticket/{$ticket->id}/view"
                       class="hover:bg-tile/60 flex items-center gap-4 px-5 py-4 transition-colors">
                        {if $ticket->status !== 'closed'}
                            <span class="bg-warning-tint text-warning flex size-10 shrink-0 items-center justify-center rounded-full">
                                <i class="ti ti-clock"></i>
                            </span>
                        {else}
                            <span class="bg-success-tint text-success flex size-10 shrink-0 items-center justify-center rounded-full">
                                <i class="ti ti-check"></i>
                            </span>
                        {/if}
                        <div class="min-w-0 flex-1">
                            <div class="text-ink truncate text-sm font-medium">{$ticket->title}</div>
                            <div class="text-faint mt-0.5 text-xs">#{$ticket->id}</div>
                        </div>
                        <span class="badge-neutral shrink-0">{$ticket->type}</span>
                        {if $ticket->status !== 'closed'}
                            <span class="badge-warning shrink-0">{$ticket->status}</span>
                        {else}
                            <span class="badge-neutral shrink-0">{$ticket->status}</span>
                        {/if}
                        <i class="ti ti-chevron-right text-faint shrink-0"></i>
                    </a>
                {/foreach}
            </div>
        {else}
            <div class="text-faint flex flex-col items-center gap-3 py-16 text-sm">
                <i class="ti ti-messages-off text-3xl"></i>
                没有任何工单
                <button class="btn-primary btn-sm" @click="showCreate = true">开启新工单</button>
            </div>
        {/if}
    </div>

    {* ============ 创建工单模态 ============ *}
    <template x-teleport="body">
        <div x-show="showCreate" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="showCreate = false"></div>
            <div class="c-card modal-pop relative w-full max-w-lg p-6 shadow-xl" @keydown.escape.window="showCreate = false">
                <h3 class="mb-4 text-base">创建工单</h3>
                <div class="mb-3">
                    <label class="field-label" for="ticket-type">工单类型</label>
                    <select id="ticket-type" class="field-input">
                        <option value="0">请选择工单类型</option>
                        <option value="howto">使用</option>
                        <option value="billing">财务</option>
                        <option value="account">账户</option>
                        <option value="other">其他</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="field-label" for="ticket-title">主题</label>
                    <input id="ticket-title" type="text" class="field-input" placeholder="请输入工单主题">
                </div>
                <div class="mb-5">
                    <label class="field-label" for="ticket-comment">内容</label>
                    <textarea id="ticket-comment" class="field-input" rows="8" placeholder="请输入工单内容"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button class="btn-secondary btn-sm" @click="showCreate = false">取消</button>
                    <button class="btn-primary btn-sm" @click="showCreate = false"
                            hx-post="/user/ticket" hx-swap="none"
                            hx-vals='js:{
                                title: document.getElementById("ticket-title").value,
                                comment: document.getElementById("ticket-comment").value,
                                type: document.getElementById("ticket-type").value }'>
                        创建
                    </button>
                </div>
            </div>
        </div>
    </template>

</div>

{include file='shell/footer.tpl'}
