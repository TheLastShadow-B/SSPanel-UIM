{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">定时任务设置</h2>
        <p class="text-faint mt-1 text-sm">设置站点的定时任务</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/cron" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}

            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='cron'}
<div x-data="{ stab: 'daily_job' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'daily_job' && 'active'" @click="stab = 'daily_job'">每日任务</button>
        <button class="pill-tab" :class="stab === 'finance_mail' && 'active'" @click="stab = 'finance_mail'">财务报告</button>
        <button class="pill-tab" :class="stab === 'detect' && 'active'" @click="stab = 'detect'">审计任务</button>
        <button class="pill-tab" :class="stab === 'inactive' && 'active'" @click="stab = 'inactive'">闲置账号检测</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="daily_job" x-show="stab === 'daily_job'" >
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">每日任务执行时间(小时)</label>
                                            <div class="col">
                                                <input id="daily_job_hour" type="text" class="field-input"
                                                       value="{$settings['daily_job_hour']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">每日任务执行时间(分钟)</label>
                                            <div class="col">
                                                <input id="daily_job_minute" type="text" class="field-input"
                                                       value="{$settings['daily_job_minute']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">订阅到期前X天生成续费账单</label>
                                            <div class="col">
                                                <input id="subscription_renewal_days" type="number" class="field-input"
                                                       value="{$settings['subscription_renewal_days']}" min="1" max="30">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="finance_mail" x-show="stab === 'finance_mail'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">是否启用每日财务报告</label>
                                            <div class="col">
                                                <select id="enable_daily_finance_mail" class="field-input"
                                                        value="{$settings['enable_daily_finance_mail']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_daily_finance_mail']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['enable_daily_finance_mail']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">是否启用每周财务报告</label>
                                            <div class="col">
                                                <select id="enable_weekly_finance_mail" class="field-input"
                                                        value="{$settings['enable_weekly_finance_mail']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_weekly_finance_mail']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['enable_weekly_finance_mail']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">是否启用每月财务报告</label>
                                            <div class="col">
                                                <select id="enable_monthly_finance_mail" class="field-input"
                                                        value="{$settings['enable_monthly_finance_mail']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_monthly_finance_mail']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['enable_monthly_finance_mail']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="detect" x-show="stab === 'detect'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">是否启用节点被墙检测</label>
                                            <div class="col">
                                                <select id="enable_detect_gfw" class="field-input"
                                                        value="{$settings['enable_detect_gfw']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_detect_gfw']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1" {if $settings['enable_detect_gfw']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">是否启用审计封禁</label>
                                            <div class="col">
                                                <select id="enable_detect_ban" class="field-input"
                                                        value="{$settings['enable_detect_ban']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_detect_ban']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1" {if $settings['enable_detect_ban']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="inactive" x-show="stab === 'inactive'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">是否启用闲置账号检测</label>
                                            <div class="col">
                                                <select id="enable_detect_inactive_user" class="field-input"
                                                        value="{$settings['enable_detect_inactive_user']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_detect_inactive_user']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['enable_detect_inactive_user']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">未签到时长(天)</label>
                                            <div class="col">
                                                <input id="detect_inactive_user_checkin_days" type="text"
                                                       class="field-input"
                                                       value="{$settings['detect_inactive_user_checkin_days']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">未登录时长(天)</label>
                                            <div class="col">
                                                <input id="detect_inactive_user_login_days" type="text"
                                                       class="field-input"
                                                       value="{$settings['detect_inactive_user_login_days']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">未使用时长(天)</label>
                                            <div class="col">
                                                <input id="detect_inactive_user_use_days" type="text"
                                                       class="field-input"
                                                       value="{$settings['detect_inactive_user_use_days']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">是否启用移除闲置账号订阅链接与邀请码</label>
                                            <div class="col">
                                                <select id="remove_inactive_user_link_and_invite" class="field-input"
                                                        value="{$settings['remove_inactive_user_link_and_invite']}">
                                                    <option value="0"
                                                            {if ! $settings['remove_inactive_user_link_and_invite']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['remove_inactive_user_link_and_invite']}selected{/if}>
                                                        开启
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
