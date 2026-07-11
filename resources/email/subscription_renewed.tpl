{include file='components/header.tpl' preheader='你的订阅已续费成功,服务不间断延续'}
{include file='components/hero.tpl' hero_title='订阅续费成功'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">{$text}</p>
{$rows = []}
{if isset($plan_name)}{$rows[] = ['label' => '套餐', 'value' => $plan_name]}{/if}
{if isset($billing_cycle_text)}{$rows[] = ['label' => '计费周期', 'value' => $billing_cycle_text]}{/if}
{if isset($amount)}{$rows[] = ['label' => '续费金额', 'value' => "{$amount} 元", 'color' => 'green']}{/if}
{if isset($payment_method_text)}{$rows[] = ['label' => '支付方式', 'value' => $payment_method_text]}{/if}
{if isset($end_date)}{$rows[] = ['label' => '服务延续至', 'value' => $end_date, 'color' => 'green']}{/if}
{if $rows}{include file='components/card.tpl' card_title='续费详情' card_rows=$rows}{/if}
{include file='components/button.tpl' btn_text='查看我的订阅' btn_url="{$config['baseUrl']}/user/subscription"}
<p style="margin:14px 0 0;font-size:13px;line-height:20px;color:#667085;text-align:center;">感谢你的持续支持;如非本人操作,请尽快通过工单联系我们。</p>
{include file='components/footer.tpl'}
