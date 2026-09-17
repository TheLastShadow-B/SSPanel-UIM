/* Refresh probe colors in place (正常 / 波动 / 中断 only); the list also remembers which regions the user collapsed. */
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
            document.getElementById('probe-refresh-error').hidden = Date.now() - lastSuccess <= 180000;
        }, 30000);
        return;
    }

    /* ---- 列表页 ---- */
    const SHORT = { green: '正常', yellow: '波动', red: '中断' };

    window.cafeNodeList = () => ({
        collapsed: [],
        init() {
            try {
                const regions = JSON.parse(localStorage.getItem('cafe.nodeRegions') || '[]');
                if (Array.isArray(regions)) this.collapsed = regions.filter(code => typeof code === 'string');
            } catch (_) { /* storage unavailable: everything stays open */ }
        },
        isOpen(code) { return !this.collapsed.includes(code); },
        toggle(code) {
            this.collapsed = this.isOpen(code) ? [...this.collapsed, code] : this.collapsed.filter(c => c !== code);
            try { localStorage.setItem('cafe.nodeRegions', JSON.stringify(this.collapsed)); } catch (_) { /* ignore */ }
        },
        reveal(code) { if (!this.isOpen(code)) this.toggle(code); },
    });

    function paint(data) {
        document.querySelectorAll('[data-probe-node]').forEach(node => {
            node.querySelectorAll('[data-probe-carrier]').forEach(cell => {
                const result = data?.[node.dataset.probeNode]?.[cell.dataset.probeCarrier];
                const status = ['green', 'yellow', 'red'].includes(result?.status) ? result.status : 'red';
                const label = result?.label || '连接中断';
                cell.dataset.status = status;
                cell.title = cell.dataset.probeName + '：' + label;
                const tag = cell.querySelector('[data-probe-label]');
                tag.dataset.status = status;
                tag.textContent = SHORT[status];
                cell.querySelector('[data-probe-sr]').textContent = '：' + label;
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
            /* keep the last successful reading */
        } finally {
            pending = false;
        }
    }
    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
