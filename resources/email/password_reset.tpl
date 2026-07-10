{include file='components/header.tpl' preheader='重置你的账户密码'}
{include file='components/hero.tpl' hero_title='重置密码'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好</p>
<p style="margin:0;">我们收到了你的密码重置请求,点击下方按钮设置新密码:</p>
{include file='components/button.tpl' btn_text='重置密码' btn_url=$resetUrl}
<p style="margin:14px 0 0;font-size:13px;line-height:20px;color:#667085;text-align:center;">链接在有效期内一次有效;如非本人操作,请忽略此邮件,你的密码不会被更改。</p>
{include file='components/footer.tpl'}
