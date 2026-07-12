{include file='shell/admin_header.tpl' nav='syslog'}

<a href="/admin/syslog" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回系统日志
</a>

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">日志事件 #{$syslog->id}</h2>
    <p class="text-faint mt-1 text-sm">系统日志详情</p>
</div>

<div class="c-card-pad max-w-3xl">
    <div class="kv-row"><span class="kv-key">触发用户</span><span class="kv-val">#{$syslog->user_id}</span></div>
    <div class="kv-row"><span class="kv-key">触发 IP</span><span class="kv-val font-mono text-xs">{$syslog->ip}</span></div>
    <div class="kv-row"><span class="kv-key">日志等级</span><span class="badge-neutral">{$syslog->level_text}</span></div>
    <div class="kv-row"><span class="kv-key">日志类别</span><span class="badge-neutral">{$syslog->channel_text}</span></div>
    <div class="border-hairline mt-3 border-t pt-3">
        <div class="text-faint mb-1.5 text-xs">日志内容</div>
        <div class="bg-tile text-body rounded-(--radius-tile) px-4 py-3 font-mono text-xs leading-relaxed break-all">
            {$syslog->message}
        </div>
    </div>
</div>

{include file='shell/admin_footer.tpl'}
