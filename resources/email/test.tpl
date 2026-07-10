{include file='components/header.tpl' preheader='邮件发送配置测试'}
{include file='components/hero.tpl' hero_title='测试邮件'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好</p>
<p style="margin:0;">如果你收到这封邮件,说明邮件发送配置正确。</p>
{$rows = [['label' => '发送时间', 'value' => "{$smarty.now|date_format:'Y-m-d H:i:s'}"]]}
{include file='components/card.tpl' card_rows=$rows}
{include file='components/footer.tpl'}
