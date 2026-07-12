{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">注册设置</h2>
        <p class="text-faint mt-1 text-sm">管理站点的注册设置</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/reg" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}

            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='reg'}
<div x-data="{ stab: 'reg' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'reg' && 'active'" @click="stab = 'reg'">注册设置</button>
        <button class="pill-tab" :class="stab === 'default_value' && 'active'" @click="stab = 'default_value'">默认值</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="reg" x-show="stab === 'reg'" >
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">注册模式</label>
                                            <div class="col">
                                                <select id="reg_mode" class="field-input"
                                                        value="{$settings['reg_mode']}">
                                                    <option value="close"
                                                            {if $settings['reg_mode'] === 'close'}selected{/if}>关闭注册
                                                    </option>
                                                    <option value="open"
                                                            {if $settings['reg_mode'] === 'open'}selected{/if}>公开注册
                                                    </option>
                                                    <option value="invite"
                                                            {if $settings['reg_mode'] === 'invite'}selected{/if}>
                                                        仅限用户邀请注册
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">邮箱验证</label>
                                            <div class="col">
                                                <select id="reg_email_verify" class="field-input"
                                                        value="{$settings['reg_email_verify']}">
                                                    <option value="0" {if ! $settings['reg_email_verify']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1" {if $settings['reg_email_verify']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">默认接收每日用量邮件推送</label>
                                            <div class="col">
                                                <select id="reg_daily_report" class="field-input"
                                                        value="{$settings['reg_daily_report']}">
                                                    <option value="0"
                                                            {if ! $settings['reg_daily_report']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['reg_daily_report']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="default_value" x-show="stab === 'default_value'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">注册时随机分配到的分组，多个分组请用英文半角逗号分隔</label>
                                            <div class="col">
                                                <input id="random_group" type="text" class="field-input"
                                                       value="{$settings['random_group']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">用户端口池最小值，设为 0
                                                时用户不会被分配端口</label>
                                            <div class="col">
                                                <input id="min_port" type="text" class="field-input"
                                                       value="{$settings['min_port']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">用户端口池最大值，设为 0
                                                时用户不会被分配端口</label>
                                            <div class="col">
                                                <input id="max_port" type="text" class="field-input"
                                                       value="{$settings['max_port']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">注册时赠送的流量（GB）</label>
                                            <div class="col">
                                                <input id="reg_traffic" type="text" class="field-input"
                                                       value="{$settings['reg_traffic']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">免费用戶的流量重置日，设为 0
                                                时不重置</label>
                                            <div class="col">
                                                <input id="free_user_reset_day" type="text" class="field-input"
                                                       value="{$settings['free_user_reset_day']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">需要重置的免费流量，设为 0
                                                时不重置</label>
                                            <div class="col">
                                                <input id="free_user_reset_bandwidth" type="text" class="field-input"
                                                       value="{$settings['free_user_reset_bandwidth']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">注册等级</label>
                                            <div class="col">
                                                <input id="reg_class" type="text" class="field-input"
                                                       value="{$settings['reg_class']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">注册等级过期时间（天）</label>
                                            <div class="col">
                                                <input id="reg_class_time" type="text" class="field-input"
                                                       value="{$settings['reg_class_time']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">默认加密</label>
                                            <div class="col">
                                                <input id="reg_method" type="text" class="field-input"
                                                       value="{$settings['reg_method']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">连接 IP 限制</label>
                                            <div class="col">
                                                <input id="reg_ip_limit" type="text" class="field-input"
                                                       value="{$settings['reg_ip_limit']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">使用速率限制</label>
                                            <div class="col">
                                                <input id="reg_speed_limit" type="text" class="field-input"
                                                       value="{$settings['reg_speed_limit']}">
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
