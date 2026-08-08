<!doctype html>
<html lang="zh"{if $user->is_dark_mode} data-theme="dark"{/if}>

<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="referrer" content="never">
    <title>关于 - {$config['appName']}</title>
    <link rel="icon" href="/favicon.ico">
    <link href="/theme/cafe/app.css?v={$config['assets_version']}" rel="stylesheet"/>
</head>

<body class="bg-canvas min-h-screen">
<div class="mx-auto w-full max-w-2xl px-4 py-12 sm:py-16">
    <div class="c-card p-8">
        <h1 class="text-xl font-semibold tracking-tight">MIT License</h1>
        <p class="text-faint mt-1 text-sm">&copy;2019 SSPanel UIM</p>

        <div class="text-body mt-4 space-y-3 text-sm leading-relaxed">
            <p>Permission is hereby granted, free of charge, to any person obtaining a copy
                of this software and associated documentation files (the "Software"), to deal
                in the Software without restriction, including without limitation the rights
                to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
                copies of the Software, and to permit persons to whom the Software is
                furnished to do so, subject to the following conditions:</p>
            <p>The above copyright notice and this permission notice shall be included in all
                copies or substantial portions of the Software.</p>
            <p>THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
                IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
                FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
                AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
                LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
                OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
                SOFTWARE.</p>
        </div>

        <h2 class="mt-8 text-base font-semibold">项目</h2>
        <div class="mt-2 space-y-1 text-sm leading-relaxed">
            <p><a class="text-primary hover:underline" href="https://github.com/Anankke/SSPanel-UIM/graphs/contributors">贡献者清单</a></p>
            <p><a class="text-primary hover:underline" href="https://github.com/Anankke/SSPanel-Uim">GitHub Repo</a></p>
            <p><a class="text-primary hover:underline" href="https://github.com/sspanel-uim">GitHub Org</a></p>
        </div>

        <h2 class="mt-8 text-base font-semibold">SSPanel-UIM 的存在离不开以下开源项目</h2>
        <div class="mt-2 space-y-1 text-sm leading-relaxed">
            <p><a class="text-primary hover:underline" href="https://github.com/slimphp/Slim">Slim Framework</a></p>
            <p><a class="text-primary hover:underline" href="https://github.com/tabler/tabler">Tabler</a></p>
            <p><a class="text-primary hover:underline" href="https://github.com/smarty-php/smarty">Smarty</a></p>
        </div>

        <p class="text-body mt-6 text-sm leading-relaxed">This product includes GeoLite2 data created by MaxMind, available from
            <a class="text-primary hover:underline" href="https://www.maxmind.com">https://www.maxmind.com</a>.</p>

        <h2 class="mt-8 text-base font-semibold">鸣谢</h2>
        <p class="text-body mt-2 text-sm leading-relaxed">所有被引用过代码的开发者，以及所有提交过 PR 的贡献者。当然，还有在使用这份程序的你我Ta。</p>
    </div>
</div>
</body>

</html>
