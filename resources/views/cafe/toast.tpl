{* toast 通知 + htmx JSON 协议处理(cafe 主题共享部件) *}
<div id="toast-stack" class="pointer-events-none fixed right-4 bottom-4 z-50 flex flex-col gap-2"></div>

{literal}
<script>
    function showToast(msg, type) {
        const stack = document.getElementById('toast-stack');
        const el = document.createElement('div');
        el.className = 'toast';
        const ico = type === 'success' ? 'ti-circle-check text-success' : 'ti-circle-x text-danger';
        el.innerHTML = '<i class="ti ' + ico + ' text-lg"></i><span></span>';
        el.querySelector('span').textContent = msg;
        stack.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s, transform .3s';
            el.style.opacity = '0';
            el.style.transform = 'translateY(6px)';
            setTimeout(function () { el.remove(); }, 300);
        }, 3200);
    }

    // 复制按钮:.copy + data-clipboard-text
    if (typeof ClipboardJS !== 'undefined') {
        new ClipboardJS('.copy').on('success', function () {
            showToast('已复制到剪贴板', 'success');
        }).on('error', function (e) {
            const text = e.trigger.getAttribute('data-clipboard-text');
            if (text) prompt('请手动复制以下内容：', text);
        });
    }

    // 后端 JSON 协议:{ret, msg, data:{elementId: value}}
    htmx.on('htmx:afterRequest', function (evt) {
        const xhr = evt.detail.xhr;
        if (xhr.getResponseHeader('HX-Refresh') === 'true' ||
            xhr.getResponseHeader('HX-Trigger') ||
            xhr.getResponseHeader('HX-Redirect')) {
            return;
        }

        const contentType = xhr.getResponseHeader('Content-Type') || '';
        const responseText = (xhr.responseText || '').trim();
        if (responseText === '' || !contentType.includes('application/json')) return;

        try {
            const res = JSON.parse(responseText);

            if (typeof res.data !== 'undefined') {
                for (const key in res.data) {
                    if (!Object.prototype.hasOwnProperty.call(res.data, key)) continue;

                    if (key === 'last-checkin-time') {
                        const btn = document.getElementById('check-in');
                        if (btn) { btn.textContent = '已签到'; btn.disabled = true; }
                    }

                    const el = document.getElementById(key);
                    if (el) {
                        if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                            el.value = res.data[key];
                        } else {
                            el.textContent = res.data[key];
                        }
                    }
                }
            }

            showToast(res.msg, res.ret === 1 ? 'success' : 'danger');
        } catch (e) {
            console.error('Failed to parse HTMX response:', e);
            showToast('发生了意外错误', 'danger');
        }
    });
</script>
{/literal}
