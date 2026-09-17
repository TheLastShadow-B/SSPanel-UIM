/* Refresh current colors without closing expanded node groups. */
(() => {
    let lastSuccess = Date.now();
    let pending = false;
    const detail = document.getElementById('node-probe-status');
    if (detail) {
        let opened = [];
        detail.addEventListener('htmx:beforeSwap', event => {
            if (event.detail.target !== detail) return;
            opened = [...detail.querySelectorAll('details[open]')].map(el => el.dataset.carrier);
        });
        detail.addEventListener('htmx:afterSwap', event => {
            if (event.detail.target !== detail) return;
            lastSuccess = Date.now();
            document.getElementById('probe-refresh-error').hidden = true;
            detail.querySelectorAll('details').forEach(el => { el.open = opened.includes(el.dataset.carrier); });
        });
        setInterval(() => {
            if (Date.now() - lastSuccess <= 180000) return;
            document.getElementById('probe-refresh-error').hidden = false;
            detail.querySelectorAll('.probe-state-icon').forEach(el => {
                el.dataset.status = 'gray';
                el.setAttribute('aria-label', '暂无有效数据');
                el.querySelector('span').textContent = '−';
            });
            detail.querySelectorAll('.probe-components .probe-dot').forEach(el => { el.dataset.status = 'gray'; });
            detail.querySelectorAll('[data-target-state]').forEach(el => { el.textContent = '暂无有效数据'; });
            detail.querySelectorAll('[data-target-metric]').forEach(el => { el.textContent = '—'; });
        }, 30000);
        return;
    }
    function paint(data) {
        document.querySelectorAll('[data-probe-node]').forEach(node => {
            node.querySelectorAll('[data-probe-carrier]').forEach(carrier => {
                const result = data?.[node.dataset.probeNode]?.[carrier.dataset.probeCarrier];
                const status = ['green', 'yellow', 'red', 'gray'].includes(result?.status) ? result.status : 'gray';
                carrier.querySelector('.probe-dot').dataset.status = status;
                const label = result?.label || '暂无有效数据';
                carrier.title = carrier.textContent.split('：')[0].trim() + '：' + label;
                carrier.querySelector('.sr-only').textContent = '：' + label;
            });
        });
    }
    async function refresh() {
        if (pending || document.hidden) return;
        pending = true;
        try {
            const response = await fetch('/user/server/status', { credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(10000) });
            if (!response.ok) throw new Error('Status unavailable');
            const body = await response.json();
            if (body.ret !== 1) throw new Error('Status unavailable');
            paint(body.data);
            lastSuccess = Date.now();
        } catch (_) {
            if (Date.now() - lastSuccess > 180000) paint(null);
        } finally {
            pending = false;
        }
    }
    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
