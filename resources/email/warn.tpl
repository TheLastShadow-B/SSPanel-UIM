{include file='components/header.tpl'}
{include file='components/hero.tpl' hero_title=$title|default:'系统提示'}
<p style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1f2937;">你好{if isset($user)},{$user->user_name}{/if}</p>
<p style="margin:0;">{$text}</p>
{include file='components/footer.tpl'}
