{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">其他设置</h2>
        <p class="text-faint mt-1 text-sm">设置站点的其他设置</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/feature" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}

            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='feature'}
<div x-data="{ stab: 'display' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'display' && 'active'" @click="stab = 'display'">功能显示</button>
        <button class="pill-tab" :class="stab === 'log' && 'active'" @click="stab = 'log'">用户日志</button>
        <button class="pill-tab" :class="stab === 'checkin' && 'active'" @click="stab = 'checkin'">签到</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="display" x-show="stab === 'display'" >
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">显示用户审计记录</label>
                                            <div class="col">
                                                <select id="display_detect_log" class="field-input"
                                                        value="{$settings['display_detect_log']}">
                                                    <option value="0"
                                                            {if ! $settings['display_detect_log']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1" {if $settings['display_detect_log']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">显示文档</label>
                                            <div class="col">
                                                <select id="display_docs" class="field-input"
                                                        value="{$settings['display_docs']}">
                                                    <option value="0" {if ! $settings['display_docs']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1" {if $settings['display_docs']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">文档仅付费用户可见</label>
                                            <div class="col">
                                                <select id="display_docs_only_for_paid_user" class="field-input"
                                                        value="{$settings['display_docs_only_for_paid_user']}">
                                                    <option value="0"
                                                            {if ! $settings['display_docs_only_for_paid_user']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['display_docs_only_for_paid_user']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">使用文档地址</label>
                                            <div class="col">
                                                <input id="docs_url" type="text" class="field-input"
                                                       placeholder="https://docs.example.com"
                                                       value="{$settings['docs_url']}">
                                                <small class="form-hint">顶部导航「使用文档」链接的目标地址，留空则隐藏该入口</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="log" x-show="stab === 'log'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">启用每小时使用流量日志</label>
                                            <div class="col">
                                                <select id="traffic_log" class="field-input"
                                                        value="{$settings['traffic_log']}">
                                                    <option value="0" {if ! $settings['traffic_log']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1" {if $settings['traffic_log']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">流量日志保留天数</label>
                                            <div class="col">
                                                <input id="traffic_log_retention_days" type="text" class="field-input"
                                                       value="{$settings['traffic_log_retention_days']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">启用订阅日志</label>
                                            <div class="col">
                                                <select id="subscribe_log" class="field-input"
                                                        value="{$settings['subscribe_log']}">
                                                    <option value="0" {if ! $settings['subscribe_log']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1" {if $settings['subscribe_log']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">订阅日志保留天数</label>
                                            <div class="col">
                                                <input id="subscribe_log_retention_days" type="text"
                                                       class="field-input"
                                                       value="{$settings['subscribe_log_retention_days']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">通知用户新IP订阅</label>
                                            <div class="col">
                                                <select id="notify_new_subscribe" class="field-input"
                                                        value="{$settings['notify_new_subscribe']}">
                                                    <option value="0"
                                                            {if ! $settings['notify_new_subscribe']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['notify_new_subscribe']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">启用登录日志</label>
                                            <div class="col">
                                                <select id="login_log" class="field-input"
                                                        value="{$settings['login_log']}">
                                                    <option value="0" {if ! $settings['login_log']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1" {if $settings['login_log']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">通知用户新IP登录</label>
                                            <div class="col">
                                                <select id="notify_new_login" class="field-input"
                                                        value="{$settings['notify_new_login']}">
                                                    <option value="0" {if ! $settings['notify_new_login']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1" {if $settings['notify_new_login']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="checkin" x-show="stab === 'checkin'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">启用签到</label>
                                            <div class="col">
                                                <select id="enable_checkin" class="field-input"
                                                        value="{$settings['enable_checkin']}">
                                                    <option value="0" {if ! $settings['enable_checkin']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1" {if $settings['enable_checkin']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">签到最少流量（MB）</label>
                                            <div class="col">
                                                <input id="checkin_min" type="text" class="field-input"
                                                       value="{$settings['checkin_min']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">签到最多流量（MB）</label>
                                            <div class="col">
                                                <input id="checkin_max" type="text"
                                                       class="field-input"
                                                       value="{$settings['checkin_max']}">
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
