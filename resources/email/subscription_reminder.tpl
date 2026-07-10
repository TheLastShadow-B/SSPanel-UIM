{include file='components/header.tpl' preheader='续费订单仍未支付,请尽快完成支付'}
{include file='components/hero.tpl' hero_title='续费订单待支付'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">{$text}</p>
{$rows = []}
{if isset($plan_name)}{$rows[] = ['label' => '套餐', 'value' => $plan_name]}{/if}
{if isset($billing_cycle_text)}{$rows[] = ['label' => '计费周期', 'value' => $billing_cycle_text]}{/if}
{if isset($amount)}{$rows[] = ['label' => '续费金额', 'value' => "{$amount} 元", 'color' => 'orange']}{/if}
{if isset($end_date)}{$rows[] = ['label' => '本期到期日', 'value' => $end_date]}{/if}
{if isset($order_id)}{$rows[] = ['label' => '订单号', 'value' => "#{$order_id}"]}{/if}
{if $rows}{include file='components/card.tpl' card_title='续费订单' card_rows=$rows}{/if}
{if isset($invoice_url)}{include file='components/button.tpl' btn_text='立即支付' btn_url=$invoice_url}{/if}
<p style="margin:14px 0 0;font-size:13px;line-height:20px;color:#667085;text-align:center;">若未在到期前完成支付,服务将在宽限期后中断。</p>
{include file='components/footer.tpl'}
