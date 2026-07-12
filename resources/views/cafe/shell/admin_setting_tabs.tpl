{* 面板设置子页导航:{include file='shell/admin_setting_tabs.tpl' tab='reg'} *}
<div class="pill-tabs mb-6">
    <a href="/admin/setting/billing" class="pill-tab {if ($tab|default:'') === 'billing'}active{/if}">财务</a>
    <a href="/admin/setting/email" class="pill-tab {if ($tab|default:'') === 'email'}active{/if}">邮件</a>
    <a href="/admin/setting/support" class="pill-tab {if ($tab|default:'') === 'support'}active{/if}">客服</a>
    <a href="/admin/setting/captcha" class="pill-tab {if ($tab|default:'') === 'captcha'}active{/if}">验证</a>
    <a href="/admin/setting/reg" class="pill-tab {if ($tab|default:'') === 'reg'}active{/if}">注册</a>
    <a href="/admin/setting/ref" class="pill-tab {if ($tab|default:'') === 'ref'}active{/if}">邀请</a>
    <a href="/admin/setting/im" class="pill-tab {if ($tab|default:'') === 'im'}active{/if}">IM</a>
    <a href="/admin/setting/sub" class="pill-tab {if ($tab|default:'') === 'sub'}active{/if}">订阅</a>
    <a href="/admin/setting/cron" class="pill-tab {if ($tab|default:'') === 'cron'}active{/if}">定时任务</a>
    <a href="/admin/setting/llm" class="pill-tab {if ($tab|default:'') === 'llm'}active{/if}">LLM</a>
    <a href="/admin/setting/feature" class="pill-tab {if ($tab|default:'') === 'feature'}active{/if}">其他</a>
</div>
