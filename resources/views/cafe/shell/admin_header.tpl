{* 管理端壳:{include file='shell/admin_header.tpl' nav='users'} *}
<!doctype html>
<html lang="zh"{if $user->is_dark_mode} data-theme="dark"{/if}>

<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="referrer" content="never">
    <title>管理后台 - {$config['appName']}</title>
    <link rel="icon" href="/favicon.ico">
    <link href="/theme/cafe/app.css?v={$config['assets_version']}" rel="stylesheet"/>
    <link href="//{$config['jsdelivr_url']}/npm/@tabler/icons-webfont@3/dist/tabler-icons.min.css" rel="stylesheet"/>
    <style>[x-cloak] { display: none !important; }</style>
    <script src="/theme/cafe/js/htmx.min.js"></script>
    <script src="/theme/cafe/js/clipboard.min.js"></script>
    <script defer src="/theme/cafe/js/alpine.min.js"></script>
</head>

<body class="bg-canvas min-h-screen">
<div x-data="{ sidebar: false }" @keydown.escape.window="sidebar = false">

    <div x-show="sidebar" x-cloak @click="sidebar = false"
         class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

    {* ============ 侧边栏 ============ *}
    <aside :class="sidebar ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="bg-card border-hairline fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col
                  border-r px-4 py-5 transition-transform duration-200 lg:translate-x-0">

        <a href="/admin" class="mb-4 flex items-center gap-2.5 px-2">
            <img src="/images/uim-logo-round_48x48.png" alt="logo" class="size-9 rounded-xl">
            <span class="min-w-0">
                <span class="text-ink block truncate text-base font-semibold">{$config['appName']}</span>
                <span class="text-faint block text-xs">管理后台</span>
            </span>
        </a>

        {* 当前页所在分组(强制展开) *}
        {$navgroup = ''}
        {if in_array($nav|default:'', ['users', 'nodes'])}{$navgroup = 'users'}
        {elseif in_array($nav|default:'', ['product', 'subscription', 'order', 'invoice', 'coupon', 'giftcard'])}{$navgroup = 'finance'}
        {elseif in_array($nav|default:'', ['announcement', 'ticket', 'docs'])}{$navgroup = 'ops'}
        {elseif in_array($nav|default:'', ['detect', 'detect-log', 'detect-ban'])}{$navgroup = 'audit'}
        {elseif in_array($nav|default:'', ['log-login', 'log-subscribe', 'log-payback', 'log-money', 'log-gateway', 'log-online', 'syslog'])}{$navgroup = 'logs'}
        {elseif in_array($nav|default:'', ['setting', 'system'])}{$navgroup = 'system'}
        {/if}

        <script>window.CAFE_NAVGROUP = '{$navgroup}';</script>
        {literal}
        <script>
            // 分组折叠状态:localStorage 记忆,当前页所在分组强制展开
            function adminNav() {
                let stored = {};
                try { stored = JSON.parse(localStorage.getItem('cafe.adminNav') || '{}'); } catch (e) {}
                const groups = Object.assign(
                    { users: true, finance: true, ops: true, audit: false, logs: false, system: true },
                    stored
                );
                if (window.CAFE_NAVGROUP) groups[window.CAFE_NAVGROUP] = true;
                return {
                    g: groups,
                    toggle(k) {
                        this.g[k] = !this.g[k];
                        localStorage.setItem('cafe.adminNav', JSON.stringify(this.g));
                    }
                };
            }
        </script>
        {/literal}

        <nav class="-mx-1 flex flex-1 flex-col gap-0.5 overflow-y-auto px-1" x-data="adminNav()">
            <a href="/admin" class="side-link {if ($nav|default:'') === 'dashboard'}active{/if}">
                <span class="side-ico"><i class="ti ti-layout-dashboard"></i></span>
                概况
            </a>

            <button class="side-caption flex w-full cursor-pointer items-center justify-between pr-2" @click="toggle('users')">
                用户与节点
                <i class="ti ti-chevron-down transition-transform" :class="!g.users && '-rotate-90'"></i>
            </button>
            <div x-show="g.users" class="flex flex-col gap-0.5">
                <a href="/admin/user" class="side-link {if ($nav|default:'') === 'users'}active{/if}">
                    <span class="side-ico"><i class="ti ti-users"></i></span>
                    用户
                </a>
                <a href="/admin/node" class="side-link {if ($nav|default:'') === 'nodes'}active{/if}">
                    <span class="side-ico"><i class="ti ti-server-2"></i></span>
                    节点
                </a>
            </div>

            <button class="side-caption flex w-full cursor-pointer items-center justify-between pr-2" @click="toggle('finance')">
                财务
                <i class="ti ti-chevron-down transition-transform" :class="!g.finance && '-rotate-90'"></i>
            </button>
            <div x-show="g.finance" class="flex flex-col gap-0.5">
                <a href="/admin/product" class="side-link {if ($nav|default:'') === 'product'}active{/if}">
                    <span class="side-ico"><i class="ti ti-list-details"></i></span>
                    商品
                </a>
                <a href="/admin/subscription" class="side-link {if ($nav|default:'') === 'subscription'}active{/if}">
                    <span class="side-ico"><i class="ti ti-refresh"></i></span>
                    订阅管理
                </a>
                <a href="/admin/order" class="side-link {if ($nav|default:'') === 'order'}active{/if}">
                    <span class="side-ico"><i class="ti ti-receipt"></i></span>
                    订单
                </a>
                <a href="/admin/invoice" class="side-link {if ($nav|default:'') === 'invoice'}active{/if}">
                    <span class="side-ico"><i class="ti ti-file-dollar"></i></span>
                    账单
                </a>
                <a href="/admin/coupon" class="side-link {if ($nav|default:'') === 'coupon'}active{/if}">
                    <span class="side-ico"><i class="ti ti-ticket"></i></span>
                    优惠码
                </a>
                <a href="/admin/giftcard" class="side-link {if ($nav|default:'') === 'giftcard'}active{/if}">
                    <span class="side-ico"><i class="ti ti-gift-card"></i></span>
                    礼品卡
                </a>
            </div>

            <button class="side-caption flex w-full cursor-pointer items-center justify-between pr-2" @click="toggle('ops')">
                运营
                <i class="ti ti-chevron-down transition-transform" :class="!g.ops && '-rotate-90'"></i>
            </button>
            <div x-show="g.ops" class="flex flex-col gap-0.5">
                <a href="/admin/announcement" class="side-link {if ($nav|default:'') === 'announcement'}active{/if}">
                    <span class="side-ico"><i class="ti ti-speakerphone"></i></span>
                    公告
                </a>
                <a href="/admin/ticket" class="side-link {if ($nav|default:'') === 'ticket'}active{/if}">
                    <span class="side-ico"><i class="ti ti-messages"></i></span>
                    工单
                </a>
                <a href="/admin/docs" class="side-link {if ($nav|default:'') === 'docs'}active{/if}">
                    <span class="side-ico"><i class="ti ti-notes"></i></span>
                    文档
                </a>
            </div>

            <button class="side-caption flex w-full cursor-pointer items-center justify-between pr-2" @click="toggle('audit')">
                审计
                <i class="ti ti-chevron-down transition-transform" :class="!g.audit && '-rotate-90'"></i>
            </button>
            <div x-show="g.audit" class="flex flex-col gap-0.5">
                <a href="/admin/detect" class="side-link {if ($nav|default:'') === 'detect'}active{/if}">
                    <span class="side-ico"><i class="ti ti-barrier-block"></i></span>
                    审计规则
                </a>
                <a href="/admin/detect/log" class="side-link {if ($nav|default:'') === 'detect-log'}active{/if}">
                    <span class="side-ico"><i class="ti ti-file-search"></i></span>
                    碰撞记录
                </a>
                <a href="/admin/detect/ban" class="side-link {if ($nav|default:'') === 'detect-ban'}active{/if}">
                    <span class="side-ico"><i class="ti ti-ban"></i></span>
                    封禁记录
                </a>
            </div>

            <button class="side-caption flex w-full cursor-pointer items-center justify-between pr-2" @click="toggle('logs')">
                日志
                <i class="ti ti-chevron-down transition-transform" :class="!g.logs && '-rotate-90'"></i>
            </button>
            <div x-show="g.logs" class="flex flex-col gap-0.5">
                <a href="/admin/login" class="side-link {if ($nav|default:'') === 'log-login'}active{/if}">
                    <span class="side-ico"><i class="ti ti-login"></i></span>
                    登录日志
                </a>
                <a href="/admin/subscribe" class="side-link {if ($nav|default:'') === 'log-subscribe'}active{/if}">
                    <span class="side-ico"><i class="ti ti-rss"></i></span>
                    订阅日志
                </a>
                <a href="/admin/payback" class="side-link {if ($nav|default:'') === 'log-payback'}active{/if}">
                    <span class="side-ico"><i class="ti ti-friends"></i></span>
                    返利日志
                </a>
                <a href="/admin/money" class="side-link {if ($nav|default:'') === 'log-money'}active{/if}">
                    <span class="side-ico"><i class="ti ti-coin"></i></span>
                    余额日志
                </a>
                <a href="/admin/gateway" class="side-link {if ($nav|default:'') === 'log-gateway'}active{/if}">
                    <span class="side-ico"><i class="ti ti-building-bank"></i></span>
                    支付网关
                </a>
                <a href="/admin/online" class="side-link {if ($nav|default:'') === 'log-online'}active{/if}">
                    <span class="side-ico"><i class="ti ti-router"></i></span>
                    在线 IP
                </a>
                <a href="/admin/syslog" class="side-link {if ($nav|default:'') === 'syslog'}active{/if}">
                    <span class="side-ico"><i class="ti ti-terminal-2"></i></span>
                    系统日志
                </a>
            </div>

            <button class="side-caption flex w-full cursor-pointer items-center justify-between pr-2" @click="toggle('system')">
                系统
                <i class="ti ti-chevron-down transition-transform" :class="!g.system && '-rotate-90'"></i>
            </button>
            <div x-show="g.system" class="flex flex-col gap-0.5">
                <a href="/admin/setting/billing" class="side-link {if ($nav|default:'') === 'setting'}active{/if}">
                    <span class="side-ico"><i class="ti ti-adjustments"></i></span>
                    面板设置
                </a>
                <a href="/admin/system" class="side-link {if ($nav|default:'') === 'system'}active{/if}">
                    <span class="side-ico"><i class="ti ti-tool"></i></span>
                    系统信息
                </a>
            </div>
        </nav>

        <div class="border-hairline mt-3 border-t pt-3">
            <a href="/user" class="side-link">
                <span class="side-ico"><i class="ti ti-arrow-back-up"></i></span>
                返回用户中心
            </a>
            <a href="/user/logout" class="side-link hover:text-danger">
                <span class="side-ico"><i class="ti ti-logout"></i></span>
                登出
            </a>
        </div>
    </aside>

    {* ============ 内容列 ============ *}
    <div class="lg:pl-64">
        <div class="mx-auto max-w-7xl px-4 pt-4 pb-10 sm:px-6 lg:px-8">

            <div class="mb-5 flex items-center justify-between gap-3">
                <button class="btn-secondary btn-sm !size-9 !rounded-xl !p-0 text-base lg:invisible"
                        @click="sidebar = true" aria-label="打开菜单">
                    <i class="ti ti-menu-2"></i>
                </button>

                <div class="flex items-center gap-2">
                    <button class="btn-secondary btn-sm !size-9 !rounded-xl !p-0 text-base"
                            aria-label="切换深浅色"
                            hx-post="/user/edit/theme_mode" hx-swap="none"
                            hx-vals='js:{ theme_mode: {if $user->is_dark_mode}0{else}1{/if} }'>
                        <i class="ti {if $user->is_dark_mode}ti-sun{else}ti-moon{/if}"></i>
                    </button>

                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" class="hover:bg-tile flex items-center gap-2.5 rounded-full p-1 pr-3 transition-colors">
                            <span class="size-8 rounded-full bg-cover bg-center"
                                  style="background-image: url({$user->dice_bear})"></span>
                            <span class="text-ink hidden text-sm font-medium sm:block">{$user->user_name}</span>
                            <i class="ti ti-chevron-down text-faint text-xs"></i>
                        </button>
                        <div x-show="open" x-cloak @click.outside="open = false"
                             x-transition.origin.top.right
                             class="c-card absolute right-0 z-20 mt-2 w-56 p-2 shadow-lg">
                            <div class="border-hairline border-b px-3 pt-1 pb-2.5">
                                <div class="text-ink truncate text-sm font-medium">{$user->email}</div>
                                <div class="text-faint mt-0.5 text-xs">管理员</div>
                            </div>
                            <a href="/user" class="side-link mt-1.5">
                                <i class="ti ti-arrow-back-up"></i> 返回用户中心
                            </a>
                            <a href="/user/logout" class="side-link hover:text-danger">
                                <i class="ti ti-logout"></i> 登出
                            </a>
                        </div>
                    </div>
                </div>
            </div>
