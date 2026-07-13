{* 工单聊天公共 JS:图片上传(按钮/粘贴)+ 回复后刷新
   使用前页面需存在 #reply-comment 文本框、#attach-image 按钮、#image-file 文件框 *}
{literal}
<script>
    function insertAtCursor(textarea, text) {
        const start = textarea.selectionStart || textarea.value.length;
        const end = textarea.selectionEnd || textarea.value.length;
        textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(end);
        const pos = start + text.length;
        textarea.setSelectionRange(pos, pos);
        textarea.focus();
    }

    async function uploadTicketImage(file) {
        if (!file || !file.type.startsWith('image/')) return;
        showToast('图片上传中…', 'success');
        const form = new FormData();
        form.append('image', file);
        try {
            const resp = await fetch('/user/ticket/upload', { method: 'POST', body: form });
            const data = await resp.json();
            if (data.ret === 1) {
                insertAtCursor(document.getElementById('reply-comment'), '\n' + data.url + '\n');
                showToast('图片已插入,发送后可见', 'success');
            } else {
                showToast(data.msg, 'danger');
            }
        } catch (e) {
            showToast('图片上传失败', 'danger');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const textarea = document.getElementById('reply-comment');
        const attachBtn = document.getElementById('attach-image');
        const fileInput = document.getElementById('image-file');
        if (!textarea) return;

        if (attachBtn && fileInput) {
            attachBtn.addEventListener('click', function () { fileInput.click(); });
            fileInput.addEventListener('change', function () {
                if (this.files[0]) uploadTicketImage(this.files[0]);
                this.value = '';
            });
        }

        // 直接粘贴截图
        textarea.addEventListener('paste', function (e) {
            const files = e.clipboardData && e.clipboardData.files;
            if (files && files.length > 0) {
                e.preventDefault();
                uploadTicketImage(files[0]);
            }
        });

        // 拖拽图片到输入框
        textarea.addEventListener('dragover', function (e) { e.preventDefault(); });
        textarea.addEventListener('drop', function (e) {
            const files = e.dataTransfer && e.dataTransfer.files;
            if (files && files.length > 0) {
                e.preventDefault();
                uploadTicketImage(files[0]);
            }
        });

        // 消息区滚到最新
        const thread = document.getElementById('ticket-thread');
        if (thread) thread.scrollTop = thread.scrollHeight;
    });

    // 回复成功后刷新对话
    function afterTicketReply(event) {
        try {
            const res = JSON.parse(event.detail.xhr.responseText);
            if (res.ret === 1) setTimeout(function () { location.reload(); }, 700);
        } catch (e) { /* 非 JSON 响应交给通用处理 */ }
    }
</script>
{/literal}
