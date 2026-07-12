{include file='shell/header.tpl' nav='docs'}

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">使用文档</h2>
    <p class="text-faint mt-1 text-sm">在这里查看安装和使用教程</p>
</div>

<div class="c-card">
    {if count($docs) > 0}
        <div class="divide-y divide-(--color-hairline)">
            {foreach $docs as $doc}
                <a href="/user/docs/{$doc->id}/view"
                   class="hover:bg-tile/60 flex items-center gap-4 px-5 py-4 transition-colors">
                    <span class="bg-primary-tint text-primary flex size-10 shrink-0 items-center justify-center rounded-full">
                        <i class="ti ti-file-text"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="text-ink truncate text-sm font-medium">{$doc->title}</div>
                        <div class="text-faint mt-0.5 text-xs">{$doc->date}</div>
                    </div>
                    <i class="ti ti-chevron-right text-faint shrink-0"></i>
                </a>
            {/foreach}
        </div>
    {else}
        <div class="text-faint flex flex-col items-center gap-2 py-14 text-sm">
            <i class="ti ti-file-off text-2xl"></i>
            暂无文档
        </div>
    {/if}
</div>

{include file='shell/footer.tpl'}
