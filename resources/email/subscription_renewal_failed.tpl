{include file='components/header.tpl' preheader='自动续费扣款失败,已进入宽限期'}
{include file='components/hero.tpl' hero_title='订阅续费失败'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">{$text}</p>
{$rows = []}
{if isset($plan_name)}{$rows[] = ['label' => '套餐', 'value' => $plan_name]}{/if}
{if isset($amount)}{$rows[] = ['label' => '续费金额', 'value' => "{$amount} 元", 'color' => 'red']}{/if}
{if isset($grace_until)}{$rows[] = ['label' => '宽限截止', 'value' => $grace_until, 'color' => 'red']}{/if}
{if $rows}{include file='components/card.tpl' card_rows=$rows}{/if}
{if isset($invoice_url)}{include file='components/button.tpl' btn_text='前往支付' btn_url=$invoice_url}{/if}
<p style="margin:14px 0 0;font-size:13px;line-height:20px;color:#667085;text-align:center;">在宽限期内完成支付即可无缝续期。</p>
{include file='components/footer.tpl'}
