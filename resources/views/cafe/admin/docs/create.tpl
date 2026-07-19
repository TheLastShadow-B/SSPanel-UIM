{include file='shell/admin_header.tpl' nav='docs'}

<a href="/admin/docs" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回文档列表
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3" x-data="{ showGen: false }">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">新建文档</h2>
        <p class="text-faint mt-1 text-sm">创建使用文档</p>
    </div>
    <div class="flex gap-2">
        <button id="generate-btn" class="btn-outline btn-sm" @click="showGen = true">
            <i class="ti ti-robot"></i> LLM 生成
        </button>
        <button id="save" class="btn-primary btn-sm"
                hx-post="/admin/docs" hx-swap="none"
                hx-vals='js:{
                    title: document.getElementById("title").value,
                    status: document.getElementById("status").value,
                    sort: document.getElementById("sort").value,
                    content: tinyMCE.activeEditor.getContent(),
                }'>
            <i class="ti ti-device-floppy"></i> 保存
        </button>
    </div>
</div>

<div class="grid items-start gap-5 lg:grid-cols-3">
    <div class="c-card-pad lg:col-span-2">
        <textarea id="tinymce"></textarea>
    </div>
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">选项</h3>
        <div class="mb-3">
            <label class="field-label" for="title">标题</label>
            <input id="title" type="text" class="field-input" value="">
        </div>
        <div class="mb-3">
            <label class="field-label" for="status">状态</label>
            <select id="status" class="field-input">
                <option value="0">未发布</option>
                <option value="1" selected>已发布</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="field-label" for="sort">排序</label>
            <input id="sort" type="text" class="field-input" value="0">
        </div>
    </div>
</div>

{include file='tinymce.tpl'}

<template x-teleport="body">
    <div x-show="showGen" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40" @click="showGen = false"></div>
        <div class="c-card modal-pop relative w-full max-w-md p-6 shadow-xl" @keydown.escape.window="showGen = false">
            <h3 class="mb-1 text-base">LLM 生成文档</h3>
            <p class="text-faint mb-4 text-xs">生成结果将填入编辑器</p>
            <input id="question" type="text" class="field-input mb-5" placeholder="请输入文档生成提示">
            <div class="flex justify-end gap-2">
                <button class="btn-secondary btn-sm" @click="showGen = false">取消</button>
                <button class="btn-primary btn-sm" @click="showGen = false; generateDocs()">生成</button>
            </div>
        </div>
    </div>
</template>

{literal}
<script>
    function generateDocs() {
        showToast('生成中，请稍候…', 'success');
        fetch('/admin/docs/generate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ question: document.getElementById('question').value })
        }).then(function (r) { return r.json(); }).then(function (data) {
            showToast(data.msg, data.ret === 1 ? 'success' : 'danger');
            if (data.ret === 1) tinyMCE.activeEditor.setContent(data.content);
        });
    }
</script>
{/literal}

{include file='shell/admin_footer.tpl'}
