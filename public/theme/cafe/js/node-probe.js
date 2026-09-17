/* Refresh probe colors in place; the list also owns the carrier filter (Alpine state + row ordering). */
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

    /* ---- 列表页 ---- */
    const table = document.querySelector('.node-table');
    const SHORT = { green: '正常', yellow: '波动', red: '中断', gray: '无数据' };
    const RANK = { green: 0, yellow: 1, red: 2, gray: 3 };

    /* With a carrier picked, rows inside each region sort by that carrier's state; flex order keeps the DOM (and tab order) stable. */
    function sortRows() {
        const carrier = table?.dataset.carrier || '';
        document.querySelectorAll('.node-rows [data-probe-node]').forEach(row => {
            const cell = carrier ? row.querySelector(`[data-probe-carrier="${carrier}"]`) : null;
            row.style.order = cell ? String(RANK[cell.dataset.status] ?? 3) : '';
        });
    }

    window.cafeNodeList = () => ({
        picked: '',
        collapsed: [],
        names: { telecom: '电信', unicom: '联通', mobile: '移动' },
        init() {
            try {
                const saved = localStorage.getItem('cafe.nodeCarrier');
                if (saved && saved in this.names) this.picked = saved;
                const regions = JSON.parse(localStorage.getItem('cafe.nodeRegions') || '[]');
                if (Array.isArray(regions)) this.collapsed = regions.filter(code => typeof code === 'string');
            } catch (_) { /* storage unavailable: everything open, 全部 selected */ }
            this.$nextTick(sortRows);
        },
        isOpen(code) { return !this.collapsed.includes(code); },
        toggle(code) {
            this.collapsed = this.isOpen(code) ? [...this.collapsed, code] : this.collapsed.filter(c => c !== code);
            try { localStorage.setItem('cafe.nodeRegions', JSON.stringify(this.collapsed)); } catch (_) { /* ignore */ }
        },
        reveal(code) { if (!this.isOpen(code)) this.toggle(code); },
        select(carrier) {
            this.picked = carrier;
            try { localStorage.setItem('cafe.nodeCarrier', carrier); } catch (_) { /* ignore */ }
            this.$nextTick(sortRows);
        },
    });

    function paint(data) {
        const tally = {};
        document.querySelectorAll('[data-probe-node]').forEach(node => {
            node.querySelectorAll('[data-probe-carrier]').forEach(cell => {
                const carrier = cell.dataset.probeCarrier;
                const result = data?.[node.dataset.probeNode]?.[carrier];
                const status = ['green', 'yellow', 'red', 'gray'].includes(result?.status) ? result.status : 'gray';
                const label = result?.label || '暂无有效数据';
                cell.dataset.status = status;
                cell.title = cell.dataset.probeName + '：' + label;
                const tag = cell.querySelector('[data-probe-label]');
                tag.dataset.status = status;
                tag.textContent = SHORT[status];
                cell.querySelector('[data-probe-sr]').textContent = '：' + label;
                tally[carrier] ??= { green: 0, yellow: 0, red: 0, gray: 0 };
                tally[carrier][status]++;
            });
        });
        Object.entries(tally).forEach(([carrier, counts]) => Object.entries(counts).forEach(([status, n]) => {
            document.querySelectorAll(`[data-tally="${carrier}:${status}"]`).forEach(el => { el.textContent = n; });
            document.querySelectorAll(`[data-tally-item="${carrier}:${status}"]`).forEach(el => { el.hidden = n === 0; });
            document.querySelectorAll(`[data-bar="${carrier}:${status}"]`).forEach(el => { el.style.flexGrow = n; el.hidden = n === 0; });
        }));
        sortRows();
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
