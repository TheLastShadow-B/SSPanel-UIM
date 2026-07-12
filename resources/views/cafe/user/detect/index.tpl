{include file='shell/header.tpl' nav='server'}

<a href="/user/server" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回节点状态
</a>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">审计规则</h2>
        <p class="text-faint mt-1 text-sm">目前站点中所使用的审计规则</p>
    </div>
    <a href="/user/detect/log" class="btn-secondary btn-sm"><i class="ti ti-history"></i> 我的审计记录</a>
</div>

<div class="c-card">
    <div class="table-card overflow-x-auto">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>名称</th>
                <th>描述</th>
                <th>正则表达式</th>
                <th>类型</th>
            </tr>
            </thead>
            <tbody>
            {foreach $rules as $rule}
                <tr>
                    <td class="text-ink font-medium">#{$rule->id}</td>
                    <td class="text-ink">{$rule->name}</td>
                    <td>{$rule->text}</td>
                    <td><code class="bg-tile rounded px-1.5 py-0.5 font-mono text-xs">{$rule->regex}</code></td>
                    <td>
                        {if $rule->type === 1}
                            <span class="badge-neutral">数据包明文匹配</span>
                        {elseif $rule->type === 2}
                            <span class="badge-neutral">数据包 hex 匹配</span>
                        {/if}
                    </td>
                </tr>
            {foreachelse}
                <tr>
                    <td colspan="5">
                        <div class="text-faint flex flex-col items-center gap-2 py-10 text-sm">
                            <i class="ti ti-shield-off text-2xl"></i>
                            暂无审计规则
                        </div>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
</div>

{include file='shell/footer.tpl'}
