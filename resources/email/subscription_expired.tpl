{include file='components/header.tpl' preheader='你的订阅已过期'}
{include file='components/hero.tpl' hero_title='订阅已过期'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">{$text}</p>
{$rows = []}
{if isset($plan_name)}{$rows[] = ['label' => '套餐', 'value' => $plan_name]}{/if}
{if isset($end_date)}{$rows[] = ['label' => '过期日', 'value' => $end_date, 'color' => 'red']}{/if}
{if isset($plan_name) || isset($end_date)}{$rows[] = ['label' => '状态', 'value' => '已过期', 'color' => 'red']}{/if}
{if $rows}{include file='components/card.tpl' card_rows=$rows}{/if}
{include file='components/button.tpl' btn_text='重新订阅' btn_url="{$config['baseUrl']}/user/product"}
<p style="margin:14px 0 0;font-size:13px;line-height:20px;color:#667085;text-align:center;">重新购买后服务立即恢复。</p>
{include file='components/footer.tpl'}
