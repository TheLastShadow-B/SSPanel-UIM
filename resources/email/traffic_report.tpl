{include file='components/header.tpl' preheader='你的每日流量使用报告'}
{include file='components/hero.tpl' hero_title='每日流量报告'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">以下是你截至今日的流量使用情况:</p>
{$rows = []}
{if isset($lastday_traffic)}{$rows[] = ['label' => '昨日用量', 'value' => $lastday_traffic]}{/if}
{if isset($used_traffic)}{$rows[] = ['label' => '已用流量', 'value' => $used_traffic]}{/if}
{if isset($unused_traffic)}{$rows[] = ['label' => '剩余流量', 'value' => $unused_traffic, 'color' => 'green']}{/if}
{if isset($enable_traffic)}{$rows[] = ['label' => '总流量', 'value' => $enable_traffic]}{/if}
{if $rows}{include file='components/card.tpl' card_title='用量概览' card_rows=$rows}{/if}
{if isset($used_pct)}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:4px 0 16px;">
    <tr>
        <td style="background-color:#e9ecef;border-radius:6px;height:10px;line-height:10px;font-size:0;">
            <div style="width:{$used_pct}%;max-width:100%;height:10px;border-radius:6px;background-color:{if $used_pct >= 80}#c62828{else}#206bc4{/if};">&nbsp;</div>
        </td>
    </tr>
    <tr>
        <td align="right" style="padding-top:4px;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:12px;color:#667085;">已使用 {$used_pct}%</td>
    </tr>
</table>
{/if}
<p style="margin:8px 0 0;font-size:14px;line-height:22px;color:#475467;">{$text}</p>
{include file='components/button.tpl' btn_text='查看用量详情' btn_url="{$config['baseUrl']}/user"}
{include file='components/footer.tpl'}
