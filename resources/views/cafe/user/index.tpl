{include file='shell/header.tpl' nav='dashboard'}

{* ============ 页头 ============ *}
<div class="mb-6">
    <h2 class="text-2xl font-semibold tracking-tight">我的订阅</h2>
    <p class="text-faint mt-1 text-sm">欢迎回来，{$user->user_name}</p>
</div>

<div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">

    {* ============ 订阅实体卡 ============ *}
    <a href="/user/subscription" class="c-card hover:border-primary group flex flex-col transition-colors">
        <div class="flex items-center gap-3 p-5 pb-4">
            <span class="bg-primary-tint text-primary flex size-12 items-center justify-center rounded-full text-xl">
                <i class="ti ti-coffee"></i>
            </span>
            <div class="min-w-0 flex-1">
                <div class="text-ink truncate text-base font-semibold">
                    {if $user->class > 0}LV.{$user->class} 订阅{else}免费用户{/if}
                </div>
                <div class="text-faint text-xs">点击查看用量与订阅设置</div>
            </div>
            <i class="ti ti-chevron-right text-faint transition-transform group-hover:translate-x-0.5"></i>
        </div>
        <div class="border-hairline mx-5 border-t"></div>
        <div class="flex-1 px-5 py-2">
            <div class="kv-row">
                <span class="kv-key">状态</span>
                {if $user->class > 0}
                    {if $class_expire_days <= 7}
                        <span class="badge-warning"><i class="ti ti-clock"></i> {$class_expire_days} 天后到期</span>
                    {else}
                        <span class="badge-success"><i class="ti ti-circle-check"></i> 有效</span>
                    {/if}
                {else}
                    <span class="badge-neutral">未订阅</span>
                {/if}
            </div>
            <div class="kv-row">
                <span class="kv-key">到期时间</span>
                <span class="value-pill">{$user->class_expire|substr:0:10}</span>
            </div>
            <div class="kv-row">
                <span class="kv-key">账户余额</span>
                <span class="kv-val">¥ {$user->money}</span>
            </div>
        </div>
        <div class="px-5 pt-1 pb-5">
            <div class="mb-1.5 flex items-baseline justify-between text-sm">
                <span class="text-body">剩余流量</span>
                <span class="text-ink font-semibold">{$user->unusedTraffic()}</span>
            </div>
            <div class="meter">
                <span style="width: {(100 - $user->unusedTrafficPercent())|string_format:'%.1f'}%"></span>
            </div>
            <div class="text-faint mt-1.5 text-xs">总量 {$user->enableTraffic()}</div>
        </div>
    </a>

    {* ============ 购买新订阅幽灵卡 ============ *}
    <a href="/user/product" class="c-ghost-card">
        <span class="bg-tile flex size-11 items-center justify-center rounded-xl text-xl">
            <i class="ti ti-plus"></i>
        </span>
        <span class="text-sm font-medium">购买新订阅</span>
    </a>

    {* ============ 快速导入卡(按检测到的系统直接给出推荐客户端)============ *}
    <div class="c-card-pad flex flex-col" x-data="clientImport()">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <i class="ti ti-rocket text-primary text-lg"></i>
                <h3 class="text-base">快速导入</h3>
            </div>
            <span class="badge-primary" x-text="os"></span>
        </div>

        <div class="mb-3 flex flex-col gap-2">
            <template x-for="c in recommended" :key="c.name">
                <div class="bg-tile flex items-center gap-2.5 rounded-(--radius-tile) px-3 py-2.5">
                    <div class="min-w-0 flex-1">
                        <div class="text-ink truncate text-sm font-medium" x-text="c.name"></div>
                        <div class="text-faint truncate text-xs" x-text="c.description"></div>
                    </div>
                    <a class="btn-primary btn-sm shrink-0" :href="c.importUrl">
                        <i class="ti ti-link"></i> 一键导入
                    </a>
                    <button class="btn-secondary btn-sm copy shrink-0 !px-2.5" :data-clipboard-text="subUrl(c)"
                            title="复制该客户端的订阅链接">
                        <i class="ti ti-copy"></i>
                    </button>
                </div>
            </template>
        </div>

        <a href="/user/subscription#settings" class="btn-secondary btn-sm mt-auto w-full">
            <i class="ti ti-apps"></i> 全部客户端与导入方式
        </a>
    </div>

    {* ============ 置顶公告卡 ============ *}
    <div class="c-card-pad flex flex-col md:col-span-2">
        <div class="mb-3 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <i class="ti ti-speakerphone text-warning text-lg"></i>
                <h3 class="text-base">置顶公告</h3>
            </div>
            {if $ann !== null}
                <span class="text-faint text-xs">{$ann->date}</span>
            {/if}
        </div>
        {if $ann !== null}
            <div class="text-body max-h-56 overflow-y-auto text-sm leading-relaxed [&_a]:text-primary">
                {$ann->content}
            </div>
            <a href="/user/announcement" class="text-primary mt-3 inline-flex items-center gap-1 text-sm font-medium">
                查看全部公告 <i class="ti ti-arrow-right text-xs"></i>
            </a>
        {else}
            <div class="text-faint flex flex-1 flex-col items-center justify-center gap-2 py-8 text-sm">
                <i class="ti ti-bell-off text-2xl"></i>
                暂无公告
            </div>
        {/if}
    </div>

    {* ============ 邀请返利卡 ============ *}
    <div class="c-card-pad from-primary to-primary-hover flex flex-col justify-between bg-gradient-to-br !border-0 text-white">
        <div>
            <div class="mb-2 flex items-center gap-2 text-base font-semibold">
                <i class="ti ti-gift text-lg"></i>
                邀请好友
            </div>
            <p class="text-sm leading-relaxed text-white/80">
                分享你的邀请链接，好友注册消费后可获得返利奖励。
            </p>
        </div>
        <a href="/user/invite" class="btn mt-5 w-full bg-white/95 text-sm font-semibold !text-[#1d5ff6] hover:bg-white">
            立即邀请
        </a>
    </div>

</div>

<script>
    window.CAFE_SUB = "{$UniversalSub}";
    window.CAFE_CLIENTS = {$clientData|default:'{ }'};
    window.CAFE_ICONS = {$platformIcons|default:'{ }'};
    window.CAFE_R2 = {if $config['enable_r2_client_download']}true{else}false{/if};
</script>
{include file='shell/client_import.tpl'}

{include file='shell/footer.tpl'}
