{* Alpine 客户端表格组件:一次性拉取 JSON,本地搜索 + 分页。
   用法:<div x-data="cafeTable('/user/order/ajax', 'orders')"> ... </div> *}
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
