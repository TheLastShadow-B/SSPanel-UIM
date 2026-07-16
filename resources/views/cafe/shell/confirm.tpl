{* hx-confirm 的 cafe 风格确认模态(替代浏览器原生 confirm,cafe 主题共享部件) *}
<div id="cafe-confirm" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="c-card w-full max-w-sm p-6 shadow-xl">
        <h3 class="mb-2 text-base">操作确认</h3>
        <p id="cafe-confirm-msg" class="text-body text-sm leading-relaxed"></p>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" id="cafe-confirm-cancel" class="btn-secondary btn-sm">取消</button>
            <button type="button" id="cafe-confirm-ok" class="btn-primary btn-sm">确认</button>
        </div>
    </div>
</div>

{literal}
<script>
    document.addEventListener('htmx:confirm', function (evt) {
        // htmx 对每个请求都发 confirm 事件;只拦截真正带 hx-confirm 的
        if (!evt.detail.question) return;
        evt.preventDefault();

        const overlay = document.getElementById('cafe-confirm');
        const show = function () { overlay.classList.remove('hidden'); overlay.classList.add('flex'); };
        const hide = function () { overlay.classList.add('hidden'); overlay.classList.remove('flex'); };

        document.getElementById('cafe-confirm-msg').textContent = evt.detail.question;
        // onclick 赋值覆盖旧 handler,不会随弹窗次数累积
        document.getElementById('cafe-confirm-ok').onclick = function () { hide(); evt.detail.issueRequest(true); };
        document.getElementById('cafe-confirm-cancel').onclick = hide;
        overlay.onclick = function (e) { if (e.target === overlay) hide(); };
        show();
    });
</script>
{/literal}
