{* 认证页分屏外壳(上半):include 参数 page_title / brand_title / brand_sub *}
<!doctype html>
<html lang="zh">

<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="referrer" content="never">
    <title>{$page_title|default:''} - {$config['appName']}</title>
    <link rel="icon" href="/favicon.ico">
    <link href="/theme/cafe/app.css?v={$config['assets_version']}" rel="stylesheet"/>
    <link href="//{$config['jsdelivr_url']}/npm/@tabler/icons-webfont@3/dist/tabler-icons.min.css" rel="stylesheet"/>
    <style>[x-cloak] { display: none !important; }</style>
    <script src="/theme/cafe/js/htmx.min.js"></script>
</head>

<body class="bg-canvas min-h-screen">
<div class="flex min-h-screen">

    {* ============ 左侧品牌区 ============ *}
    <div class="from-primary to-primary-hover relative hidden w-[44%] flex-col justify-between
                overflow-hidden bg-gradient-to-br p-10 text-white lg:flex">
        <div class="flex items-center gap-3">
            <img src="/images/uim-logo-round_48x48.png" alt="logo" class="size-10 rounded-xl">
            <span class="text-lg font-semibold">{$config['appName']}</span>
        </div>
        <div class="relative z-10">
            <h1 class="text-3xl leading-snug font-semibold text-white">
                {$brand_title|default:'欢迎回到咖啡厅<br>今天也要元气满满'}
            </h1>
            <p class="mt-4 max-w-sm text-sm leading-relaxed text-white/75">
                {$brand_sub|default:'登录后即可管理订阅、查看流量用量、快速导入客户端配置。'}
            </p>
        </div>
        <div class="text-xs text-white/50">Powered by SSPanel-UIM</div>
        {* 装饰圆 *}
        <div class="absolute -right-24 -bottom-24 size-80 rounded-full bg-white/10"></div>
        <div class="absolute -right-6 -bottom-40 size-56 rounded-full bg-white/10"></div>
    </div>

    {* ============ 右侧表单区 ============ *}
    <div class="flex flex-1 items-center justify-center px-5 py-10">
        <div class="w-full max-w-sm">
            <div class="mb-8 lg:hidden">
                <img src="/images/uim-logo-round_48x48.png" alt="logo" class="size-11 rounded-xl">
            </div>
