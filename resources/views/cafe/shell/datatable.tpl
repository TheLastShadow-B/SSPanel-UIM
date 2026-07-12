{* Alpine 表格组件。
   cafeTable(url, key):一次性拉取 JSON,本地搜索 + 分页。
   cafeServerTable(url, key, columns):DataTables 服务端分页协议(draw/start/length/search)。 *}
{literal}
<script>
    function cafeServerTable(url, dataKey, columns) {
        return {
            rows: [],
            loading: true,
            search: '',
            page: 1,
            perPage: 15,
            filtered: 0,
            draw: 0,
            sortCol: 0,
            sortDir: 'desc',
            _timer: null,
            init() { this.fetchPage(); },
            get pageCount() {
                return Math.max(1, Math.ceil(this.filtered / this.perPage));
            },
            get paged() { return this.rows; },
            onSearch() {
                clearTimeout(this._timer);
                const self = this;
                this._timer = setTimeout(function () { self.page = 1; self.fetchPage(); }, 350);
            },
            sortBy(idx) {
                if (this.sortCol === idx) {
                    this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.sortCol = idx;
                    this.sortDir = 'desc';
                }
                this.fetchPage();
            },
            prev() { if (this.page > 1) { this.page--; this.fetchPage(); } },
            next() { if (this.page < this.pageCount) { this.page++; this.fetchPage(); } },
            async fetchPage() {
                this.loading = true;
                const body = new URLSearchParams();
                body.set('draw', String(++this.draw));
                body.set('start', String((this.page - 1) * this.perPage));
                body.set('length', String(this.perPage));
                body.set('search[value]', this.search.trim());
                body.set('order[0][column]', String(this.sortCol));
                body.set('order[0][dir]', this.sortDir);
                columns.forEach(function (c, i) { body.set('columns[' + i + '][data]', c); });
                try {
                    const resp = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                        body: body
                    });
                    const json = await resp.json();
                    // 后端可能返回 Laravel 分页器对象(行在 .data),也可能是数组
                    const raw = json[dataKey];
                    this.rows = Array.isArray(raw) ? raw : ((raw && raw.data) || []);
                    this.filtered = json.recordsFiltered || 0;
                } catch (e) {
                    console.error('表格数据加载失败:', e);
                    showToast('数据加载失败', 'danger');
                }
                this.loading = false;
            }
        };
    }
</script>
{/literal}
{literal}
<script>
    function cafeTable(url, dataKey) {
        return {
            rows: [],
            loading: true,
            search: '',
            page: 1,
            perPage: 10,
            async init() {
                try {
                    const resp = await fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const json = await resp.json();
                    this.rows = json[dataKey] || [];
                } catch (e) {
                    console.error('表格数据加载失败:', e);
                    showToast('数据加载失败', 'danger');
                }
                this.loading = false;
            },
            get filtered() {
                if (this.search.trim() === '') return this.rows;
                const q = this.search.trim().toLowerCase();
                return this.rows.filter(function (r) {
                    return Object.values(r).some(function (v) {
                        return v !== null && String(v).toLowerCase().includes(q);
                    });
                });
            },
            get pageCount() {
                return Math.max(1, Math.ceil(this.filtered.length / this.perPage));
            },
            get paged() {
                const p = Math.min(this.page, this.pageCount);
                return this.filtered.slice((p - 1) * this.perPage, p * this.perPage);
            },
            prev() { if (this.page > 1) this.page--; },
            next() { if (this.page < this.pageCount) this.page++; },
            // 管理端行删除:确认 → DELETE → toast → 本地移除该行
            async destroy(url, msg, id) {
                if (!confirm(msg || '确认删除？')) return;
                try {
                    const resp = await fetch(url, { method: 'DELETE', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const json = await resp.json();
                    showToast(json.msg, json.ret === 1 ? 'success' : 'danger');
                    if (json.ret === 1) this.rows = this.rows.filter(function (r) { return r.id !== id; });
                } catch (e) {
                    showToast('请求失败', 'danger');
                }
            },
            // 管理端行操作:确认(可选)→ POST → toast → 重新拉取
            async action(url, msg) {
                if (msg && !confirm(msg)) return;
                try {
                    const resp = await fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const json = await resp.json();
                    showToast(json.msg, json.ret === 1 ? 'success' : 'danger');
                    if (json.ret === 1) { this.loading = true; this.rows = []; await this.init(); }
                } catch (e) {
                    showToast('请求失败', 'danger');
                }
            },
            // 状态徽章配色启发式
            badgeClass(text) {
                const s = String(text);
                if (s.includes('待') || s.includes('处理中')) return 'badge-warning';
                if (s.includes('已支付') || s.includes('完成') || s.includes('激活') || s.includes('有效')) return 'badge-success';
                if (s.includes('取消') || s.includes('失效') || s.includes('过期') || s.includes('退款')) return 'badge-neutral';
                return 'badge-neutral';
            }
        };
    }
</script>
{/literal}
