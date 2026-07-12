{include file="shell/admin_header.tpl" nav='product'}

<a href="/admin/product" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回商品列表
</a>

<div x-data="{ ptype: 'subscription' }">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight">创建商品</h2>
            <p class="text-faint mt-1 text-sm">创建各类商品</p>
        </div>
        <button id="create-product" class="btn-primary btn-sm"
                hx-post="/admin/product" hx-swap="none"
                hx-vals='js:{
                    {foreach $update_field as $key}
                    {if $key !== 'billing_cycle_month' && $key !== 'billing_cycle_quarter' && $key !== 'billing_cycle_year'}
                    {$key}: document.getElementById("{$key}").value,
                    {/if}
                    {/foreach}
                    new_user_required: document.getElementById("new_user_required").checked,
                    billing_cycle_month: document.getElementById("billing_cycle_month").checked,
                    billing_cycle_quarter: document.getElementById("billing_cycle_quarter").checked,
                    billing_cycle_year: document.getElementById("billing_cycle_year").checked,
                }'>
            <i class="ti ti-device-floppy"></i> 保存
        </button>
    </div>

    <div class="grid items-start gap-5 lg:grid-cols-2">

        {* ============ 基础信息 ============ *}
        <div class="c-card-pad">
            <h3 class="mb-4 text-base">基础信息</h3>
            <div class="mb-3">
                <label class="field-label" for="name">名称 *</label>
                <input id="name" type="text" class="field-input" value="">
            </div>
            <div class="mb-3 grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="price">价格 *</label>
                    <input id="price" type="text" class="field-input" value="">
                </div>
                <div>
                    <label class="field-label" for="stock">库存（-1 不限制）*</label>
                    <input id="stock" type="text" class="field-input" value="">
                </div>
            </div>
            <div class="mb-3">
                <label class="field-label" for="status">销售状态</label>
                <select id="status" class="field-input">
                    <option value="1">正常</option>
                    <option value="0">下架</option>
                </select>
            </div>
            <div>
                <label class="field-label" for="type">类型</label>
                <select id="type" class="field-input" x-model="ptype">
                    <option value="subscription">订阅套餐</option>
                    <option value="bandwidth">流量包</option>
                    <option value="tabp">时间流量包(旧)</option>
                    <option value="time">时间包(旧)</option>
                </select>
            </div>
        </div>

        {* ============ 商品内容 ============ *}
        <div class="c-card-pad">
            <h3 class="mb-4 text-base">商品内容</h3>
            <div class="mb-3" x-show="ptype === 'time' || ptype === 'tabp'">
                <label class="field-label" for="time">商品时长 (天) *</label>
                <input id="time" type="text" class="field-input" value="">
            </div>
            <div class="mb-3" x-show="ptype !== 'bandwidth'">
                <label class="field-label" for="class">等级 *</label>
                <input id="class" type="text" class="field-input" value="">
            </div>
            <div class="mb-3" x-show="ptype === 'time' || ptype === 'tabp'">
                <label class="field-label" for="class_time">等级时长 (天) *</label>
                <input id="class_time" type="text" class="field-input" value="">
            </div>
            <div class="mb-3" x-show="ptype !== 'time'">
                <label class="field-label" for="bandwidth">可用流量 (GB) *</label>
                <input id="bandwidth" type="text" class="field-input" value="">
            </div>
            <div class="mb-3" x-show="ptype !== 'bandwidth'">
                <label class="field-label" for="node_group">用户分组 *</label>
                <input id="node_group" type="text" class="field-input" value="">
            </div>
            <div class="mb-3 grid grid-cols-2 gap-3" x-show="ptype !== 'bandwidth'">
                <div>
                    <label class="field-label" for="speed_limit">速率限制 (Mbps) *</label>
                    <input id="speed_limit" type="text" class="field-input" value="">
                </div>
                <div>
                    <label class="field-label" for="ip_limit">同时连接 IP 限制 *</label>
                    <input id="ip_limit" type="text" class="field-input" value="">
                </div>
            </div>

            <div class="border-hairline mt-4 border-t pt-4">
                <h4 class="text-ink mb-3 text-sm font-semibold">购买限制</h4>
                <div class="mb-3 grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label" for="class_required">用户等级要求</label>
                        <input id="class_required" type="text" class="field-input" value="">
                    </div>
                    <div>
                        <label class="field-label" for="node_group_required">用户所在的节点组</label>
                        <input id="node_group_required" type="text" class="field-input" value="">
                    </div>
                </div>
                <label class="flex cursor-pointer items-center justify-between gap-3">
                    <span class="text-body text-sm font-medium">仅限新用户购买</span>
                    <input id="new_user_required" type="checkbox" class="accent-primary size-4">
                </label>
            </div>

            <div class="border-hairline mt-4 border-t pt-4" x-show="ptype === 'subscription'">
                <h4 class="text-ink mb-3 text-sm font-semibold">订阅设置</h4>
                <div class="mb-3">
                    <label class="field-label">账单周期</label>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input id="billing_cycle_month" type="checkbox" class="accent-primary size-4" checked>
                            <span class="text-body">月付</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input id="billing_cycle_quarter" type="checkbox" class="accent-primary size-4">
                            <span class="text-body">季付</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input id="billing_cycle_year" type="checkbox" class="accent-primary size-4">
                            <span class="text-body">年付</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label" for="discount_quarter">季付折扣</label>
                        <input id="discount_quarter" type="text" class="field-input" value="1.0">
                    </div>
                    <div>
                        <label class="field-label" for="discount_year">年付折扣</label>
                        <input id="discount_year" type="text" class="field-input" value="1.0">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{include file="shell/admin_footer.tpl"}
