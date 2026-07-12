{include file='shell/header.tpl' nav='announcement'}

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">站点公告</h2>
    <p class="text-faint mt-1 text-sm">管理员发布的所有公告</p>
</div>

<div class="flex flex-col gap-5">
    {foreach $anns as $ann}
        <div class="c-card-pad">
            <div class="mb-3 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <i class="ti ti-speakerphone text-warning text-lg"></i>
                    <h3 class="text-base">公告 #{$ann->id}</h3>
                </div>
                <span class="text-faint text-xs">{$ann->date}</span>
            </div>
            <div class="text-body text-sm leading-relaxed [&_a]:text-primary">
                {$ann->content}
            </div>
        </div>
    {foreachelse}
        <div class="c-card-pad text-faint flex flex-col items-center gap-2 py-14 text-sm">
            <i class="ti ti-bell-off text-2xl"></i>
            暂无公告
        </div>
    {/foreach}
</div>

{include file='shell/footer.tpl'}
