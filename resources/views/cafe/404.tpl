<!doctype html>
<html lang="zh"{if $user->is_dark_mode} data-theme="dark"{/if}>

<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="referrer" content="never">
    <title>404 - {$config['appName']}</title>
    <link rel="icon" href="/favicon.ico">
    <link href="/theme/cafe/app.css?v={$config['assets_version']}" rel="stylesheet"/>
</head>

<body class="bg-canvas min-h-screen">
<div class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-md text-center">
        <div class="text-primary text-7xl font-semibold tracking-tight">404</div>
        <h1 class="mt-4 text-xl font-semibold tracking-tight">你所尝试访问的页面不存在</h1>
        <p class="text-faint mt-2 text-sm leading-relaxed">
            Take me home, country roads, to the place, I belong...
        </p>
        <a href="/" class="btn-primary btn-sm mt-6">返回主页</a>
    </div>
</div>
</body>

</html>
