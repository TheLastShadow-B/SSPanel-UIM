{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">财务设置</h2>
        <p class="text-faint mt-1 text-sm">设置站点的财务系统</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/billing" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}
                {foreach $payment_gateways as $key => $value}
                {$value}: document.getElementById("{$value}_enable").checked,
                {/foreach}
            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='billing'}
<div x-data="{ stab: 'gateway' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'gateway' && 'active'" @click="stab = 'gateway'">网关选择</button>
        <button class="pill-tab" :class="stab === 'f2f' && 'active'" @click="stab = 'f2f'">支付宝当面付</button>
        <button class="pill-tab" :class="stab === 'stripe' && 'active'" @click="stab = 'stripe'">Stripe</button>
        <button class="pill-tab" :class="stab === 'epay' && 'active'" @click="stab = 'epay'">EPay</button>
        <button class="pill-tab" :class="stab === 'paypal' && 'active'" @click="stab = 'paypal'">PayPal</button>
        <button class="pill-tab" :class="stab === 'smogate' && 'active'" @click="stab = 'smogate'">Smogate</button>
        <button class="pill-tab" :class="stab === 'cryptomus' && 'active'" @click="stab = 'cryptomus'">Cryptomus</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="gateway" x-show="stab === 'gateway'" >
                                    <div class="max-w-xl">
                                        {foreach $payment_gateways as $key => $value}
                                            <label class="border-hairline flex cursor-pointer items-center justify-between gap-3 border-b py-3 last:border-b-0">
                                                <span class="text-body text-sm font-medium">{$key}</span>
                                                <input id="{$value}_enable" class="accent-primary size-4" type="checkbox"
                                                       {if in_array($value, $active_payment_gateway)}checked{/if}>
                                            </label>
                                        {/foreach}
                                    </div>
                                </div>
                                <div id="f2f" x-show="stab === 'f2f'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">App ID</label>
                                            <div class="col">
                                                <input id="f2f_pay_app_id" type="text" class="field-input"
                                                       value="{$settings['f2f_pay_app_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">PID</label>
                                            <div class="col">
                                                <input id="f2f_pay_pid" type="text" class="field-input"
                                                       value="{$settings['f2f_pay_pid']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">支付宝公钥</label>
                                            <div class="col">
                                                <input id="f2f_pay_public_key" type="text" class="field-input"
                                                       value="{$settings['f2f_pay_public_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">应用私钥</label>
                                            <div class="col">
                                                <input id="f2f_pay_private_key" type="text" class="field-input"
                                                       value="{$settings['f2f_pay_private_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">自定义回调地址（可选）</label>
                                            <div class="col">
                                                <input id="f2f_pay_notify_url" type="text" class="field-input"
                                                       value="{$settings['f2f_pay_notify_url']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="stripe" x-show="stab === 'stripe'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">API Key</label>
                                            <div class="col">
                                                <input id="stripe_api_key" type="text" class="field-input"
                                                       value="{$settings['stripe_api_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Endpoint Secret</label>
                                            <div class="col">
                                                <input id="stripe_endpoint_secret" type="text" class="field-input"
                                                       value="{$settings['stripe_endpoint_secret']}">
                                            </div>
                                            <div class="col-auto">
                                                <button class="btn btn-primary"
                                                        hx-post="/admin/setting/billing/set_stripe_webhook" hx-swap="none"
                                                        hx-vals='js:{
                                                            stripe_api_key: document.getElementById("stripe_api_key").value
                                                        }'>
                                                    Set Webhook
                                                </button>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">网关货币</label>
                                            <div class="col">
                                                <input id="stripe_currency" type="text" class="field-input"
                                                       value="{$settings['stripe_currency']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">银行卡支付</label>
                                            <div class="col">
                                                <select id="stripe_card" class="field-input"
                                                        value="{$settings['stripe_card']}">
                                                    <option value="0">停用</option>
                                                    <option value="1" {if $settings['stripe_card']}selected{/if}>启用
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">支付宝支付</label>
                                            <div class="col">
                                                <select id="stripe_alipay" class="field-input"
                                                        value="{$settings['stripe_alipay']}">
                                                    <option value="0">停用</option>
                                                    <option value="1" {if $settings['stripe_alipay']}selected{/if}>
                                                        启用
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">微信支付</label>
                                            <div class="col">
                                                <select id="stripe_wechat" class="field-input"
                                                        value="{$settings['stripe_wechat']}">
                                                    <option value="0">停用</option>
                                                    <option value="1" {if $settings['stripe_wechat']}selected{/if}>
                                                        启用
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">最低充值限额（整数）</label>
                                            <div class="col">
                                                <input id="stripe_min_recharge" type="text" class="field-input"
                                                       value="{$settings['stripe_min_recharge']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">最高充值限额（整数）</label>
                                            <div class="col">
                                                <input id="stripe_max_recharge" type="text" class="field-input"
                                                       value="{$settings['stripe_max_recharge']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Publishable Key</label>
                                            <div class="col">
                                                <input id="stripe_publishable_key" type="text" class="field-input"
                                                       value="{$settings['stripe_publishable_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Stripe 存卡自动扣费</label>
                                            <div class="col">
                                                <select id="stripe_auto_billing_enabled" class="field-input">
                                                    <option value="0">停用</option>
                                                    <option value="1" {if $settings['stripe_auto_billing_enabled']}selected{/if}>启用
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">余额自动续费</label>
                                            <div class="col">
                                                <select id="balance_auto_renew_enabled" class="field-input">
                                                    <option value="0">停用</option>
                                                    <option value="1" {if $settings['balance_auto_renew_enabled']}selected{/if}>启用
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">宽限期天数（整数）</label>
                                            <div class="col">
                                                <input id="stripe_grace_days" type="text" class="field-input"
                                                       value="{$settings['stripe_grace_days']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="epay" x-show="stab === 'epay'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">网关地址</label>
                                            <div class="col">
                                                <input id="epay_url" type="text" class="field-input"
                                                       value="{$settings['epay_url']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">商户ID</label>
                                            <div class="col">
                                                <input id="epay_pid" type="text" class="field-input"
                                                       value="{$settings['epay_pid']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">商户Key</label>
                                            <div class="col">
                                                <input id="epay_key" type="text" class="field-input"
                                                       value="{$settings['epay_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">签名方式</label>
                                            <div class="col">
                                                <input id="epay_sign_type" type="text" class="field-input"
                                                       value="{$settings['epay_sign_type']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">支付宝</label>
                                            <div class="col">
                                                <select id="epay_alipay" class="field-input"
                                                        value="{$settings['epay_alipay']}">
                                                    <option value="0">停用</option>
                                                    <option value="1" {if $settings['epay_alipay']}selected{/if}>启用
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">微信支付</label>
                                            <div class="col">
                                                <select id="epay_wechat" class="field-input"
                                                        value="{$settings['epay_wechat']}">
                                                    <option value="0">停用</option>
                                                    <option value="1" {if $settings['epay_wechat']}selected{/if}>启用
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">QQ钱包</label>
                                            <div class="col">
                                                <select id="epay_qq" class="field-input"
                                                        value="{$settings['epay_qq']}">
                                                    <option value="0">停用</option>
                                                    <option value="1" {if $settings['epay_qq']}selected{/if}>启用
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">USDT</label>
                                            <div class="col">
                                                <select id="epay_usdt" class="field-input"
                                                        value="{$settings['epay_usdt']}">
                                                    <option value="0">停用</option>
                                                    <option value="1" {if $settings['epay_usdt']}selected{/if}>启用
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="paypal" x-show="stab === 'paypal'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Mode</label>
                                            <div class="col">
                                                <select id="paypal_mode" class="field-input"
                                                        value="{$settings['paypal_mode']}">
                                                    <option value="sandbox">Sandbox</option>
                                                    <option value="live"
                                                            {if $settings['paypal_mode'] === 'live'}selected{/if}>Live
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Client ID</label>
                                            <div class="col">
                                                <input id="paypal_client_id" type="text" class="field-input"
                                                       value="{$settings['paypal_client_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Client Secret</label>
                                            <div class="col">
                                                <input id="paypal_client_secret" type="text" class="field-input"
                                                       value="{$settings['paypal_client_secret']}">
                                            </div>
                                            <div class="col-auto">
                                                <button class="btn btn-primary"
                                                        hx-post="/admin/setting/billing/set_paypal_webhook" hx-swap="none"
                                                        hx-vals='js:{
                                                            paypal_client_id: document.getElementById("paypal_client_id").value,
                                                            paypal_client_secret: document.getElementById("paypal_client_secret").value,
                                                        }'>
                                                    Set Webhook
                                                </button>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Currency</label>
                                            <div class="col">
                                                <input id="paypal_currency" type="text" class="field-input"
                                                       value="{$settings['paypal_currency']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Language</label>
                                            <div class="col">
                                                <input id="paypal_locale" type="text" class="field-input"
                                                       value="{$settings['paypal_locale']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="smogate" x-show="stab === 'smogate'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">App ID</label>
                                            <div class="col">
                                                <input id="smogate_app_id" type="text" class="field-input"
                                                       value="{$settings['smogate_app_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">App Secret</label>
                                            <div class="col">
                                                <input id="smogate_app_secret" type="text" class="field-input"
                                                       value="{$settings['smogate_app_secret']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="cryptomus" x-show="stab === 'cryptomus'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Api key</label>
                                            <div class="col">
                                                <input id="cryptomus_api_key" type="password" class="field-input"
                                                       value="{$settings['cryptomus_api_key']}">
                                                <span>You can find the API key in the settings of your personal account.</span>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">UUID</label>
                                            <div class="col">
                                                <input id="cryptomus_uuid" type="text" class="field-input"
                                                       value="{$settings['cryptomus_uuid']}">
                                                <span>You can find the UUID in the settings of your personal account.</span>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Subtract</label>
                                            <div class="col">
                                                <input id="cryptomus_subtract" type="number" class="field-input"
                                                       value="{$settings['cryptomus_subtract']}">
                                                <span>How much commission does the client pay (0-100%)</span>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Lifetime</label>
                                            <div class="col">
                                                <input id="cryptomus_lifetime" type="number" class="field-input"
                                                       value="{$settings['cryptomus_lifetime']}">
                                                <span>The lifespan of the issued invoice.(In seconds)</span>
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Currency</label>
                                            <div class="col">
                                                <input id="cryptomus_currency" type="text" class="field-input"
                                                       value="{$settings['cryptomus_currency']}">
                                                <span>The fiat currency invoices are issued in (e.g. CNY, USD).</span>
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
