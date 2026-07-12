{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">订阅设置</h2>
        <p class="text-faint mt-1 text-sm">设置站点的订阅系统</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/sub" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}

            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='sub'}
<div x-data="{ stab: 'sub' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'sub' && 'active'" @click="stab = 'sub'">订阅设置</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="sub" x-show="stab === 'sub'" >
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">
                                                Enable Shadowsocks Subscription
                                            </label>
                                            <div class="col">
                                                <select id="enable_ss_sub" class="field-input"
                                                        value="{$settings['enable_ss_sub']}">
                                                    <option value="0" {if ! $settings['enable_ss_sub']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1" {if $settings['enable_ss_sub']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Enable Vmess Subscription
                                            </label>
                                            <div class="col">
                                                <select id="enable_v2_sub" class="field-input"
                                                        value="{$settings['enable_v2_sub']}">
                                                    <option value="0" {if ! $settings['enable_v2_sub']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1" {if $settings['enable_v2_sub']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Enable Trojan Subscription
                                            </label>
                                            <div class="col">
                                                <select id="enable_trojan_sub" class="field-input"
                                                        value="{$settings['enable_trojan_sub']}">
                                                    <option value="0" {if ! $settings['enable_trojan_sub']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1" {if $settings['enable_trojan_sub']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                修改账户登录密码时重置订阅地址
                                            </label>
                                            <div class="col">
                                                <select id="enable_forced_replacement" class="field-input"
                                                        value="{$settings['enable_forced_replacement']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_forced_replacement']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['enable_forced_replacement']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
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
