{include file='shell/header.tpl' nav='server'}
<a href="/user/server" class="text-body hover:text-primary mb-5 inline-flex items-center gap-1 text-sm"><i class="ti ti-arrow-left"></i> 全部节点</a>
<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">{$node->name|escape}</h2>
    <p class="text-faint mt-2 text-sm">回国 TCP 状态 · 最近 24 小时 · IPv4</p>
</div>
<div id="node-probe-status" hx-get="/user/server/{$node->id}/status" hx-trigger="every 60s" hx-swap="innerHTML">
    {include file='user/server_probe.tpl'}
</div>
<p id="probe-refresh-error" class="text-faint mt-3 text-xs" role="status" hidden>暂时无法更新状态，当前状态已标为无数据。历史记录保留上次读取结果。</p>
<script src="/theme/cafe/js/node-probe.js?v={$config['assets_version']}"></script>
{include file='shell/footer.tpl'}
