{include file='user/header.tpl'}

<style>
.client-item:hover {
    border-color: var(--tblr-primary) !important;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.copy.copied {
    background-color: var(--tblr-success) !important;
    border-color: var(--tblr-success) !important;
}

.recommended-section {
    background: rgba(var(--tblr-primary-rgb), 0.1);
    border: 1px solid rgba(var(--tblr-primary-rgb), 0.2);
}

.client-item {
    transition: all 0.3s;
}

.client-item:hover {
    background: var(--tblr-bg-surface-secondary);
    transform: translateX(5px);
}

.user-stat-box {
    background: var(--tblr-bg-surface-secondary);
    border-radius: var(--tblr-border-radius);
    padding: 1rem;
    text-align: center;
    height: 100%;
}

.user-stat-box .stat-value {
    font-size: 1.25rem;
    font-weight: 600;
    line-height: 1.2;
    word-break: keep-all;
}

.user-stat-box .stat-label {
    font-size: 0.8125rem;
    color: var(--tblr-secondary);
    margin-top: 0.25rem;
}

.checkin-banner {
    background: var(--tblr-success-lt);
    border: 0;
}

@media (max-width: 576px) {
    .client-item:hover {
        transform: none;
    }

    .client-item .btn-group-vertical {
        margin-top: 0.5rem;
    }

    .recommended-section h4 {
        font-size: 1rem;
    }

    .copy button {
        word-break: keep-all;
        white-space: nowrap;
    }

    .btn-group-vertical .btn {
        padding: 0.75rem 1rem;
        font-size: 0.9rem;
        min-height: 44px;
    }

    .btn-group-vertical {
        gap: 0.5rem;
    }

    .client-item {
        padding: 1rem !important;
    }

    .recommended-section .card-body {
        padding: 1rem;
    }

    .recommended-section .btn-group {
        flex-wrap: wrap;
        gap: 0.5rem;
        justify-content: center;
    }

    .recommended-section .btn-group-vertical {
        align-items: stretch;
        width: 100%;
    }

    .recommended-section .btn {
        flex: 1 1 auto;
        min-width: 100px;
    }

    .user-stat-box .stat-value {
        font-size: 1rem;
    }
}

.accordion-button:not(.collapsed) {
    background: var(--tblr-primary-lt);
    color: var(--tblr-primary);
}

.spoiler {
    filter: blur(5px);
    transition: filter 0.3s;
}

.spoiler:hover {
    filter: none;
}
</style>

<div class="page-wrapper">
    <div class="container-xl">
        <div class="page-header d-print-none text-white">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">
                        <span class="home-title">用户中心</span>
                    </h2>
                    <div class="page-pretitle my-3">
                        <span class="home-subtitle">在这里查看账户信息和最新公告</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <div class="row row-cards row-deck">

                {* ============ 左上：快速配置 ============ *}
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-body">
                            {if $public_setting['enable_checkin']}
                            <div class="alert checkin-banner mb-3 d-flex align-items-center flex-wrap gap-2">
                                <div class="flex-fill">
                                    <div class="d-flex align-items-center">
                                        <i class="ti ti-gift icon text-green me-2"></i>
                                        <span>
                                            签到可领取
                                            {if $public_setting['checkin_min'] !== $public_setting['checkin_max']}
                                            <code>{$public_setting['checkin_min']}</code> -
                                            <code>{$public_setting['checkin_max']}</code> MB
                                            {else}
                                            <code>{$public_setting['checkin_min']} MB</code>
                                            {/if}
                                            流量
                                        </span>
                                    </div>
                                    <small class="text-secondary d-block mt-1">
                                        上次签到：<span id="last-checkin-time">{$user->lastCheckInTime()}</span>
                                    </small>
                                </div>
                                <div class="ms-auto">
                                    {if !$user->isAbleToCheckin()}
                                    <button id="check-in" class="btn btn-sm btn-success" disabled>已签到</button>
                                    {else}
                                    {if $public_setting['enable_checkin_captcha']}
                                    {include file='captcha/div.tpl'}
                                    {/if}
                                    <button id="check-in" class="btn btn-sm btn-success"
                                        hx-post="/user/checkin" hx-swap="none" hx-vals='js:{
                                        {if $public_setting['enable_checkin_captcha']}
                                        {include file='captcha/ajax.tpl'}
                                        {/if}
                                        }'>
                                        签到
                                    </button>
                                    {/if}
                                </div>
                            </div>
                            {/if}

                            <h3 class="card-title">快速配置</h3>

                            <div class="recommended-section p-3 bg-primary-lt rounded mb-3">
                                <h4 class="mb-3">
                                    <i class="ti ti-rocket"></i>
                                    为您推荐的 <span id="detected-os" class="text-primary">Windows</span> 客户端
                                </h4>
                                <div class="row g-3" id="recommended-clients"></div>
                            </div>

                            <div class="text-center">
                                <button class="btn btn-ghost-primary" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#all-platforms" aria-expanded="false">
                                    <i class="ti ti-package"></i>
                                    查看其他平台客户端
                                    <i class="ti ti-chevron-down ms-1"></i>
                                </button>
                            </div>

                            <div class="collapse mt-3" id="all-platforms">
                                <div class="accordion" id="platform-accordion"></div>
                            </div>
                        </div>
                    </div>
                </div>

                {* ============ 右上：流量用量（合并每小时用量） ============ *}
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title">流量用量</h3>
                            <div class="card-actions">
                                {if $user->class > 0}
                                <span class="badge bg-blue-lt">
                                    LV.{$user->class} · {$class_expire_days} 天到期
                                </span>
                                {else}
                                <a href="/user/product" class="btn btn-sm btn-primary">
                                    <i class="ti ti-shopping-cart me-1"></i>购买套餐
                                </a>
                                {/if}
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2 mb-3">
                                <div class="col-4">
                                    <div class="user-stat-box">
                                        <div class="stat-value">{$user->TodayusedTraffic()}</div>
                                        <div class="stat-label">今日用量</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="user-stat-box">
                                        <div class="stat-value">{$user->LastusedTraffic()}</div>
                                        <div class="stat-label">过去用量</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="user-stat-box">
                                        <div class="stat-value">{$user->unusedTraffic()}</div>
                                        <div class="stat-label">剩余流量</div>
                                    </div>
                                </div>
                            </div>

                            {if $public_setting['traffic_log']}
                            <div id="traffic-log" style="min-height: 260px"></div>
                            {else}
                            <div class="text-center text-secondary py-5">
                                <i class="ti ti-chart-line icon mb-2 d-block mx-auto"></i>
                                <div>每小时用量统计未启用</div>
                            </div>
                            {/if}
                        </div>
                    </div>
                </div>

                {* ============ 左下：置顶公告 ============ *}
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-bell-ringing text-yellow me-2"></i>
                                置顶公告
                            </h3>
                            {if $ann !== null}
                            <div class="card-actions">
                                <small class="text-secondary">{$ann->date}</small>
                            </div>
                            {/if}
                        </div>
                        <div class="card-body" style="max-height: 480px; overflow-y: auto;">
                            {if $ann !== null}
                            <div class="text-secondary">
                                {$ann->content}
                            </div>
                            {else}
                            <div class="empty py-4">
                                <div class="empty-icon">
                                    <i class="ti ti-bell-off icon"></i>
                                </div>
                                <p class="empty-title">暂无公告</p>
                            </div>
                            {/if}
                        </div>
                    </div>
                </div>

                {* ============ 右下：在线 IP 列表 ============ *}
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-device-desktop me-2"></i>
                                在线IP列表
                            </h3>
                            <div class="card-actions">
                                <small class="text-secondary">仅显示 5 分钟内活跃的连接</small>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            {if $online_ips && count($online_ips) > 0}
                            <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
                                <table class="table table-vcenter card-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>IP地址</th>
                                            <th>IP归属地</th>
                                            <th>节点</th>
                                            <th>最后在线</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {foreach $online_ips as $online_ip}
                                        <tr>
                                            <td><code>{$online_ip->formatted_ip}</code></td>
                                            <td><span class="text-secondary">{$online_ip->location}</span></td>
                                            <td><span class="badge bg-blue-lt">{$online_ip->node_name}</span></td>
                                            <td class="text-secondary">
                                                <span class="text-nowrap">{$online_ip->last_time|date_format:'Y-m-d H:i:s'}</span>
                                            </td>
                                        </tr>
                                        {/foreach}
                                    </tbody>
                                </table>
                            </div>
                            {else}
                            <div class="empty">
                                <div class="empty-icon">
                                    <i class="ti ti-mood-sad icon"></i>
                                </div>
                                <p class="empty-title">暂无在线连接</p>
                                <p class="empty-subtitle text-secondary">
                                    当您使用节点连接时，这里将显示最近 5 分钟内活跃的在线 IP
                                </p>
                            </div>
                            {/if}
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {if $public_setting['enable_checkin_captcha'] && $user->isAbleToCheckin()}
        {include file='captcha/js.tpl'}
    {/if}

    {if $public_setting['traffic_log']}
    <script src="//{$config['jsdelivr_url']}/npm/@tabler/core@latest/dist/libs/apexcharts/dist/apexcharts.min.js"></script>
    <script>
        function getTrafficChartConfig(trafficData, transferEnableMb) {
            var annotations = transferEnableMb > 0 ? {
                yaxis: [{
                    y: transferEnableMb,
                    borderColor: '#FF4500',
                    borderWidth: 1,
                    strokeDashArray: 6,
                    label: {
                        borderColor: '#FF4500',
                        style: { color: '#fff', background: '#FF4500', fontSize: '11px' },
                        text: '流量上限',
                        position: 'left',
                        offsetX: 60
                    }
                }]
            } : {};

            return {
                chart: {
                    type: "line",
                    fontFamily: "inherit",
                    height: 260,
                    parentHeightOffset: 0,
                    toolbar: { show: false },
                    animations: { enabled: false }
                },
                stroke: { curve: "smooth", width: 2 },
                fill: { opacity: 1 },
                series: [
                    { name: "当日用量（MB）", data: trafficData }
                ],
                annotations: annotations,
                tooltip: { theme: "dark" },
                grid: {
                    padding: { top: 0, right: 8, left: 8, bottom: 0 },
                    strokeDashArray: 4,
                    borderColor: 'rgba(127, 127, 127, 0.2)'
                },
                xaxis: {
                    labels: { padding: 0, style: { fontSize: '11px' } },
                    tooltip: { enabled: false },
                    axisBorder: { show: false },
                    categories: [
                        "00", "01", "02", "03", "04", "05", "06", "07", "08", "09", "10", "11",
                        "12", "13", "14", "15", "16", "17", "18", "19", "20", "21", "22", "23"
                    ]
                },
                yaxis: {
                    labels: {
                        padding: 8,
                        style: { fontSize: '11px' },
                        formatter: function(v) { return v.toFixed(0); }
                    }
                },
                colors: ["#FF4500"],
                legend: {
                    show: true,
                    position: 'bottom',
                    horizontalAlign: 'right',
                    fontSize: '12px',
                    markers: { width: 10, height: 10 }
                }
            };
        }

        function initTrafficChart() {
            var chartElement = document.getElementById('traffic-log');
            if (!chartElement || !window.ApexCharts) return;

            try {
                var transferEnableMb = (window.APP_CONFIG && window.APP_CONFIG.transferEnableBytes
                    ? window.APP_CONFIG.transferEnableBytes / 1048576 : 0);
                var chart = new ApexCharts(chartElement, getTrafficChartConfig({$traffic_logs}, transferEnableMb));
                chart.render();
            } catch (error) {
                console.error('流量图表初始化失败:', error);
            }
        }

        document.addEventListener("DOMContentLoaded", function () {
            initTrafficChart();
        });
    </script>
    {/if}

    <script>
    window.APP_CONFIG = {
        enableR2Download: {if $config['enable_r2_client_download']}true{else}false{/if},
        universalSubUrl: "{$UniversalSub}",
        appName: "{$config['appName']}",
        enableSsSub: {if $public_setting['enable_ss_sub']}true{else}false{/if},
        enableV2Sub: {if $public_setting['enable_v2_sub']}true{else}false{/if},
        enableTrojanSub: {if $public_setting['enable_trojan_sub']}true{else}false{/if},
        transferEnableBytes: {$user->transfer_enable}
    };

    const platformIcons = {$platformIcons};

    const clientRecommendations = {$clientData};

    {literal}
    function detectOS() {
        const userAgent = navigator.userAgent;
        if (userAgent.indexOf("Win") !== -1) return "Windows";
        if (userAgent.indexOf("Mac") !== -1) return "macOS";
        if (userAgent.indexOf("Android") !== -1) return "Android";
        if (userAgent.match(/iPhone|iPad|iPod/i)) return "iOS";
        if (userAgent.indexOf("Linux") !== -1) return "Linux";
        return "Windows"; // default
    }


    const CONFIG = {
        FEEDBACK_TIMEOUT: 2000,
        CLIPBOARD_SUCCESS_TEXT: '已复制',
        CLIPBOARD_ERROR_TEXT: '复制失败，请手动选择并复制',
        CLASSES: {
            BTN_GROUP_MOBILE: 'btn-group-vertical',
            BTN_GROUP_DESKTOP: 'btn-group btn-group-sm',
            MOBILE_ONLY: 'd-md-none w-100',
            DESKTOP_ONLY: 'd-none d-md-flex',
            MOBILE_SM: 'd-sm-none w-100',
            DESKTOP_SM: 'd-none d-sm-flex'
        },
        BUTTONS: {
            download: { icon: 'ti-download', text: '下载', class: 'btn-primary' },
            downloadAppStore: { icon: 'ti-brand-appstore', text: 'App Store', class: 'btn-primary' },
            copy: { icon: 'ti-copy', text: '复制订阅', class: 'btn-info copy' },
            import: { icon: 'ti-link', text: '一键导入', class: 'btn-success' },
            importRecommended: { icon: 'ti-rocket', text: '一键导入', class: 'btn-success' }
        }
    };

    function safeInit(fn, name) {
        try {
            fn();
        } catch (error) {
            console.error(`${name} 初始化失败:`, error);
        }
    }

    function createElement(tag, className, content) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (content) element.textContent = content;
        return element;
    }

    function createIcon(iconClass) {
        const icon = createElement('i', 'ti ' + iconClass);
        return icon;
    }

    function createButton(type, options = {}) {
        const { client, url, isMobile, isRecommended } = options;
        const btnConfig = CONFIG.BUTTONS[type];

        let config = { ...btnConfig };
        if (type === 'download' && client?.isAppStore) {
            config = CONFIG.BUTTONS.downloadAppStore;
        } else if (type === 'import' && isRecommended) {
            config = CONFIG.BUTTONS.importRecommended;
        }

        const btn = createElement(type === 'copy' ? 'button' : 'a', 'btn ' + config.class);

        if (type === 'copy') {
            btn.setAttribute('data-clipboard-text', url);
        } else {
            btn.href = url;
            if (type === 'download' && client?.isAppStore) {
                btn.target = '_blank';
            }
        }

        btn.appendChild(createIcon(config.icon));
        btn.appendChild(document.createTextNode(' ' + config.text));

        return btn;
    }

    function createResponsiveButtonGroups(client, urls, isRecommended = false) {
        const { downloadUrl, subUrl, importUrl } = urls;
        const buttons = [];

        const buttonConfigs = [
            { type: 'download', url: downloadUrl, needsClient: true },
            { type: 'copy', url: subUrl },
            { type: 'import', url: importUrl }
        ];

        const variants = [
            {
                isMobile: true,
                classes: isRecommended ?
                    `${CONFIG.CLASSES.BTN_GROUP_MOBILE} ${CONFIG.CLASSES.MOBILE_ONLY}` :
                    `${CONFIG.CLASSES.BTN_GROUP_MOBILE} ${CONFIG.CLASSES.MOBILE_SM}`
            },
            {
                isMobile: false,
                classes: isRecommended ?
                    `${CONFIG.CLASSES.BTN_GROUP_DESKTOP.replace('btn-group-sm', '')} ${CONFIG.CLASSES.DESKTOP_ONLY}` :
                    `${CONFIG.CLASSES.BTN_GROUP_DESKTOP} ${CONFIG.CLASSES.DESKTOP_SM}`
            }
        ];

        variants.forEach(variant => {
            const group = createElement('div', variant.classes);

            buttonConfigs.forEach(btnConfig => {
                const options = {
                    client: btnConfig.needsClient ? client : null,
                    url: btnConfig.url,
                    isMobile: variant.isMobile,
                    isRecommended
                };
                group.appendChild(createButton(btnConfig.type, options));
            });

            buttons.push(group);
        });

        return buttons;
    }

    function createClientCardContent(client) {
        const content = createElement('div');

        const title = createElement('h4', 'mb-1', client.name);
        const desc = createElement('p', 'text-secondary mb-0', client.description);

        content.appendChild(title);
        content.appendChild(desc);

        return content;
    }

    function generateClientHtml(client, isRecommended) {
        const config = window.APP_CONFIG;

        let downloadUrl = client.downloadUrl;
        if (!client.isAppStore && downloadUrl.includes('/clients/')) {
            downloadUrl = config.enableR2Download ? '/user' + downloadUrl : downloadUrl;
        }

        const subUrl = config.universalSubUrl + '/' + client.format;
        const importUrl = client.importUrl;

        const container = createElement('div', 'col-12');

        if (isRecommended) {
            const card = createElement('div', 'card');
            const cardBody = createElement('div', 'card-body');
            const flexContainer = createElement('div', 'd-flex flex-column flex-md-row align-items-center justify-content-between gap-3');

            const contentDiv = createClientCardContent(client);

            const buttonsContainer = createElement('div');
            const urls = { downloadUrl, subUrl, importUrl };
            const buttonGroups = createResponsiveButtonGroups(client, urls, true);
            buttonGroups.forEach(group => buttonsContainer.appendChild(group));

            flexContainer.appendChild(contentDiv);
            flexContainer.appendChild(buttonsContainer);
            cardBody.appendChild(flexContainer);
            card.appendChild(cardBody);
            container.appendChild(card);
        } else {
            const item = createElement('div', 'client-item d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between p-3 border rounded gap-2');

            const contentDiv = createElement('div', 'flex-fill');
            const title = createElement('h5', 'mb-0', client.name);
            const desc = createElement('small', 'text-muted', client.description);
            contentDiv.appendChild(title);
            contentDiv.appendChild(desc);

            const urls = { downloadUrl, subUrl, importUrl };
            const buttonGroups = createResponsiveButtonGroups(client, urls, false);

            item.appendChild(contentDiv);
            buttonGroups.forEach(group => item.appendChild(group));

            container.appendChild(item);
        }

        return container.outerHTML;
    }

    function initClientSelector() {
        const os = detectOS();
        document.getElementById('detected-os').textContent = os;

        const recommendations = clientRecommendations[os] || clientRecommendations["Windows"];
        const recommendedContainer = document.getElementById('recommended-clients');

        if (recommendedContainer) {
            recommendations.forEach(function(client) {
                const clientHtml = generateClientHtml(client, true);
            recommendedContainer.insertAdjacentHTML('beforeend', clientHtml);
            });
        }

        const accordionContainer = document.getElementById('platform-accordion');

        if (accordionContainer) {
            Object.keys(clientRecommendations).forEach(function(platform) {
                const clients = clientRecommendations[platform];
                const platformId = 'platform-' + platform.toLowerCase();
                const icon = platformIcons[platform] || CONFIG.BUTTONS.download.icon.replace('ti-', 'ti-device-');

                const accordionHtml = `
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#${platformId}">
                                <i class="ti ${icon} me-2"></i> ${platform}
                            </button>
                        </h2>
                        <div id="${platformId}" class="accordion-collapse collapse"
                             data-bs-parent="#platform-accordion">
                            <div class="accordion-body">
                                <div class="row g-3">
                                    ${clients.map(client => generateClientHtml(client, false)).join('')}
                                </div>
                            </div>
                        </div>
                    </div>`;

                accordionContainer.insertAdjacentHTML('beforeend', accordionHtml.trim());
            });
        }
    }

    function initClipboard() {
        if (typeof ClipboardJS === 'undefined') {
            console.warn('ClipboardJS 未加载');
            return;
        }

        const clipboard = new ClipboardJS('.copy');

        clipboard.on('success', function(e) {
            e.clearSelection();
            const originalText = e.trigger.innerHTML;
            const checkIcon = createIcon('ti-check');
            e.trigger.innerHTML = '';
            e.trigger.appendChild(checkIcon);
            e.trigger.appendChild(document.createTextNode(' ' + CONFIG.CLIPBOARD_SUCCESS_TEXT));
            setTimeout(function() {
                e.trigger.innerHTML = originalText;
            }, CONFIG.FEEDBACK_TIMEOUT);
        });

        clipboard.on('error', function(e) {
            console.error('复制失败:', e.action);
            alert(CONFIG.CLIPBOARD_ERROR_TEXT);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        safeInit(initClientSelector, '客户端选择器');
        safeInit(initClipboard, '剪贴板功能');
    });
    {/literal}
    </script>

    {include file='user/footer.tpl'}
