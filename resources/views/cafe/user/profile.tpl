{include file='shell/header.tpl' nav='settings'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">账户信息</h2>
        <p class="text-faint mt-1 text-sm">浏览最近的登录和使用记录</p>
    </div>
    <a href="/user/edit" class="btn-secondary btn-sm"><i class="ti ti-settings"></i> 修改资料</a>
</div>

<div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="stat-tile !text-left">
        <div class="stat-label !mt-0">账户邮箱</div>
        <div class="stat-value truncate !text-base">{$user->email}</div>
    </div>
    <div class="stat-tile !text-left">
        <div class="stat-label !mt-0">用户名</div>
        <div class="stat-value truncate !text-base">{$user->user_name}</div>
    </div>
    <div class="stat-tile !text-left">
        <div class="stat-label !mt-0">注册时间</div>
        <div class="stat-value !text-base">{$user->reg_date}</div>
    </div>
    <div class="stat-tile !text-left">
        <div class="stat-label !mt-0">累计使用流量</div>
        <div class="stat-value !text-base">{$user->totalTraffic()}</div>
    </div>
</div>

{if $public_setting['subscribe_log']}
    <div class="c-card mb-5">
        <div class="p-5 pb-3">
            <h3 class="text-base">最近 10 次订阅记录</h3>
        </div>
        <div class="table-card overflow-x-auto">
            <table>
                <thead>
                <tr>
                    <th>类型</th>
                    <th>UA</th>
                    <th>IP</th>
                    <th>归属地</th>
                    <th>时间</th>
                </tr>
                </thead>
                <tbody>
                {foreach $subs as $sub}
                    <tr>
                        <td><span class="badge-neutral">{$sub->type}</span></td>
                        <td class="max-w-64 truncate">{$sub->request_user_agent}</td>
                        <td class="font-mono text-xs">{$sub->request_ip}</td>
                        <td>{$sub->location}</td>
                        <td class="text-faint">{$sub->request_time}</td>
                    </tr>
                {foreachelse}
                    <tr><td colspan="5" class="text-faint py-8 text-center">暂无记录</td></tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    </div>
{/if}

<div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
    {if $public_setting['login_log']}
        <div class="c-card">
            <div class="p-5 pb-3">
                <h3 class="text-base">最近 10 次成功登录</h3>
            </div>
            <div class="table-card overflow-x-auto">
                <table>
                    <thead>
                    <tr>
                        <th>IP</th>
                        <th>归属地</th>
                        <th>时间</th>
                    </tr>
                    </thead>
                    <tbody>
                    {foreach $logins as $login}
                        <tr>
                            <td class="font-mono text-xs">{$login->ip}</td>
                            <td>{$login->location}</td>
                            <td class="text-faint">{$login->datetime}</td>
                        </tr>
                    {foreachelse}
                        <tr><td colspan="3" class="text-faint py-8 text-center">暂无记录</td></tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    {/if}
    <div class="c-card">
        <div class="p-5 pb-3">
            <h3 class="text-base">当前在线 IP</h3>
        </div>
        <div class="table-card overflow-x-auto">
            <table>
                <thead>
                <tr>
                    <th>IP</th>
                    <th>归属地</th>
                    <th>节点</th>
                    <th>最后在线</th>
                </tr>
                </thead>
                <tbody>
                {foreach $ips as $ip}
                    <tr>
                        <td class="font-mono text-xs">{$ip->ip}</td>
                        <td>{$ip->location}</td>
                        <td><span class="badge-primary">{$ip->node_name}</span></td>
                        <td class="text-faint">{$ip->last_time}</td>
                    </tr>
                {foreachelse}
                    <tr><td colspan="4" class="text-faint py-8 text-center">暂无在线连接</td></tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    </div>
</div>

{include file='shell/footer.tpl'}
