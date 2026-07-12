{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">客服设置</h2>
        <p class="text-faint mt-1 text-sm">设置站点的客服系统</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/support" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}

            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='support'}
<div x-data="{ stab: 'support' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'support' && 'active'" @click="stab = 'support'">网页客服</button>
        <button class="pill-tab" :class="stab === 'ticket' && 'active'" @click="stab = 'ticket'">工单</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="support" x-show="stab === 'support'" >
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">客服系统提供商</label>
                                            <div class="col">
                                                <select id="live_chat" class="field-input"
                                                        value="{$settings['live_chat']}">
                                                    <option value="none"
                                                            {if $settings['live_chat'] === "none"}selected{/if}>None
                                                    </option>
                                                    <option value="crisp"
                                                            {if $settings['live_chat'] === "crisp"}selected{/if}>Crisp
                                                    </option>
                                                    <option value="livechat"
                                                            {if $settings['live_chat'] === "livechat"}selected{/if}>
                                                        LiveChat
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Crisp ID</label>
                                            <div class="col">
                                                <input id="crisp_id" type="text" class="field-input"
                                                       value="{$settings['crisp_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">LiveChat License</label>
                                            <div class="col">
                                                <input id="livechat_license" type="text" class="field-input"
                                                       value="{$settings['livechat_license']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="ticket" x-show="stab === 'ticket'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">启用工单系统</label>
                                            <div class="col">
                                                <select id="enable_ticket" class="field-input"
                                                        value="{$settings['enable_ticket']}">
                                                    <option value="0" {if ! $settings['enable_ticket']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1" {if $settings['enable_ticket']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">启用工单邮件提醒</label>
                                            <div class="col">
                                                <select id="mail_ticket" class="field-input"
                                                        value="{$settings['mail_ticket']}">
                                                    <option value="0" {if ! $settings['mail_ticket']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1" {if $settings['mail_ticket']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">用戶工单配額（每月）</label>
                                            <div class="col">
                                                <input id="ticket_limit" type="text" class="field-input"
                                                       value="{$settings['ticket_limit']}">
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
