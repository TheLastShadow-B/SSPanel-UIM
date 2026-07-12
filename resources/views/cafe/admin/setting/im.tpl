{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">IM 设置</h2>
        <p class="text-faint mt-1 text-sm">管理站点的 IM 集成设置</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/im" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}

            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='im'}
<div x-data="{ stab: 'notification' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'notification' && 'active'" @click="stab = 'notification'">Notification</button>
        <button class="pill-tab" :class="stab === 'telegram' && 'active'" @click="stab = 'telegram'">Telegram Bot</button>
        <button class="pill-tab" :class="stab === 'discord' && 'active'" @click="stab = 'discord'">Discord Bot</button>
        <button class="pill-tab" :class="stab === 'slack' && 'active'" @click="stab = 'slack'">Slack Bot</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="notification" x-show="stab === 'notification'" >
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">
                                                Node Addition
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_add_node" class="field-input"
                                                        value="{$settings['im_bot_group_notify_add_node']}">
                                                    <option value="0"
                                                            {if ! $settings['im_bot_group_notify_add_node']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['im_bot_group_notify_add_node']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Node Update
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_update_node" class="field-input"
                                                        value="{$settings['im_bot_group_notify_update_node']}">
                                                    <option value="0"
                                                            {if ! $settings['im_bot_group_notify_update_node']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['im_bot_group_notify_update_node']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Node Deletion
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_delete_node" class="field-input"
                                                        value="{$settings['im_bot_group_notify_delete_node']}">
                                                    <option value="0"
                                                            {if ! $settings['im_bot_group_notify_delete_node']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['im_bot_group_notify_delete_node']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Node GFWed
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_node_gfwed" class="field-input"
                                                        value="{$settings['im_bot_group_notify_node_gfwed']}">
                                                    <option value="0"
                                                            {if ! $settings['im_bot_group_notify_node_gfwed']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['im_bot_group_notify_node_gfwed']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Node UnGFWed
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_node_ungfwed" class="field-input"
                                                        value="{$settings['im_bot_group_notify_node_ungfwed']}">
                                                    <option value="0"
                                                            {if ! $settings['im_bot_group_notify_node_ungfwed']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['im_bot_group_notify_node_ungfwed']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Node Online
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_node_online" class="field-input"
                                                        value="{$settings['im_bot_group_notify_node_online']}">
                                                    <option value="0"
                                                            {if ! $settings['im_bot_group_notify_node_online']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['im_bot_group_notify_node_online']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Node Offline
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_node_offline" class="field-input"
                                                        value="{$settings['im_bot_group_notify_node_offline']}">
                                                    <option value="0"
                                                            {if ! $settings['im_bot_group_notify_node_offline']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['im_bot_group_notify_node_offline']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Daily Job
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_daily_job" class="field-input"
                                                        value="{$settings['im_bot_group_notify_daily_job']}">
                                                    <option value="0"
                                                            {if ! $settings['im_bot_group_notify_daily_job']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['im_bot_group_notify_daily_job']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                System Dairy
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_diary" class="field-input"
                                                        value="{$settings['im_bot_group_notify_diary']}">
                                                    <option value="0" {if ! $settings['im_bot_group_notify_diary']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1" {if $settings['im_bot_group_notify_diary']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Announcement Creation
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_ann_create" class="field-input"
                                                        value="{$settings['im_bot_group_notify_ann_create']}">
                                                    <option value="0"
                                                            {if ! $settings['im_bot_group_notify_ann_create']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['im_bot_group_notify_ann_create']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Announcement Update
                                            </label>
                                            <div class="col">
                                                <select id="im_bot_group_notify_ann_update" class="field-input"
                                                        value="{$settings['im_bot_group_notify_ann_update']}">
                                                    <option value="0"
                                                            {if ! $settings['im_bot_group_notify_ann_update']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['im_bot_group_notify_ann_update']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="telegram" x-show="stab === 'telegram'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Bot Token</label>
                                            <div class="col">
                                                <input id="telegram_token" type="text" class="field-input"
                                                       value="{$settings['telegram_token']}">
                                            </div>
                                            <div class="col-auto">
                                                <button class="btn btn-primary"
                                                        hx-post="/admin/setting/im/set_webhook/telegram" hx-swap="none"
                                                        hx-vals='js:{
                                                            bot_token: document.getElementById("telegram_token").value
                                                        }'>
                                                    Set Webhook
                                                </button>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Webhook Token</label>
                                            <div class="col">
                                                <input id="telegram_webhook_token" type="text" class="field-input"
                                                       value="{$settings['telegram_webhook_token']}" disabled>
                                            </div>
                                            <div class="col-auto">
                                                <button class="btn btn-primary"
                                                        hx-post="/admin/setting/im/reset_webhook_token/telegram" hx-swap="none">
                                                    Reset Webhook Token
                                                </button>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Bot Account Username</label>
                                            <div class="col">
                                                <input id="telegram_bot" type="text" class="field-input"
                                                       value="{$settings['telegram_bot']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Group ID</label>
                                            <div class="col">
                                                <input id="telegram_chatid" type="text" class="field-input"
                                                       value="{$settings['telegram_chatid']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Enable Telegram group notify
                                            </label>
                                            <div class="col">
                                                <select id="enable_telegram_group_notify" class="field-input"
                                                        value="{$settings['enable_telegram_group_notify']}">
                                                    <option value="0" {if ! $settings['enable_telegram_group_notify']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1" {if $settings['enable_telegram_group_notify']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">解绑 Telegram
                                                账户后自动踢出群组</label>
                                            <div class="col">
                                                <select id="telegram_unbind_kick_member" class="field-input"
                                                        value="{$settings['telegram_unbind_kick_member']}">
                                                    <option value="0"
                                                            {if ! $settings['telegram_unbind_kick_member']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['telegram_unbind_kick_member']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">仅允许已绑定 Telegram
                                                账户的用户加入群组</label>
                                            <div class="col">
                                                <select id="telegram_group_bound_user" class="field-input"
                                                        value="{$settings['telegram_group_bound_user']}">
                                                    <option value="0"
                                                            {if ! $settings['telegram_group_bound_user']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['telegram_group_bound_user']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Telegram
                                                机器人发送欢迎消息</label>
                                            <div class="col">
                                                <select id="enable_welcome_message" class="field-input"
                                                        value="{$settings['enable_welcome_message']}">
                                                    <option value="0"
                                                            {if ! $settings['enable_welcome_message']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['enable_welcome_message']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Telegram
                                                机器人在群组中不回应</label>
                                            <div class="col">
                                                <select id="telegram_group_quiet" class="field-input"
                                                        value="{$settings['telegram_group_quiet']}">
                                                    <option value="0"
                                                            {if ! $settings['telegram_group_quiet']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['telegram_group_quiet']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">允许 Bot
                                                加入下方配置之外的群组</label>
                                            <div class="col">
                                                <select id="allow_to_join_new_groups" class="field-input"
                                                        value="{$settings['allow_to_join_new_groups']}">
                                                    <option value="0"
                                                            {if ! $settings['allow_to_join_new_groups']}selected{/if}>关闭
                                                    </option>
                                                    <option value="1"
                                                            {if $settings['allow_to_join_new_groups']}selected{/if}>开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">允许加入的群组 ID</label>
                                            <div class="col">
                                                <input id="group_id_allowed_to_join" type="text" class="field-input"
                                                       value="{$settings['group_id_allowed_to_join']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">允许任意未知的命令触发 /help
                                                的回复</label>
                                            <div class="col">
                                                <select id="help_any_command" class="field-input"
                                                        value="{$settings['help_any_command']}">
                                                    <option value="0" {if ! $settings['help_any_command']}selected{/if}>
                                                        关闭
                                                    </option>
                                                    <option value="1" {if $settings['help_any_command']}selected{/if}>
                                                        开启
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Telegram Chat ID(Group/DM)</label>
                                            <input type="text" class="field-input" id="telegram_chat_id" value="">
                                            <div class="row my-3">
                                                <div class="col">
                                                    <button class="btn btn-primary"
                                                        hx-post="/admin/setting/test/telegram" hx-swap="none"
                                                        hx-vals='js:{ telegram_chat_id: document.getElementById("telegram_chat_id").value }'>
                                                        Send Test Message
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="discord" x-show="stab === 'discord'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Bot Token</label>
                                            <div class="col">
                                                <input id="discord_bot_token" type="text" class="field-input"
                                                       value="{$settings['discord_bot_token']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Client ID</label>
                                            <div class="col">
                                                <input id="discord_client_id" type="text" class="field-input"
                                                       value="{$settings['discord_client_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Client Secret</label>
                                            <div class="col">
                                                <input id="discord_client_secret" type="text" class="field-input"
                                                       value="{$settings['discord_client_secret']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Guild ID</label>
                                            <div class="col">
                                                <input id="discord_guild_id" type="text" class="field-input"
                                                       value="{$settings['discord_guild_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Discord Channel ID</label>
                                            <div class="col">
                                                <input id="discord_channel_id" type="text" class="field-input"
                                                       value="{$settings['discord_channel_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Enable Discord channel notify
                                            </label>
                                            <div class="col">
                                                <select id="enable_discord_channel_notify" class="field-input"
                                                        value="{$settings['enable_discord_channel_notify']}">
                                                    <option value="0" {if ! $settings['enable_discord_channel_notify']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1" {if $settings['enable_discord_channel_notify']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Discord User ID/Channel ID</label>
                                            <input type="text" class="field-input" id="discord_channel_id" value="">
                                            <div class="row my-3">
                                                <div class="col">
                                                    <button class="btn btn-primary"
                                                        hx-post="/admin/setting/test/discord" hx-swap="none"
                                                        hx-vals='js:{ discord_channel_id: document.getElementById("discord_channel_id").value }'>
                                                        Send Test Message
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="slack" x-show="stab === 'slack'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">App Token</label>
                                            <div class="col">
                                                <input id="slack_token" type="text" class="field-input"
                                                       value="{$settings['slack_token']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Client ID</label>
                                            <div class="col">
                                                <input id="slack_client_id" type="text" class="field-input"
                                                       value="{$settings['slack_client_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Client Secret</label>
                                            <div class="col">
                                                <input id="slack_client_secret" type="text" class="field-input"
                                                       value="{$settings['slack_client_secret']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Team ID</label>
                                            <div class="col">
                                                <input id="slack_team_id" type="text" class="field-input"
                                                       value="{$settings['slack_team_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Slack Channel ID</label>
                                            <div class="col">
                                                <input id="slack_channel_id" type="text" class="field-input"
                                                       value="{$settings['slack_channel_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">
                                                Enable Slack channel notify
                                            </label>
                                            <div class="col">
                                                <select id="enable_slack_channel_notify" class="field-input"
                                                        value="{$settings['enable_slack_channel_notify']}">
                                                    <option value="0" {if ! $settings['enable_slack_channel_notify']}selected{/if}>
                                                        False
                                                    </option>
                                                    <option value="1" {if $settings['enable_slack_channel_notify']}selected{/if}>
                                                        True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Slack User ID/Channel ID</label>
                                            <input type="text" class="field-input" id="slack_channel_id" value="">
                                            <div class="row my-3">
                                                <div class="col">
                                                    <button class="btn btn-primary"
                                                        hx-post="/admin/setting/test/slack" hx-swap="none"
                                                        hx-vals='js:{ slack_channel_id: document.getElementById("slack_channel_id").value }'>
                                                        Send Test Message
                                                    </button>
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
</div>

{include file='shell/admin_footer.tpl'}
