{include file='shell/admin_header.tpl' nav='ticket'}

<a href="/admin/ticket" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回工单列表
</a>

{* ============ 工单头 ============ *}
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
        <h2 class="truncate text-xl font-semibold tracking-tight">{$ticket->title}</h2>
        <div class="mt-1 flex flex-wrap items-center gap-2 text-sm">
            <span class="text-faint">#{$ticket->id}</span>
            {if $ticket->status !== 'closed'}
                <span class="badge-warning">{$ticket->status}</span>
            {else}
                <span class="badge-neutral">已关闭</span>
            {/if}
        </div>
    </div>
    <div class="flex gap-2">
        <button id="llm-reply" class="btn-outline btn-sm">
            <i class="ti ti-robot"></i> LLM 回复
        </button>
        {if $ticket->status !== 'closed'}
            <button id="close-ticket" class="btn-danger-soft btn-sm">
                <i class="ti ti-x"></i> 关闭工单
            </button>
        {/if}
    </div>
</div>

{* ============ 对话(管理端视角:管理员在右,用户/AI 在左)============ *}
<div class="c-card mb-5">
    <div id="ticket-thread" class="flex max-h-[60vh] flex-col gap-5 overflow-y-auto p-5">
        {foreach $comments as $comment}
            {if $comment->commenter_type === 'admin'}
                <div class="flex justify-end">
                    <div class="max-w-[85%] sm:max-w-[70%]">
                        <div class="text-faint mb-1 text-right text-xs">{$comment->commenter_name} · {$comment->datetime}</div>
                        <div class="bg-primary ml-auto w-fit rounded-2xl rounded-br-md px-4 py-2.5 text-sm leading-relaxed break-words text-white
                                    [&_.ticket-img]:border-white/30 [&_a]:text-white">
                            {$comment->comment}
                        </div>
                    </div>
                </div>
            {else}
                <div class="flex justify-start gap-3">
                    <span class="bg-tile text-body flex size-9 shrink-0 items-center justify-center rounded-full text-sm">
                        {if $comment->commenter_type === 'llm'}
                            <i class="ti ti-robot"></i>
                        {else}
                            <i class="ti ti-user"></i>
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
    <div class="border-hairline border-t p-4">
        <textarea id="reply-comment" class="field-input mb-3" rows="3"
                  placeholder="回复用户,支持直接粘贴 / 拖入图片…"></textarea>
        <div class="flex items-center justify-between">
            <button id="attach-image" class="btn-secondary btn-sm" type="button">
                <i class="ti ti-photo"></i> 图片
            </button>
            <input type="file" id="image-file" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
            <button class="btn-primary btn-sm"
                    hx-post="/admin/ticket/{$ticket->id}" hx-swap="none"
                    hx-on::after-request="afterTicketReply(event)"
                    hx-vals='js:{ comment: document.getElementById("reply-comment").value }'>
                <i class="ti ti-send"></i> 发送
            </button>
        </div>
    </div>
</div>

{include file='shell/ticket_chat.tpl'}

<script>
    window.TICKET_ID = {$ticket->id};
</script>
{literal}
<script>
    document.getElementById('llm-reply').addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="ti ti-loader-2"></i> 生成中…';
        fetch('/admin/ticket/' + window.TICKET_ID + '/llm_reply', { method: 'POST' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                showToast(data.msg || 'LLM 回复完成', data.ret === 1 ? 'success' : 'danger');
                if (data.ret === 1) setTimeout(function () { location.reload(); }, 800);
                else { btn.disabled = false; btn.innerHTML = '<i class="ti ti-robot"></i> LLM 回复'; }
            })
            .catch(function () {
                showToast('请求失败', 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="ti ti-robot"></i> LLM 回复';
            });
    });

    const closeBtn = document.getElementById('close-ticket');
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            if (!confirm('确认关闭工单？')) return;
            fetch('/admin/ticket/' + window.TICKET_ID + '/close', { method: 'PUT' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    showToast(data.msg, data.ret === 1 ? 'success' : 'danger');
                    if (data.ret === 1) setTimeout(function () { location.reload(); }, 800);
                });
        });
    }
</script>
{/literal}

{include file='shell/admin_footer.tpl'}
