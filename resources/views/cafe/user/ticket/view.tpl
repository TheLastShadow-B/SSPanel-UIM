{include file='shell/header.tpl' nav='ticket'}

<a href="/user/ticket" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回工单列表
</a>

{* ============ 工单头 ============ *}
<div class="mb-6 flex flex-wrap items-center gap-4">
    {if $ticket->status !== 'closed'}
        <span class="bg-warning-tint text-warning flex size-14 shrink-0 items-center justify-center rounded-full text-2xl">
            <i class="ti ti-clock"></i>
        </span>
    {else}
        <span class="bg-success-tint text-success flex size-14 shrink-0 items-center justify-center rounded-full text-2xl">
            <i class="ti ti-check"></i>
        </span>
    {/if}
    <div class="min-w-0 flex-1">
        <h2 class="truncate text-xl font-semibold tracking-tight">{$ticket->title}</h2>
        <div class="mt-1.5 flex flex-wrap items-center gap-2 text-sm">
            <span class="text-faint">#{$ticket->id}</span>
            {if $ticket->status !== 'closed'}
                <span class="badge-warning">{$ticket->status}</span>
            {else}
                <span class="badge-neutral">{$ticket->status}</span>
            {/if}
            <span class="badge-neutral">{$ticket->type}</span>
            <span class="text-faint">开启于 {$ticket->datetime}</span>
        </div>
    </div>
</div>

{* ============ 对话流 ============ *}
<div class="c-card mb-5">
    <div class="divide-y divide-(--color-hairline)">
        {foreach $comments as $comment}
            <div class="flex gap-4 px-5 py-4">
                <span class="bg-tile text-body flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold">
                    {$comment->commenter_name|truncate:1:''|upper}
                </span>
                <div class="min-w-0 flex-1">
                    <div class="mb-1 flex flex-wrap items-baseline gap-2">
                        <span class="text-ink text-sm font-medium">{$comment->commenter_name}</span>
                        <span class="text-faint text-xs">{$comment->datetime}</span>
                        <span class="text-faint ml-auto text-xs"># {$comment->comment_id + 1}</span>
                    </div>
                    <div class="text-body text-sm leading-relaxed whitespace-pre-wrap">{$comment->comment}</div>
                </div>
            </div>
        {/foreach}
    </div>
</div>

{* ============ 回复框 ============ *}
{if $ticket->status !== 'closed'}
    <div class="c-card-pad">
        <h3 class="mb-3 text-base">添加回复</h3>
        <textarea id="reply-comment" class="field-input mb-4" rows="5" placeholder="请输入回复内容"></textarea>
        <div class="flex justify-end">
            <button id="reply" class="btn-primary btn-sm"
                    hx-post="/user/ticket/{$ticket->id}" hx-swap="none"
                    hx-vals='js:{ comment: document.getElementById("reply-comment").value }'>
                <i class="ti ti-send"></i> 回复
            </button>
        </div>
    </div>
{else}
    <div class="c-card text-faint flex items-center justify-center gap-2 px-5 py-6 text-sm">
        <i class="ti ti-lock"></i>
        工单已关闭，如需继续咨询请开启新工单
    </div>
{/if}

{include file='shell/footer.tpl'}
