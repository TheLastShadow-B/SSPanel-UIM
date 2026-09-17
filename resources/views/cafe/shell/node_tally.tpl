{* 一家运营商的比例条 + 计数；节点页的桌面磁贴与移动端汇总卡共用。变量：$carrier（TcpProbeStatus::tally 的一行） *}
<span class="probe-split" aria-hidden="true">
    {foreach ['green', 'yellow', 'red', 'gray'] as $status}
        <span data-status="{$status}" data-bar="{$carrier['carrier']}:{$status}" style="flex-grow: {$carrier[$status]}"{if $carrier[$status] === 0} hidden{/if}></span>
    {/foreach}
</span>
<span class="text-body flex flex-wrap gap-x-2.5 gap-y-0.5 text-xs">
    {foreach ['green' => '正常', 'yellow' => '波动', 'red' => '中断', 'gray' => '无数据'] as $status => $label}
        <span class="inline-flex items-center gap-1 tabular-nums" data-tally-item="{$carrier['carrier']}:{$status}"{if $carrier[$status] === 0} hidden{/if}><span class="probe-dot{if $status === 'gray'} is-hollow{/if}" data-status="{$status}" aria-hidden="true"></span><span data-tally="{$carrier['carrier']}:{$status}">{$carrier[$status]}</span> {$label}</span>
    {/foreach}
</span>
