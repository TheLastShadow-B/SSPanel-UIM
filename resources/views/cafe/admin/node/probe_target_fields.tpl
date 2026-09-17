<div class="grid gap-3 md:grid-cols-2">
    <label class="text-body text-xs">运营商
        <select class="field-input mt-1" name="carrier" required>
            {foreach $carriers as $carrier => $name}<option value="{$carrier}" {if $target['carrier'] === $carrier}selected{/if}>{$name}</option>{/foreach}
        </select>
    </label>
    <label class="text-body text-xs">地区 / 名称<input class="field-input mt-1" name="label" value="{$target['label']|escape}" maxlength="80" placeholder="例如：广东电信" required {if $is_new}x-ref="newLabel"{/if}></label>
    <label class="text-body text-xs">公网 IPv4<input class="field-input mt-1" name="ip" value="{$target['ip']|escape}" placeholder="填写公网 IPv4" autocomplete="off" required></label>
    <label class="text-body text-xs">TCP 端口<input class="field-input mt-1" name="port" value="{$target['port']}" type="number" min="1" max="65535" required></label>
</div>
