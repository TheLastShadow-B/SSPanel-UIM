{include file='shell/admin_header.tpl' nav='setting'}

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight">LLM 设置</h2>
        <p class="text-faint mt-1 text-sm">设置站点的大型语言模型服务</p>
    </div>
    <button id="save-setting" class="btn-primary btn-sm"
            hx-post="/admin/setting/llm" hx-swap="none"
            hx-vals='js:{
                {foreach $update_field as $key}
                {$key}: document.getElementById("{$key}").value,
                {/foreach}

            }'>
        <i class="ti ti-device-floppy"></i> 保存
    </button>
</div>

{include file='shell/admin_setting_tabs.tpl' tab='llm'}
<div x-data="{ stab: 'backend' }">
<div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="c-card-pad">
                        <div class="mb-4">
                            <div class="pill-tabs">
        <button class="pill-tab" :class="stab === 'backend' && 'active'" @click="stab = 'backend'">设置</button>
        <button class="pill-tab" :class="stab === 'openai' && 'active'" @click="stab = 'openai'">OpenAI</button>
        <button class="pill-tab" :class="stab === 'google-ai' && 'active'" @click="stab = 'google-ai'">Google AI</button>
        <button class="pill-tab" :class="stab === 'vertex-ai' && 'active'" @click="stab = 'vertex-ai'">Vertex AI</button>
        <button class="pill-tab" :class="stab === 'huggingface' && 'active'" @click="stab = 'huggingface'">Hugging Face</button>
        <button class="pill-tab" :class="stab === 'cf-workers-ai' && 'active'" @click="stab = 'cf-workers-ai'">Cloudflare Workers AI</button>
        <button class="pill-tab" :class="stab === 'anthropic' && 'active'" @click="stab = 'anthropic'">Anthropic</button>
        <button class="pill-tab" :class="stab === 'aws-bedrock' && 'active'" @click="stab = 'aws-bedrock'">AWS Bedrock</button>
    </div>
                        </div>
                        <div class="">
                            <div class="tab-content">
                                <div id="backend" x-show="stab === 'backend'" >
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Backend</label>
                                            <div class="col">
                                                <select id="llm_backend" class="field-input"
                                                        value="{$settings['llm_backend']}">
                                                    <option value=""
                                                            {if $settings['llm_backend'] === ""}selected{/if}>
                                                        None
                                                    </option>
                                                    <option value="openai"
                                                            {if $settings['llm_backend'] === "openai"}selected{/if}>
                                                        OpenAI
                                                    </option>
                                                    <option value="google-ai"
                                                            {if $settings['llm_backend'] === "google-ai"}selected{/if}>
                                                        Google AI
                                                    </option>
                                                    <option value="vertex-ai"
                                                            {if $settings['llm_backend'] === "vertex-ai"}selected{/if}>
                                                        Vertex AI
                                                    </option>
                                                    <option value="huggingface"
                                                            {if $settings['llm_backend'] === "huggingface"}selected{/if}>
                                                        Hugging Face
                                                    </option>
                                                    <option value="cf-workers-ai"
                                                            {if $settings['llm_backend'] === "cf-workers-ai"}selected{/if}>
                                                        Cloudflare Workers AI
                                                    </option>
                                                    <option value="anthropic"
                                                            {if $settings['llm_backend'] === "anthropic"}selected{/if}>
                                                        Anthropic
                                                    </option>
                                                    <option value="aws-bedrock"
                                                            {if $settings['llm_backend'] === "aws-bedrock"}selected{/if}>
                                                        AWS Bedrock
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="openai" x-show="stab === 'openai'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">API Key</label>
                                            <div class="col">
                                                <input id="openai_api_key" type="text" class="field-input"
                                                       value="{$settings['openai_api_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Model ID</label>
                                            <div class="col">
                                                <input id="openai_model_id" type="text" class="field-input"
                                                       value="{$settings['openai_model_id']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="google-ai" x-show="stab === 'google-ai'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">API Key</label>
                                            <div class="col">
                                                <input id="google_ai_api_key" type="text" class="field-input"
                                                       value="{$settings['google_ai_api_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Model ID</label>
                                            <div class="col">
                                                <input id="google_ai_model_id" type="text" class="field-input"
                                                       value="{$settings['google_ai_model_id']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="vertex-ai" x-show="stab === 'vertex-ai'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Access Token</label>
                                            <div class="col">
                                                <input id="vertex_ai_access_token" type="text" class="field-input"
                                                       value="{$settings['vertex_ai_access_token']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Location</label>
                                            <div class="col">
                                                <input id="vertex_ai_location" type="text" class="field-input"
                                                       value="{$settings['vertex_ai_location']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Project ID</label>
                                            <div class="col">
                                                <input id="vertex_ai_project_id" type="text" class="field-input"
                                                       value="{$settings['vertex_ai_project_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Model ID</label>
                                            <div class="col">
                                                <input id="vertex_ai_model_id" type="text" class="field-input"
                                                       value="{$settings['vertex_ai_model_id']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="huggingface" x-show="stab === 'huggingface'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">API Key</label>
                                            <div class="col">
                                                <input id="huggingface_api_key" type="text" class="field-input"
                                                       value="{$settings['huggingface_api_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Endpoint URL</label>
                                            <div class="col">
                                                <input id="huggingface_endpoint_url" type="text" class="field-input"
                                                       value="{$settings['huggingface_endpoint_url']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="cf-workers-ai" x-show="stab === 'cf-workers-ai'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Account ID</label>
                                            <div class="col">
                                                <input id="cf_workers_ai_account_id" type="text" class="field-input"
                                                       value="{$settings['cf_workers_ai_account_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">API Token</label>
                                            <div class="col">
                                                <input id="cf_workers_ai_api_token" type="text" class="field-input"
                                                       value="{$settings['cf_workers_ai_api_token']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Model ID</label>
                                            <div class="col">
                                                <input id="cf_workers_ai_model_id" type="text" class="field-input"
                                                       value="{$settings['cf_workers_ai_model_id']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="anthropic" x-show="stab === 'anthropic'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">API Key</label>
                                            <div class="col">
                                                <input id="anthropic_api_key" type="text" class="field-input"
                                                       value="{$settings['anthropic_api_key']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Model ID</label>
                                            <div class="col">
                                                <input id="anthropic_model_id" type="text" class="field-input"
                                                       value="{$settings['anthropic_model_id']}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="aws-bedrock" x-show="stab === 'aws-bedrock'" x-cloak>
                                    <div class="">
                                        <div class="form-row">
                                            <label class="">Access Key ID</label>
                                            <div class="col">
                                                <input id="aws_bedrock_access_key_id" type="text" class="field-input"
                                                       value="{$settings['aws_bedrock_access_key_id']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Access Key Secret</label>
                                            <div class="col">
                                                <input id="aws_bedrock_access_key_secret" type="text" class="field-input"
                                                       value="{$settings['aws_bedrock_access_key_secret']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Region</label>
                                            <div class="col">
                                                <input id="aws_bedrock_region" type="text" class="field-input"
                                                       value="{$settings['aws_bedrock_region']}">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <label class="">Model ID</label>
                                            <div class="col">
                                                <input id="aws_bedrock_model_id" type="text" class="field-input"
                                                       value="{$settings['aws_bedrock_model_id']}">
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
