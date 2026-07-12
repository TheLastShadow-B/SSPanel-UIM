{include file='shell/auth_top.tpl' page_title='设置新密码' brand_title='设置一个新密码<br>然后重新出发' brand_sub='设置成功后请使用新密码登录用户中心。'}

<h2 class="text-2xl font-semibold tracking-tight">设置新密码</h2>
<p class="text-faint mt-1.5 mb-7 text-sm">请输入并确认你的新密码</p>

<div class="mb-4">
    <label class="field-label" for="password">新密码</label>
    <input id="password" type="password" class="field-input" placeholder="请输入新密码" autocomplete="new-password">
</div>

<div class="mb-5">
    <label class="field-label" for="confirm_password">再次输入新密码</label>
    <input id="confirm_password" type="password" class="field-input" placeholder="请再次输入新密码" autocomplete="new-password">
</div>

<button class="btn-primary w-full"
        hx-post="{$smarty.server.REQUEST_URI}" hx-swap="none"
        hx-vals='js:{
            password: document.getElementById("password").value,
            confirm_password: document.getElementById("confirm_password").value, }'>
    <i class="ti ti-key"></i> 重置密码
</button>

<p class="text-faint mt-7 text-center text-sm">
    已有账户？ <a href="/auth/login" class="text-primary font-medium">点击登录</a>
</p>

{include file='shell/auth_bottom.tpl'}
