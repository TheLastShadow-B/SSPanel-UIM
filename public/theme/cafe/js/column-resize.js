/* Resizable columns for explicitly opted-in tables. No table data is stored. */
(() => {
    document.querySelectorAll('table[data-column-storage]').forEach(table => {
        if (table.dataset.columnsReady) return;
        const headers = Array.from(table.querySelectorAll('thead th'));
        let columns = Array.from(table.querySelectorAll('colgroup > col'));
        if (!columns.length) {
            const sizes = (table.dataset.columnWidths || '').split(',').map(Number);
            const limits = (table.dataset.columnMinimums || '').split(',').map(Number);
            const group = document.createElement('colgroup');
            columns = headers.map((header, index) => {
                const col = document.createElement('col');
                col.dataset.column = header.textContent.trim();
                col.dataset.width = String(sizes[index] || 180);
                col.dataset.min = String(limits[index] || 100);
                if (table.hasAttribute('data-fixed-last') && index === headers.length - 1) {
                    col.setAttribute('data-fixed', '');
                }
                group.appendChild(col);
                return col;
            });
            table.insertBefore(group, table.tHead);
        }
        if (columns.length !== headers.length || columns.length === 0) return;
        table.dataset.columnsReady = 'true';
        table.classList.add('cafe-resizable-table');
        if (columns[columns.length - 1].hasAttribute('data-fixed')) table.setAttribute('data-fixed-last', '');

        const storageKey = table.dataset.columnStorage;
        const maxWidth = 900;
        const baseWidths = columns.map(col => Number(col.dataset.width));
        const minimums = columns.map(col => Number(col.dataset.min));
        // Short tables fill their card on first use; explicit saved widths stay exact.
        function defaultWidths() {
            const result = baseWidths.slice();
            const total = result.reduce((sum, width) => sum + width, 0);
            const extra = Math.max(0, table.parentElement.clientWidth - total);
            const flexible = columns.map((col, i) => col.hasAttribute('data-fixed') ? -1 : i).filter(i => i >= 0);
            flexible.forEach(i => { result[i] += Math.floor(extra / flexible.length); });
            return result;
        }
        let defaults = defaultWidths();
        let widths = defaults.slice();
        let drag = null;
        const handles = [];

        try {
            const saved = JSON.parse(localStorage.getItem(storageKey));
            if (saved && typeof saved === 'object') {
                widths = columns.map((col, index) => {
                    const width = saved[col.dataset.column];
                    return !col.hasAttribute('data-fixed') && Number.isFinite(width)
                        ? Math.max(minimums[index], Math.min(maxWidth, width))
                        : defaults[index];
                });
            }
        } catch (_) { /* Storage may be unavailable; resizing still works. */ }

        function render() {
            columns.forEach((col, index) => {
                col.style.width = widths[index] + 'px';
                if (handles[index]) {
                    handles[index].setAttribute('aria-valuenow', String(widths[index]));
                    handles[index].setAttribute('aria-valuetext', widths[index] + ' 像素');
                }
            });
            table.style.width = widths.reduce((sum, width) => sum + width, 0) + 'px';
        }

        function save() {
            try {
                localStorage.setItem(storageKey, JSON.stringify(Object.fromEntries(
                    columns.map((col, index) => [col.dataset.column, widths[index]])
                )));
            } catch (_) { /* Private browsing / quota restrictions are non-fatal. */ }
        }

        function resize(index, width) {
            widths[index] = Math.round(Math.max(minimums[index], Math.min(maxWidth, width)));
            render();
        }

        function finish(cancelled = false) {
            if (!drag) return;
            const { handle, pointerId, index, startWidth } = drag;
            drag = null;
            if (cancelled) resize(index, startWidth);
            else save();
            handle.classList.remove('is-resizing');
            document.documentElement.classList.remove('cafe-column-dragging');
            if (handle.hasPointerCapture(pointerId)) handle.releasePointerCapture(pointerId);
        }

        columns.forEach((col, index) => {
            if (col.hasAttribute('data-fixed')) return;
            const handle = document.createElement('span');
            handle.className = 'cafe-column-handle';
            handle.tabIndex = 0;
            handle.setAttribute('role', 'separator');
            handle.setAttribute('aria-orientation', 'vertical');
            handle.setAttribute('aria-label', headers[index].textContent.trim() + '列宽');
            handle.setAttribute('aria-valuemin', String(minimums[index]));
            handle.setAttribute('aria-valuemax', String(maxWidth));
            handle.title = '拖动调整列宽，双击恢复；方向键微调';
            handles[index] = handle;
            headers[index].appendChild(handle);

            handle.addEventListener('pointerdown', event => {
                if (!event.isPrimary || event.button !== 0) return;
                event.preventDefault();
                finish();
                handle.focus({ preventScroll: true });
                drag = { index, handle, pointerId: event.pointerId, startX: event.clientX, startWidth: widths[index] };
                handle.setPointerCapture(event.pointerId);
                handle.classList.add('is-resizing');
                document.documentElement.classList.add('cafe-column-dragging');
            });
            handle.addEventListener('pointermove', event => {
                if (!drag || event.pointerId !== drag.pointerId) return;
                resize(drag.index, drag.startWidth + event.clientX - drag.startX);
            });
            handle.addEventListener('pointerup', event => {
                if (drag && event.pointerId === drag.pointerId) finish();
            });
            handle.addEventListener('pointercancel', () => finish(true));
            handle.addEventListener('lostpointercapture', () => finish());
            handle.addEventListener('dblclick', () => {
                resize(index, defaults[index]);
                save();
            });
            handle.addEventListener('keydown', event => {
                const step = event.shiftKey ? 40 : 10;
                const target = {
                    ArrowLeft: widths[index] - step,
                    ArrowRight: widths[index] + step,
                    Home: minimums[index],
                    End: maxWidth
                }[event.key];
                if (target === undefined) return;
                event.preventDefault();
                resize(index, target);
                save();
            });
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && drag) {
                event.preventDefault();
                finish(true);
            }
        });
        window.addEventListener('blur', () => finish());
        table.addEventListener('cafe:reset-columns', () => {
            finish(true);
            defaults = defaultWidths();
            widths = defaults.slice();
            render();
            try { localStorage.removeItem(storageKey); } catch (_) {}
        });
        const toolbar = document.createElement('div');
        toolbar.className = 'cafe-column-toolbar';
        const reset = document.createElement('button');
        reset.type = 'button';
        reset.className = 'btn-secondary btn-sm';
        reset.textContent = '重置列宽';
        reset.addEventListener('click', () => table.dispatchEvent(new Event('cafe:reset-columns')));
        toolbar.appendChild(reset);
        table.parentElement.before(toolbar);
        render();
    });
})();
