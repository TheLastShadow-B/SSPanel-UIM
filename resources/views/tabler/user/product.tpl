{include file='user/header.tpl'}

<div class="page-wrapper">
    <div class="container-xl">
        <div class="page-header d-print-none text-white">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">
                        <span class="home-title">商品列表</span>
                    </h2>
                    <div class="page-pretitle my-3">
                        <span class="home-subtitle">浏览你所需要的商品</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <div class="row row-cards">
                <div class="col-12">
                    <div class="card">
                        <ul class="nav nav-tabs nav-fill" data-bs-toggle="tabs">
                            <li class="nav-item">
                                <a href="#subscription" class="nav-link active" data-bs-toggle="tab">
                                    <i class="ti ti-star icon"></i>
                                    &nbsp;订阅套餐
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="#bandwidth" class="nav-link" data-bs-toggle="tab">
                                    <i class="ti ti-arrows-down-up icon"></i>
                                    &nbsp;流量包
                                </a>
                            </li>
                        </ul>
                        <div class="card-body">
                            <div class="tab-content">
                                <div class="tab-pane active show" id="subscription">
                                    <div class="row">
                                        {foreach $subscriptions as $sub}
                                            <div class="col-md-4 col-sm-12 my-3">
                                                <div class="card card-md">
                                                    <div class="card-body text-center">
                                                        <div class="text-uppercase text-secondary font-weight-medium">
                                                            {$sub->name}
                                                        </div>
                                                        <div class="display-6 my-3">
                                                            <span class="fw-bold">{$sub->price}</span>
                                                            <span class="text-secondary fs-4">元/月</span>
                                                        </div>
                                                        {* Discount badges *}
                                                        <div class="mb-3">
                                                            {if isset($sub->content->billing_cycle->quarter) && $sub->content->billing_cycle->quarter && isset($sub->content->discount->quarter) && $sub->content->discount->quarter < 1}
                                                                <span class="badge bg-green-lt me-1">季付{$sub->content->discount->quarter * 10|string_format:"%.0f"}折</span>
                                                            {/if}
                                                            {if isset($sub->content->billing_cycle->year) && $sub->content->billing_cycle->year && isset($sub->content->discount->year) && $sub->content->discount->year < 1}
                                                                <span class="badge bg-green-lt me-1">年付{$sub->content->discount->year * 10|string_format:"%.0f"}折</span>
                                                            {/if}
                                                        </div>
                                                        <div class="list-group list-group-flush">
                                                            <div class="list-group-item">
                                                                <div class="row align-items-center">
                                                                    <div class="col text-truncate">
                                                                        <div class="text-reset d-block">{$sub->content->bandwidth} GB/月</div>
                                                                        <div class="d-block text-secondary text-truncate mt-n1">可用流量</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="list-group-item">
                                                                <div class="row align-items-center">
                                                                    <div class="col text-truncate">
                                                                        <div class="text-reset d-block">Lv. {$sub->content->class}</div>
                                                                        <div class="d-block text-secondary text-truncate mt-n1">等级</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="list-group-item">
                                                                <div class="row align-items-center">
                                                                    <div class="col text-truncate">
                                                                        {if $sub->content->speed_limit == 0}
                                                                            <div class="text-reset d-block">不限制</div>
                                                                        {else}
                                                                            <div class="text-reset d-block">{$sub->content->speed_limit} Mbps</div>
                                                                        {/if}
                                                                        <div class="d-block text-secondary text-truncate mt-n1">连接速度</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="list-group-item">
                                                                <div class="row align-items-center">
                                                                    <div class="col text-truncate">
                                                                        {if $sub->content->ip_limit == 0}
                                                                            <div class="text-reset d-block">不限制</div>
                                                                        {else}
                                                                            <div class="text-reset d-block">{$sub->content->ip_limit}</div>
                                                                        {/if}
                                                                        <div class="d-block text-secondary text-truncate mt-n1">同时连接 IP 数</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row g-2">
                                                            {if $hasActiveSubscription}
                                                                <div class="col">
                                                                    <a href="#" class="btn btn-secondary w-100 my-3" disabled>您已有活跃订阅</a>
                                                                </div>
                                                            {elseif $sub->stock === 0}
                                                                <div class="col">
                                                                    <a href="#" class="btn btn-secondary w-100 my-3" disabled>告罄</a>
                                                                </div>
                                                            {else}
                                                                <div class="col">
                                                                    <a href="/user/order/create?product_id={$sub->id}" class="btn btn-primary w-100 my-3">购买</a>
                                                                </div>
                                                            {/if}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        {/foreach}
                                    </div>
                                </div>
                                <div class="tab-pane show" id="bandwidth">
                                    <div class="row">
                                        {foreach $bandwidths as $bandwidth}
                                            <div class="col-md-4 col-sm-12 my-3">
                                                <div class="card card-md">
                                                    <div class="card-body text-center">
                                                        <div class="text-uppercase text-secondary font-weight-medium">
                                                            {$bandwidth->name}
                                                        </div>
                                                        <div class="display-6 my-3">
                                                            <span class="fw-bold">{$bandwidth->price}</span>
                                                            <i class="ti ti-currency-yuan"></i>
                                                        </div>
                                                        <div class="list-group list-group-flush">
                                                            <div class="list-group-item">
                                                                <div class="row align-items-center">
                                                                    <div class="col text-truncate">
                                                                        <div class="text-reset d-block">{$bandwidth->content->bandwidth} GB</div>
                                                                        <div class="d-block text-secondary text-truncate mt-n1">可用流量</div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row g-2">
                                                            {if !$hasActiveSubscription}
                                                                <div class="col">
                                                                    <a href="#" class="btn btn-secondary w-100 my-3" disabled>需要先购买订阅</a>
                                                                </div>
                                                            {elseif $bandwidth->stock === 0}
                                                                <div class="col">
                                                                    <a href="#" class="btn btn-secondary w-100 my-3" disabled>告罄</a>
                                                                </div>
                                                            {else}
                                                                <div class="col">
                                                                    <a href="/user/order/create?product_id={$bandwidth->id}" class="btn btn-primary w-100 my-3">购买</a>
                                                                </div>
                                                            {/if}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        {/foreach}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {include file='user/footer.tpl'}
