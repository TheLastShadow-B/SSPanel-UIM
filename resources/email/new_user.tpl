{include file='components/header.tpl' preheader='你的账户已创建成功'}
{include file='components/hero.tpl' hero_title="欢迎加入 {$config['appName']}"}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">你的账户已创建成功,以下是账户信息:</p>
{$rows = []}
{if isset($user)}{$rows[] = ['label' => '账号邮箱', 'value' => $user->email]}{/if}
{if isset($reg_time)}{$rows[] = ['label' => '注册时间', 'value' => $reg_time]}{/if}
{if $rows}{include file='components/card.tpl' card_rows=$rows}{/if}
{include file='components/button.tpl' btn_text='进入用户中心' btn_url="{$config['baseUrl']}/user"}
<p style="margin:14px 0 0;font-size:13px;color:#667085;text-align:center;">如需帮助,请通过工单联系我们。</p>
{include file='components/footer.tpl'}
