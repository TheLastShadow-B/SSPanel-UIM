{include file='shell/admin_header.tpl' nav='system'}

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">系统信息</h2>
    <p class="text-faint mt-1 text-sm">查看系统的运行状态</p>
</div>

<div class="c-card-pad max-w-3xl">
    <div class="kv-row">
        <span class="kv-key">SSPanel-UIM 版本</span>
        <button id="version_check" class="value-pill hover:border-primary hover:text-primary cursor-pointer transition-colors"
                title="点击检查更新">{$version}</button>
    </div>
    <div class="kv-row">
        <span class="kv-key">数据库版本</span>
        <span class="value-pill">{$db_version}</span>
    </div>
    <div class="kv-row">
        <span class="kv-key">最后一次每日任务执行时间</span>
        <span class="kv-val">{$last_daily_job_time}</span>
    </div>
</div>

{literal}
<script>
    document.getElementById('version_check').addEventListener('click', function () {
        fetch('/admin/system/check_update', { method: 'POST' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.is_upto_date) {
                    showToast('当前已是最新版本', 'success');
                } else {
                    showToast('有新版本可用：' + (data.latest_version || ''), 'danger');
                }
            })
            .catch(function () { showToast('检查更新失败', 'danger'); });
    });
</script>
{/literal}

{include file='shell/admin_footer.tpl'}
