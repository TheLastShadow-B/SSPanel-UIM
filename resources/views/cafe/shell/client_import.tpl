{* 客户端导入 Alpine 组件(共享):按 UA 推荐 + 全平台数据
   使用前需注入 window.CAFE_SUB / CAFE_CLIENTS / CAFE_ICONS / CAFE_R2 *}
{literal}
<script>
    function clientImport() {
        return {
            data: window.CAFE_CLIENTS || {},
            icons: window.CAFE_ICONS || {},
            open: null,
            os: (function () {
                const ua = navigator.userAgent;
                if (ua.match(/iPhone|iPad|iPod/i)) return 'iOS';
                if (ua.indexOf('Android') !== -1) return 'Android';
                if (ua.indexOf('Mac') !== -1) return 'macOS';
                if (ua.indexOf('Linux') !== -1) return 'Linux';
                return 'Windows';
            })(),
            get recommended() {
                return this.data[this.os] || this.data['Windows'] || [];
            },
            get platforms() {
                return Object.keys(this.data);
            },
            subUrl(c) {
                return window.CAFE_SUB + '/' + c.format;
            },
            dlUrl(c) {
                let u = c.downloadUrl;
                if (!c.isAppStore && u && u.includes('/clients/') && window.CAFE_R2) {
                    u = '/user' + u;
                }
                return u;
            }
        };
    }
</script>
{/literal}
