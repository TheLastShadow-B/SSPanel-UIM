{* 页面通过 {include file='shell/header.tpl' nav='dashboard'} 传入 nav 以高亮侧边栏 *}
<!doctype html>
<html lang="zh"{if $user->is_dark_mode} data-theme="dark"{/if}>

<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="referrer" content="never">
    <title>{$config['appName']}</title>
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

    {* ============ 移动端遮罩 ============ *}
    <div x-show="sidebar" x-cloak @click="sidebar = false"
         class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

    {* ============ 侧边栏 ============ *}
    <aside :class="sidebar ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="bg-card border-hairline fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col
                  border-r px-4 py-5 transition-transform duration-200 lg:translate-x-0">

        <a href="/user" class="mb-6 flex items-center gap-2.5 px-2">
            <img src="/images/uim-logo-round_48x48.png" alt="logo" class="size-9 rounded-xl">
            <span class="text-ink text-base font-semibold">{$config['appName']}</span>
        </a>

        <nav class="flex flex-1 flex-col gap-0.5 overflow-y-auto">
            <a href="/user/product" class="side-link {if ($nav|default:'') === 'shop'}active{/if}">
                <span class="side-ico"><i class="ti ti-shopping-bag-plus"></i></span>
                购买订阅
            </a>
            <a href="/user/money" class="side-link {if ($nav|default:'') === 'money'}active{/if}">
                <span class="side-ico"><i class="ti ti-wallet"></i></span>
                余额充值
            </a>

            <div class="border-hairline my-3 border-t"></div>

            <a href="/user" class="side-link {if ($nav|default:'') === 'dashboard'}active{/if}">
                <span class="side-ico"><i class="ti ti-stack-2"></i></span>
                我的订阅
            </a>
            <a href="/user/server" class="side-link {if ($nav|default:'') === 'server'}active{/if}">
                <span class="side-ico"><i class="ti ti-server"></i></span>
                节点状态
            </a>
            <a href="/user/announcement" class="side-link {if ($nav|default:'') === 'announcement'}active{/if}">
                <span class="side-ico"><i class="ti ti-speakerphone"></i></span>
                公告
            </a>
            <a href="/user/ticket" class="side-link {if ($nav|default:'') === 'ticket'}active{/if}">
                <span class="side-ico"><i class="ti ti-messages"></i></span>
                工单
            </a>
            <a href="/user/invite" class="side-link {if ($nav|default:'') === 'invite'}active{/if}">
                <span class="side-ico"><i class="ti ti-gift"></i></span>
                邀请返利
            </a>
            <a href="/user/docs" class="side-link {if ($nav|default:'') === 'docs'}active{/if}">
                <span class="side-ico"><i class="ti ti-book-2"></i></span>
                使用文档
            </a>

            <div class="border-hairline my-3 border-t"></div>

            <a href="/user/order" class="side-link {if ($nav|default:'') === 'order'}active{/if}">
                <span class="side-ico"><i class="ti ti-receipt"></i></span>
                订单记录
            </a>
            <a href="/user/edit" class="side-link {if ($nav|default:'') === 'settings'}active{/if}">
                <span class="side-ico"><i class="ti ti-settings"></i></span>
                个人设置
            </a>
        </nav>

        <div class="border-hairline mt-3 border-t pt-3">
            {if $user->is_admin}
                <a href="/admin" class="side-link">
                    <span class="side-ico"><i class="ti ti-shield-cog"></i></span>
                    管理后台
                </a>
            {/if}
            {if isset($smarty.cookies.admin_uid) && $smarty.cookies.admin_uid}
                <a href="/user/switch_back_admin" class="side-link">
                    <span class="side-ico"><i class="ti ti-arrow-back-up"></i></span>
                    返回管理员
                </a>
            {/if}
            <a href="/user/logout" class="side-link hover:text-danger">
                <span class="side-ico"><i class="ti ti-logout"></i></span>
                登出
            </a>
        </div>
    </aside>

    {* ============ 内容列 ============ *}
    <div class="lg:pl-64">
        <div class="mx-auto max-w-6xl px-4 pt-4 pb-10 sm:px-6 lg:px-8">

            {* 顶栏:移动端汉堡 + 右侧控件 *}
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
                                  style="background-image: url({$user->avatar})"></span>
                            <span class="text-ink hidden text-sm font-medium sm:block">{$user->user_name}</span>
                            <i class="ti ti-chevron-down text-faint text-xs"></i>
                        </button>
                        <div x-show="open" x-cloak @click.outside="open = false"
                             x-transition.origin.top.right
                             class="c-card absolute right-0 z-20 mt-2 w-56 p-2 shadow-lg">
                            <div class="border-hairline border-b px-3 pt-1 pb-2.5">
                                <div class="text-ink truncate text-sm font-medium">{$user->email}</div>
                                <div class="text-faint mt-0.5 text-xs">UID {$user->id}</div>
                            </div>
                            <a href="/user/edit" class="side-link mt-1.5">
                                <i class="ti ti-settings"></i> 个人设置
                            </a>
                            <a href="/user/logout" class="side-link hover:text-danger">
                                <i class="ti ti-logout"></i> 登出
                            </a>
                        </div>
                    </div>
                </div>
            </div>
