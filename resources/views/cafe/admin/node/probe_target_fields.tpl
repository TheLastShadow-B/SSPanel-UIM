{* 目标抽屉表单域:值绑定在外层 probeTargets() 的 form 上,新建与编辑共用 *}
<div class="space-y-4">
    <div>
        <span class="field-label">运营商</span>
        <div class="bg-tile flex gap-0.5 rounded-full p-1" role="group" aria-label="运营商">
            {foreach $carrier_codes as $key => $code}
                <button type="button" class="probe-seg flex-1" @click="form.carrier = '{$key}'"
                        :class="form.carrier === '{$key}' ? 'is-on' : ''" :aria-pressed="form.carrier === '{$key}'">
                    {$carriers[$key]} {$code}
                </button>
            {/foreach}
        </div>
        <input type="hidden" name="carrier" x-model="form.carrier">
        <p class="text-faint mt-2 text-xs leading-relaxed">请确认该地址实际所属运营商，否则三网判断会失真。</p>
    </div>

    <div>
        <label class="field-label" for="probe-target-label">地区 / 名称</label>
        <input id="probe-target-label" class="field-input" name="label" x-model="form.label" maxlength="80" placeholder="例如：广东电信" required>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_7rem]">
        <div>
            <label class="field-label" for="probe-target-ip">公网 IPv4</label>
            <input id="probe-target-ip" class="field-input tabular-nums" name="ip" x-model="form.ip" placeholder="填写公网 IPv4" autocomplete="off" required>
        </div>
        <div>
            <label class="field-label" for="probe-target-port">TCP 端口</label>
            <input id="probe-target-port" class="field-input tabular-nums" name="port" x-model="form.port" type="number" min="1" max="65535" required>
        </div>
    </div>

    <p class="text-faint flex items-start gap-2 text-xs leading-relaxed">
        <i class="ti ti-info-circle mt-0.5 shrink-0 text-sm" aria-hidden="true"></i>
        <span>目标需开放对应 TCP 端口。手动目标不会被 TaierSpeedtest 同步覆盖；与自动目标地址重复时，自动同步会跳过该地址。</span>
    </p>
</div>
