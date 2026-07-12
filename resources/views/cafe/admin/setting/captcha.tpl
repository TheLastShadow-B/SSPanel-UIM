{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">验证设置</h2>
        <p class="text-faint mt-1 text-sm">设置站点的人机验证系统</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/captcha" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}

            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='captcha'}
<div x-data="{ stab: 'captcha' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'captcha' && 'active'" @click="stab = 'captcha'">验证设置</button>
        <button class="pill-tab" :class="stab === 'turnstile' && 'active'" @click="stab = 'turnstile'">Turnstile</button>
        <button class="pill-tab" :class="stab === 'geetest' && 'active'" @click="stab = 'geetest'">Geetest</button>
        <button class="pill-tab" :class="stab === 'hcaptcha' && 'active'" @click="stab = 'hcaptcha'">hCaptcha</button>
        <button class="pill-tab" :class="stab === 'recaptcha_enterprise' && 'active'" @click="stab = 'recaptcha_enterprise'">reCAPTCHA Enterprise</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="captcha" x-show="stab === 'captcha'" >
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">验证码提供商</label>
                                            <div class="col">
                                                <select id="captcha_provider" class="field-input"
                                                        value="{$settings['captcha_provider']}">
                                                    <option value="turnstile"
                                                            {if $settings['captcha_provider'] === "turnstile"}selected{/if}>
                                                        Turnstile
                                                    </option>
                                                    <option value="geetest"
                                                            {if $settings['captcha_provider'] === "geetest"}selected{/if}>
                                                        Geetest
                                                    </option>
                                                    <option value="hcaptcha"
                                                            {if $settings['captcha_provider'] === "hcaptcha"}selected{/if}>
                                                        hCaptcha
                                                    </option>
                                                    <option value="recaptcha_enterprise"
                                                            {if $settings['captcha_provider'] === "recaptcha_enterprise"}selected{/if}>
                                                        reCAPTCHA Enterprise
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">注册验证码</label>
                                            <div class="col">
                                                <select id="enable_reg_captcha" class="field-input"
                                                        value="{$settings['enable_reg_captcha']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_reg_captcha']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1" {if $settings['enable_reg_captcha']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">登录验证码</label>
                                            <div class="col">
                                                <select id="enable_login_captcha" class="field-input"
                                                        value="{$settings['enable_login_captcha']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_login_captcha']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['enable_login_captcha']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">签到验证码</label>
                                            <div class="col">
                                                <select id="enable_checkin_captcha" class="field-input"
                                                        value="{$settings['enable_checkin_captcha']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_checkin_captcha']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['enable_checkin_captcha']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">重置密码验证码</label>
                                            <div class="col">
                                                <select id="enable_reset_password_captcha" class="field-input"
                                                        value="{$settings['enable_reset_password_captcha']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_reset_password_captcha']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['enable_reset_password_captcha']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="turnstile" x-show="stab === 'turnstile'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Site Key</label>
                                            <div class="col">
                                                <input id="turnstile_sitekey" type="text" class="field-input"
                                                       value="{$settings['turnstile_sitekey']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Secret</label>
                                            <div class="col">
                                                <input id="turnstile_secret" type="text" class="field-input"
                                                       value="{$settings['turnstile_secret']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="geetest" x-show="stab === 'geetest'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">ID</label>
                                            <div class="col">
                                                <input id="geetest_id" type="text" class="field-input"
                                                       value="{$settings['geetest_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Key</label>
                                            <div class="col">
                                                <input id="geetest_key" type="text" class="field-input"
                                                       value="{$settings['geetest_key']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="hcaptcha" x-show="stab === 'hcaptcha'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Site Key</label>
                                            <div class="col">
                                                <input id="hcaptcha_sitekey" type="text" class="field-input"
                                                       value="{$settings['hcaptcha_sitekey']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Secret</label>
                                            <div class="col">
                                                <input id="hcaptcha_secret" type="text" class="field-input"
                                                       value="{$settings['hcaptcha_secret']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="recaptcha_enterprise" x-show="stab === 'recaptcha_enterprise'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">
                                                Key
                                            </label>
                                            <div class="col">
                                                <input id="recaptcha_enterprise_key_id" type="text" class="field-input"
                                                       value="{$settings['recaptcha_enterprise_key_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Project ID
                                            </label>
                                            <div class="col">
                                                <input id="recaptcha_enterprise_project_id" type="text" class="field-input"
                                                       value="{$settings['recaptcha_enterprise_project_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                API Key
                                            </label>
                                            <div class="col">
                                                <input id="recaptcha_enterprise_api_key" type="text" class="field-input"
                                                       value="{$settings['recaptcha_enterprise_api_key']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
</div>

{include file='shell/admin_footer.tpl'}
