{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">邀请设置</h2>
        <p class="text-faint mt-1 text-sm">管理站点的邀请设置</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/ref" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}

            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='ref'}
<div x-data="{ stab: 'invite' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'invite' && 'active'" @click="stab = 'invite'">邀请奖励</button>
        <button class="pill-tab" :class="stab === 'rebate' && 'active'" @click="stab = 'rebate'">返利</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="invite" x-show="stab === 'invite'" >
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">被邀请者初始账户余额（元）</label>
                                            <div class="col">
                                                <input id="invite_reg_money_reward" type="text"
                                                       class="field-input"
                                                       value="{$settings['invite_reg_money_reward']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">邀请者流量奖励（GB）</label>
                                            <div class="col">
                                                <input id="invite_reg_traffic_reward" type="text"
                                                       class="field-input"
                                                       value="{$settings['invite_reg_traffic_reward']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="rebate" x-show="stab === 'rebate'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">返利模式</label>
                                            <div class="col">
                                                <select id="invite_mode" class="field-input"
                                                        value="{$settings['invite_mode']}">
                                                    <option value="reg_only"
                                                            {if $settings['invite_mode'] === 'reg_only'}selected{/if}>
                                                        不返利
                                                    </option>
                                                    <option value="reward"
                                                            {if $settings['invite_mode'] === 'reward'}selected{/if}>
                                                        被邀请用户支付账单时返利
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">返利奖励模式</label>
                                            <div class="col">
                                                <select id="invite_reward_mode" class="field-input"
                                                        value="{$settings['invite_reward_mode']}">
                                                    <option value="reward_count"
                                                            {if $settings['invite_reward_mode'] === 'reward_count'}selected{/if}>
                                                        限制返利次数
                                                    </option>
                                                    <option value="reward_total"
                                                            {if $settings['invite_reward_mode'] === 'reward_total'}selected{/if}>
                                                        限制返利金额
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">返利比例，10% 填 0.1</label>
                                            <div class="col">
                                                <input id="invite_reward_rate" type="text" class="field-input"
                                                       value="{$settings['invite_reward_rate']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">返利次数限制</label>
                                            <div class="col">
                                                <input id="invite_reward_count_limit" type="text" class="field-input"
                                                       value="{$settings['invite_reward_count_limit']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">返利金额限制</label>
                                            <div class="col">
                                                <input id="invite_reward_total_limit" type="text" class="field-input"
                                                       value="{$settings['invite_reward_total_limit']}">
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
