<!doctype html>
<html lang="zh"{if $user->is_dark_mode} data-theme="dark"{/if}>

<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="referrer" content="never">
    <title>用户服务协议 - {$config['appName']}</title>
    <link rel="icon" href="/favicon.ico">
    <link href="/theme/cafe/app.css?v={$config['assets_version']}" rel="stylesheet"/>
</head>

<body class="bg-canvas min-h-screen">
<div class="mx-auto w-full max-w-2xl px-4 py-12 sm:py-16">
    <div class="c-card p-8">
        <h1 class="text-xl font-semibold tracking-tight">用户服务协议（Terms of Service）</h1>
        <p class="text-body mt-3 text-sm leading-relaxed">{$config['appName']}，以下简称本站。</p>

        <h2 class="mt-8 text-base font-semibold">隐私安全</h2>
        <div class="text-body mt-2 space-y-1 text-sm leading-relaxed">
            <p>邮箱为本站服务的唯一凭证，请妥善保管。</p>
            <p>用户密码均为密文储存，无法解密，但出于安全起见还是请使用高强度密码或使用密码管理器。</p>
        </div>

        <h2 class="mt-8 text-base font-semibold">使用条款</h2>
        <div class="text-body mt-2 space-y-1 text-sm leading-relaxed">
            <p>在使用服务时，需遵循站点和节点所在国家的法律。</p>
            <p>对于免费用户，本站有权在不通知的情况下删除账户。</p>
            <p>任何违反使用条款的用户，我们将会删除违规账户并收回使用本站服务的权利。</p>
        </div>

        <a href="/" class="btn-secondary btn-sm mt-8">返回主页</a>
    </div>
</div>
</body>

</html>
