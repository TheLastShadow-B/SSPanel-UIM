{include file='shell/admin_header.tpl' nav='docs'}

<a href="/admin/docs" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回文档列表
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">编辑文档 #{$doc->id}</h2>
        <p class="text-faint mt-1 text-sm">编辑使用文档</p>
    </div>
    <div class="flex gap-2">
        <button id="save" class="btn-primary btn-sm"
                hx-put="/admin/docs/{$doc->id}" hx-swap="none"
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

<div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-3">
    <div class="c-card-pad lg:col-span-2">
        <textarea id="tinymce">{$doc->content}</textarea>
    </div>
    <div class="c-card-pad">
        <h3 class="mb-4 text-base">选项</h3>
        <div class="mb-3">
            <label class="field-label" for="title">标题</label>
            <input id="title" type="text" class="field-input" value="{$doc->title}">
        </div>
        <div class="mb-3">
            <label class="field-label" for="status">状态</label>
            <select id="status" class="field-input">
                <option value="0" {if $doc->status === 0}selected{/if}>未发布</option>
                <option value="1" {if $doc->status === 1}selected{/if}>已发布</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="field-label" for="sort">排序</label>
            <input id="sort" type="text" class="field-input" value="{$doc->sort}">
        </div>
    </div>
</div>

{include file='tinymce.tpl'}

{include file='shell/admin_footer.tpl'}
