{include file='components/header.tpl' preheader='你的邮箱验证码'}
{include file='components/hero.tpl' hero_title='邮箱验证码'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好</p>
<p style="margin:0 0 18px;">感谢注册 {$config['appName']},你的邮箱验证码是:</p>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:6px 0 14px;">
    <tr>
        <td align="center" style="background-color:#ecf3fb;border-radius:8px;padding:18px 12px;font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:28px;font-weight:700;letter-spacing:6px;color:#1a5db0;">{$code}</td>
    </tr>
</table>
<p style="margin:0;font-size:13px;color:#667085;text-align:center;">验证码有效期至 {$expire},请勿泄露给他人。</p>
<p style="margin:14px 0 0;font-size:13px;color:#667085;text-align:center;">如非本人操作,请忽略此邮件。</p>
{include file='components/footer.tpl'}
