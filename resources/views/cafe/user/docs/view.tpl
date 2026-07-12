{include file='shell/header.tpl' nav='docs'}

<a href="/user/docs" class="text-body hover:text-ink mb-5 inline-flex items-center gap-1.5 text-sm font-medium">
    <span class="bg-tile flex size-7 items-center justify-center rounded-full"><i class="ti ti-arrow-left"></i></span>
    返回文档中心
</a>

<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">{$doc->title}</h2>
</div>

<div class="c-card p-6 sm:p-8">
    <div class="text-body [&_h1]:text-ink [&_h2]:text-ink [&_h3]:text-ink [&_a]:text-primary max-w-none
                text-sm leading-relaxed [&_code]:rounded [&_code]:bg-(--color-tile) [&_code]:px-1.5 [&_code]:py-0.5
                [&_h1]:mt-6 [&_h1]:mb-3 [&_h1]:text-xl [&_h1]:font-semibold
                [&_h2]:mt-5 [&_h2]:mb-2.5 [&_h2]:text-lg [&_h2]:font-semibold
                [&_h3]:mt-4 [&_h3]:mb-2 [&_h3]:text-base [&_h3]:font-semibold
                [&_img]:max-w-full [&_img]:rounded-xl
                [&_li]:my-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-2.5 [&_ul]:list-disc [&_ul]:pl-5">
        {$doc->content}
    </div>
</div>

{include file='shell/footer.tpl'}
