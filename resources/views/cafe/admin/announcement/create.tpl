{include file='shell/admin_header.tpl' nav='announcement'}

<a href="/admin/announcement" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回公告列表
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">创建公告</h2>
        <p class="text-faint mt-1 text-sm">创建站点公告</p>
    </div>
    <div class="flex gap-2">
        <button id="save" class="btn-primary btn-sm"
                hx-post="/admin/announcement" hx-swap="none"
                hx-vals='js:{
                    status: document.getElementById("status").value,
                    sort: document.getElementById("sort").value,
                    email_notify_class: document.getElementById("email_notify_class").value,
                    email_notify: document.getElementById("email_notify").checked,
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
            <label class="field-label" for="status">状态</label>
            <select id="status" class="field-input">
                <option value="0">未发布</option>
                <option value="1" selected>已发布</option>
                <option value="2">置顶</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="field-label" for="sort">排序</label>
            <input id="sort" type="text" class="field-input" value="0">
        </div>
        <div class="border-hairline mt-4 border-t pt-4">
        <div class="mb-3">
            <label class="field-label" for="email_notify_class">邮件通知的用户等级</label>
            <input id="email_notify_class" type="text" class="field-input" value="0">
        </div>
            <label class="flex cursor-pointer items-center justify-between gap-3">
                <span class="text-body text-sm font-medium">发送邮件通知</span>
                <input id="email_notify" type="checkbox" class="accent-primary size-4" checked>
            </label>
        </div>
    </div>
</div>

{include file='tinymce.tpl'}

{include file='shell/admin_footer.tpl'}
