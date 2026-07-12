{include file='shell/header.tpl' nav='server'}

<a href="/user/detect" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回审计规则
</a>

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">审计记录</h2>
    <p class="text-faint mt-1 text-sm">你的账户触发的审计记录</p>
</div>

<div class="c-card">
    <div class="table-card overflow-x-auto">
        <table>
            <thead>
            <tr>
                <th>事件 ID</th>
                <th>节点</th>
                <th>规则</th>
                <th>描述</th>
                <th>正则表达式</th>
                <th>时间</th>
            </tr>
            </thead>
            <tbody>
            {foreach $logs as $log}
                <tr>
                    <td class="text-ink font-medium">#{$log->id}</td>
                    <td><span class="badge-primary">{$log->node_name}</span></td>
                    <td class="text-ink">{$log->rule->name}</td>
                    <td>{$log->rule->text}</td>
                    <td><code class="bg-tile rounded px-1.5 py-0.5 font-mono text-xs">{$log->rule->regex}</code></td>
                    <td class="text-faint">{$log->datetime}</td>
                </tr>
            {foreachelse}
                <tr>
                    <td colspan="6">
                        <div class="text-faint flex flex-col items-center gap-2 py-10 text-sm">
                            <i class="ti ti-shield-check text-2xl"></i>
                            没有审计记录，保持良好
                        </div>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>

{include file='shell/footer.tpl'}
