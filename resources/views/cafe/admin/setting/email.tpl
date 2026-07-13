{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">邮件设置</h2>
        <p class="text-faint mt-1 text-sm">设置站点的邮件系统</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/email" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}

            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='email'}
<div x-data="{ stab: 'email' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'email' && 'active'" @click="stab = 'email'">邮件设置</button>
        <button class="pill-tab" :class="stab === 'limit' && 'active'" @click="stab = 'limit'">发送限制</button>
        <button class="pill-tab" :class="stab === 'smtp' && 'active'" @click="stab = 'smtp'">SMTP</button>
        <button class="pill-tab" :class="stab === 'mailgun' && 'active'" @click="stab = 'mailgun'">Mailgun</button>
        <button class="pill-tab" :class="stab === 'sendgrid' && 'active'" @click="stab = 'sendgrid'">Sendgrid</button>
        <button class="pill-tab" :class="stab === 'postal' && 'active'" @click="stab = 'postal'">Postal</button>
        <button class="pill-tab" :class="stab === 'ses' && 'active'" @click="stab = 'ses'">AWS SES</button>
        <button class="pill-tab" :class="stab === 'mailchimp' && 'active'" @click="stab = 'mailchimp'">Mailchimp</button>
        <button class="pill-tab" :class="stab === 'alibabacloud' && 'active'" @click="stab = 'alibabacloud'">AlibabaCloud DM</button>
        <button class="pill-tab" :class="stab === 'postmark' && 'active'" @click="stab = 'postmark'">Postmark</button>
        <button class="pill-tab" :class="stab === 'resend' && 'active'" @click="stab = 'resend'">Resend</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="email" x-show="stab === 'email'" >
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">邮件服务提供商</label>
                                            <div class="col">
                                                <select id="email_driver" class="field-input"
                                                        value="{$settings['email_driver']}">
                                                    <option value="none"
                                                            {if $settings['email_driver'] === "none"}selected{/if}>
                                                        None
                                                    </option>
                                                    <option value="smtp"
                                                            {if $settings['email_driver'] === "smtp"}selected{/if}>
                                                        SMTP
                                                    </option>
                                                    <option value="mailgun"
                                                            {if $settings['email_driver'] === "mailgun"}selected{/if}>
                                                        Mailgun
                                                    </option>
                                                    <option value="sendgrid"
                                                            {if $settings['email_driver'] === "sendgrid"}selected{/if}>
                                                        Sendgrid
                                                    </option>
                                                    <option value="postal"
                                                            {if $settings['email_driver'] === "postal"}selected{/if}>
                                                        Postal
                                                    </option>
                                                    <option value="ses"
                                                            {if $settings['email_driver'] === "ses"}selected{/if}>
                                                        AWS SES
                                                    </option>
                                                    <option value="mailchimp"
                                                            {if $settings['email_driver'] === "mailchimp"}selected{/if}>
                                                        Mailchimp
                                                    </option>
                                                    <option value="alibabacloud"
                                                            {if $settings['email_driver'] === "alibabacloud"}selected{/if}>
                                                        AlibabaCloud DM
                                                    </option>
                                                    <option value="resend"
                                                            {if $settings['email_driver'] === "resend"}selected{/if}>
                                                        Resend
                                                    </option>
                                                    <option value="postmark"
                                                            {if $settings['email_driver'] === "postmark"}selected{/if}>
                                                        postmark
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">测试邮件接收地址</label>
                                            <input type="text" class="field-input" id="recipient" value="">
                                            <div class="row my-3">
                                                <div class="col">
                                                    <button id="test-email" class="btn btn-primary"
                                                            hx-post="/admin/setting/test/email" hx-swap="none"
                                                            hx-vals='js:{ recipient: document.getElementById("recipient").value }'>
                                                        发送测试邮件
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="limit" x-show="stab === 'limit'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">邮箱验证码有效期（秒）</label>
                                            <div class="col">
                                                <input id="email_verify_code_ttl" type="text" class="field-input"
                                                       value="{$settings['email_verify_code_ttl']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">邮箱重设密码链接有效期（秒）</label>
                                            <div class="col">
                                                <input id="email_password_reset_ttl" type="text" class="field-input"
                                                       value="{$settings['email_password_reset_ttl']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">单个IP每小时可请求的发信次数</label>
                                            <div class="col">
                                                <input id="email_request_ip_limit" type="text" class="field-input"
                                                       value="{$settings['email_request_ip_limit']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">单个邮箱地址每小时可请求的发信次数</label>
                                            <div class="col">
                                                <input id="email_request_address_limit" type="text" class="field-input"
                                                       value="{$settings['email_request_address_limit']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="smtp" x-show="stab === 'smtp'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Host</label>
                                            <div class="col">
                                                <input id="smtp_host" type="text" class="field-input"
                                                       value="{$settings['smtp_host']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Username</label>
                                            <div class="col">
                                                <input id="smtp_username" type="text" class="field-input"
                                                       value="{$settings['smtp_username']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Password</label>
                                            <div class="col">
                                                <input id="smtp_password" type="text" class="field-input"
                                                       value="{$settings['smtp_password']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Port</label>
                                            <div class="col">
                                                <select id="smtp_port" class="field-input"
                                                        value="{$settings['smtp_port']}">
                                                    <option value="465"
                                                            {if $settings['smtp_port'] === "465"}selected{/if}>465
                                                    </option>
                                                    <option value="587"
                                                            {if $settings['smtp_port'] === "587"}selected{/if}>587
                                                    </option>
                                                    <option value="443"
                                                            {if $settings['smtp_port'] === "443"}selected{/if}>443
                                                    </option>
                                                    <option value="80"
                                                            {if $settings['smtp_port'] === "80"}selected{/if}>80
                                                    </option>
                                                    <option value="2525"
                                                            {if $settings['smtp_port'] === "2525"}selected{/if}>2525
                                                    </option>
                                                    <option value="25"
                                                            {if $settings['smtp_port'] === "25"}selected{/if}>25
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Name</label>
                                            <div class="col">
                                                <input id="smtp_name" type="text" class="field-input"
                                                       value="{$settings['smtp_name']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Sener</label>
                                            <div class="col">
                                                <input id="smtp_sender" type="text" class="field-input"
                                                       value="{$settings['smtp_sender']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Enable TLS/SSL</label>
                                            <div class="col">
                                                <select id="smtp_ssl" class="field-input"
                                                        value="{$settings['smtp_ssl']}">
                                                    <option value="0" {if ! $settings['smtp_ssl']}selected{/if}>False
                                                    </option>
                                                    <option value="1" {if $settings['smtp_ssl']}selected{/if}>True
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">BBC</label>
                                            <div class="col">
                                                <input id="smtp_bbc" type="text" class="field-input"
                                                       value="{$settings['smtp_bbc']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="mailgun" x-show="stab === 'mailgun'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Api Key</label>
                                            <div class="col">
                                                <input id="mailgun_key" type="text" class="field-input"
                                                       value="{$settings['mailgun_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Domain</label>
                                            <div class="col">
                                                <input id="mailgun_domain" type="text" class="field-input"
                                                       value="{$settings['mailgun_domain']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Sender</label>
                                            <div class="col">
                                                <input id="mailgun_sender" type="text" class="field-input"
                                                       value="{$settings['mailgun_sender']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Sender Name</label>
                                            <div class="col">
                                                <input id="mailgun_sender_name" type="text" class="field-input"
                                                       value="{$settings['mailgun_sender_name']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="sendgrid" x-show="stab === 'sendgrid'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Api Key</label>
                                            <div class="col">
                                                <input id="sendgrid_key" type="text" class="field-input"
                                                       value="{$settings['sendgrid_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Sender</label>
                                            <div class="col">
                                                <input id="sendgrid_sender" type="text" class="field-input"
                                                       value="{$settings['sendgrid_sender']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Name</label>
                                            <div class="col">
                                                <input id="sendgrid_name" type="text" class="field-input"
                                                       value="{$settings['sendgrid_name']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="postal" x-show="stab === 'postal'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Host</label>
                                            <div class="col">
                                                <input id="postal_host" type="text" class="field-input"
                                                       value="{$settings['postal_host']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Api Key</label>
                                            <div class="col">
                                                <input id="postal_key" type="text" class="field-input"
                                                       value="{$settings['postal_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Sender</label>
                                            <div class="col">
                                                <input id="postal_sender" type="text" class="field-input"
                                                       value="{$settings['postal_sender']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Name</label>
                                            <div class="col">
                                                <input id="postal_name" type="text" class="field-input"
                                                       value="{$settings['postal_name']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="ses" x-show="stab === 'ses'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Access Key ID</label>
                                            <div class="col">
                                                <input id="aws_ses_access_key_id" type="text" class="field-input"
                                                       value="{$settings['aws_ses_access_key_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Access Key Secret</label>
                                            <div class="col">
                                                <input id="aws_ses_access_key_secret" type="text" class="field-input"
                                                       value="{$settings['aws_ses_access_key_secret']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Region</label>
                                            <div class="col">
                                                <input id="aws_ses_region" type="text" class="field-input"
                                                       value="{$settings['aws_ses_region']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Sender</label>
                                            <div class="col">
                                                <input id="aws_ses_sender" type="text" class="field-input"
                                                       value="{$settings['aws_ses_sender']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="mailchimp" x-show="stab === 'mailchimp'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Api Key</label>
                                            <div class="col">
                                                <input id="mailchimp_key" type="text" class="field-input"
                                                       value="{$settings['mailchimp_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">From Email</label>
                                            <div class="col">
                                                <input id="mailchimp_from_email" type="text" class="field-input"
                                                       value="{$settings['mailchimp_from_email']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">From Name</label>
                                            <div class="col">
                                                <input id="mailchimp_from_name" type="text" class="field-input"
                                                       value="{$settings['mailchimp_from_name']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="alibabacloud" x-show="stab === 'alibabacloud'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Access Key ID</label>
                                            <div class="col">
                                                <input id="alibabacloud_dm_access_key_id" type="text" class="field-input"
                                                       value="{$settings['alibabacloud_dm_access_key_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Access Key Secret</label>
                                            <div class="col">
                                                <input id="alibabacloud_dm_access_key_secret" type="text" class="field-input"
                                                       value="{$settings['alibabacloud_dm_access_key_secret']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Endpoint</label>
                                            <div class="col">
                                                <input id="alibabacloud_dm_endpoint" type="text" class="field-input"
                                                       value="{$settings['alibabacloud_dm_endpoint']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Account Name</label>
                                            <div class="col">
                                                <input id="alibabacloud_dm_account_name" type="text" class="field-input"
                                                       value="{$settings['alibabacloud_dm_account_name']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">From Alias</label>
                                            <div class="col">
                                                <input id="alibabacloud_dm_from_alias" type="text" class="field-input"
                                                       value="{$settings['alibabacloud_dm_from_alias']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="postmark" x-show="stab === 'postmark'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Api Key</label>
                                            <div class="col">
                                                <input id="postmark_key" type="text" class="field-input"
                                                       value="{$settings['postmark_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">发件人</label>
                                            <div class="col">
                                                <input id="postmark_sender" type="text" class="field-input"
                                                       value="{$settings['postmark_sender']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Stream</label>
                                            <div class="col">
                                                <input id="postmark_stream" type="text" class="field-input"
                                                       value="{$settings['postmark_stream']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="resend" x-show="stab === 'resend'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Api Key</label>
                                            <div class="col">
                                                <input id="resend_api_key" type="text" class="field-input"
                                                       value="{$settings['resend_api_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">From</label>
                                            <div class="col">
                                                <input id="resend_from" type="text" class="field-input"
                                                       value="{$settings['resend_from']}">
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
