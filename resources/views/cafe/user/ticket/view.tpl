{include file='shell/header.tpl' nav='ticket'}

<a href="/user/ticket" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回工单列表
</a>

{* ============ 工单头 ============ *}
<div class="mb-5 flex flex-wrap items-center gap-4">
    {if $ticket->status !== 'closed'}
        <span class="bg-warning-tint text-warning flex size-12 shrink-0 items-center justify-center rounded-full text-xl">
            <i class="ti ti-clock"></i>
        </span>
    {else}
        <span class="bg-success-tint text-success flex size-12 shrink-0 items-center justify-center rounded-full text-xl">
            <i class="ti ti-check"></i>
        </span>
    {/if}
    <div class="min-w-0 flex-1">
        <h2 class="truncate text-xl font-semibold tracking-tight">{$ticket->title}</h2>
        <div class="mt-1 flex flex-wrap items-center gap-2 text-sm">
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

{* ============ 对话 ============ *}
<div class="c-card mb-5">
    <div id="ticket-thread" class="flex max-h-[60vh] flex-col gap-5 overflow-y-auto p-5">
        {foreach $comments as $comment}
            {if $comment->commenter_type === 'user'}
                {* 我(用户)→ 右侧蓝色气泡 *}
                <div class="flex justify-end">
                    <div class="max-w-[85%] sm:max-w-[70%]">
                        <div class="text-faint mb-1 text-right text-xs">{$comment->datetime}</div>
                        <div class="bg-primary ml-auto w-fit rounded-2xl rounded-br-md px-4 py-2.5 text-sm leading-relaxed break-words text-white
                                    [&_.ticket-img]:border-white/30 [&_a]:text-white">
                            {$comment->comment}
                        </div>
                    </div>
                </div>
            {else}
                {* 客服 / AI → 左侧灰色气泡 *}
                <div class="flex justify-start gap-3">
                    <span class="bg-tile text-body flex size-9 shrink-0 items-center justify-center rounded-full text-sm">
                        {if $comment->commenter_type === 'llm'}
                            <i class="ti ti-robot"></i>
                        {else}
                            <i class="ti ti-headset"></i>
                        {/if}
                    </span>
                    <div class="max-w-[85%] sm:max-w-[70%]">
                        <div class="text-faint mb-1 text-xs">{$comment->commenter_name} · {$comment->datetime}</div>
                        <div class="bg-tile text-ink w-fit rounded-2xl rounded-bl-md px-4 py-2.5 text-sm leading-relaxed break-words [&_a]:text-primary">
                            {$comment->comment}
                        </div>
                    </div>
                </div>
            {/if}
        {/foreach}
    </div>

    {* ============ 输入区 ============ *}
    {if $ticket->status !== 'closed'}
        <div class="border-hairline border-t p-4">
            <textarea id="reply-comment" class="field-input mb-3" rows="3"
                      placeholder="输入回复,支持直接粘贴 / 拖入图片…"></textarea>
            <div class="flex items-center justify-between">
                <button id="attach-image" class="btn-secondary btn-sm" type="button">
                    <i class="ti ti-photo"></i> 图片
                </button>
                <input type="file" id="image-file" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
                <button class="btn-primary btn-sm"
                        hx-post="/user/ticket/{$ticket->id}" hx-swap="none"
                        hx-on::after-request="afterTicketReply(event)"
                        hx-vals='js:{ comment: document.getElementById("reply-comment").value }'>
                    <i class="ti ti-send"></i> 发送
                </button>
            </div>
        </div>
    {else}
        <div class="border-hairline text-faint flex items-center justify-center gap-2 border-t px-5 py-5 text-sm">
            <i class="ti ti-lock"></i>
            工单已关闭,如需继续咨询请开启新工单
        </div>
    {/if}
</div>

{include file='shell/ticket_chat.tpl'}
{include file='shell/footer.tpl'}
