{* 防弹 CTA 按钮(自闭合)。参数:$btn_text、$btn_url 必填,$btn_color 可选(默认主色)。 *}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:26px auto 8px;">
    <tr>
        <td align="center" bgcolor="{$btn_color|default:'#206bc4'}" style="border-radius:6px;">
            <a href="{$btn_url}" target="_blank" style="display:inline-block;padding:12px 34px;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">{$btn_text}</a>
        </td>
    </tr>
</table>
