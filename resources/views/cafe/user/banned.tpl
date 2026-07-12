<!doctype html>
<html lang="zh"{if $user->is_dark_mode} data-theme="dark"{/if}>

<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="referrer" content="never">
    <title>账户已被封禁 - {$config['appName']}</title>
    <link rel="icon" href="/favicon.ico">
    <link href="/theme/cafe/app.css?v={$config['assets_version']}" rel="stylesheet"/>
    <link href="//{$config['jsdelivr_url']}/npm/@tabler/icons-webfont@3/dist/tabler-icons.min.css" rel="stylesheet"/>
</head>

<body class="bg-canvas min-h-screen">
<div class="flex min-h-screen items-center justify-center px-4">
    <div class="c-card w-full max-w-md p-8 text-center">
        <span class="bg-danger-tint text-danger mx-auto mb-5 flex size-16 items-center justify-center rounded-full text-3xl">
            <i class="ti ti-ban"></i>
        </span>
        <h1 class="text-xl font-semibold tracking-tight">账户已被封禁</h1>
        {if $banned_reason === 'DetectBan'}
            <p class="text-body mt-2 text-sm">审计封禁</p>
            <p class="text-faint mt-1 text-sm leading-relaxed">你的账户因为触发审计规则而被系统自动封禁</p>
        {else}
            <p class="text-body mt-2 text-sm">以下是你被封禁的理由</p>
            <p class="text-faint mt-1 text-sm leading-relaxed">{$banned_reason}</p>
        {/if}
        <a href="/user/logout" class="btn-secondary btn-sm mt-6">登出</a>
    </div>
</div>
</body>

</html>
