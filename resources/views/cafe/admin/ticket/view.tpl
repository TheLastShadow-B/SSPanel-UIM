{include file='shell/admin_header.tpl' nav='ticket'}

<a href="/admin/ticket" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回工单列表
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
        <h2 class="truncate text-xl font-semibold tracking-tight">{$ticket->title}</h2>
        <div class="mt-1.5 flex flex-wrap items-center gap-2 text-sm">
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
<div class="c-card-pad">
    <h3 class="mb-3 text-base">回复用户</h3>
    <textarea id="reply-comment" class="field-input mb-4" rows="5" placeholder="请输入回复内容"></textarea>
    <div class="flex justify-end">
        <button class="btn-primary btn-sm"
                hx-post="/admin/ticket/{$ticket->id}" hx-swap="none"
                hx-vals='js:{
                    comment: document.getElementById("reply-comment").value,
                }'>
            <i class="ti ti-send"></i> 回复
        </button>
    </div>
</div>

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
