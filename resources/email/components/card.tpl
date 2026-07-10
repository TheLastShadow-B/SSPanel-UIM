{* 信息卡(自闭合)。参数:$card_rows 必填(元素:label/value/可选 color=green|red|orange),
   $card_title 可选。 *}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e6e7e9;border-radius:6px;border-collapse:separate;margin:18px 0;">
    {if isset($card_title)}
    <tr>
        <td style="background-color:#f8f9fa;border-bottom:1px solid #e6e7e9;border-radius:6px 6px 0 0;padding:12px 16px;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:14px;font-weight:600;color:#344054;">{$card_title}</td>
    </tr>
    {/if}
    <tr>
        <td style="padding:12px 16px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                {foreach $card_rows as $row}
                <tr>
                    <td style="padding:5px 0;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;color:#475467;">
                        {$row['label']}:
                        <strong style="color:{if isset($row['color']) && $row['color'] === 'green'}#2e7d32{elseif isset($row['color']) && $row['color'] === 'red'}#c62828{elseif isset($row['color']) && $row['color'] === 'orange'}#ef6c00{else}#1f2937{/if};">{$row['value']}</strong>
                    </td>
                </tr>
                {/foreach}
            </table>
        </td>
    </tr>
</table>
