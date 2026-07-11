{include file='components/header.tpl' preheader='你的订阅已开通并立即生效'}
{include file='components/hero.tpl' hero_title='订阅开通成功'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">{$text}</p>
{$rows = []}
{if isset($plan_name)}{$rows[] = ['label' => '套餐', 'value' => $plan_name]}{/if}
{if isset($billing_cycle_text)}{$rows[] = ['label' => '计费周期', 'value' => $billing_cycle_text]}{/if}
{if isset($amount)}{$rows[] = ['label' => '支付金额', 'value' => "{$amount} 元", 'color' => 'green']}{/if}
{if isset($start_date) && isset($end_date)}{$rows[] = ['label' => '本期周期', 'value' => "{$start_date} 至 {$end_date}"]}{/if}
{if $rows}{include file='components/card.tpl' card_title='订阅详情' card_rows=$rows}{/if}
{include file='components/button.tpl' btn_text='查看我的订阅' btn_url="{$config['baseUrl']}/user/subscription"}
<p style="margin:14px 0 0;font-size:13px;line-height:20px;color:#667085;text-align:center;">订阅默认开启自动续费,可随时在「我的订阅」页取消;到期前我们也会邮件提醒你。</p>
{include file='components/footer.tpl'}
