{include file='user/header.tpl'}

<div class="page-wrapper">
    <div class="container-xl">
        <div class="page-header d-print-none text-white">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">
                        <span class="home-title">我的订阅</span>
                    </h2>
                    <div class="page-pretitle my-3">
                        <span class="home-subtitle">管理你的订阅</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <div class="row row-cards">
                <div class="col-12">
                    {if $subscription === null}
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <div class="text-secondary mb-3">
                                    <i class="ti ti-receipt-off" style="font-size: 3rem;"></i>
                                </div>
                                <h3 class="mb-2">你目前没有活跃的订阅</h3>
                                <p class="text-secondary mb-4">购买一个订阅套餐以开始使用服务。</p>
                                <a href="/user/product" class="btn btn-primary">
                                    <i class="ti ti-shopping-cart icon"></i>
                                    前往商店
                                </a>
                            </div>
                        </div>
                    {else}
                        {if $pendingInvoice !== null}
                            <div class="alert alert-warning alert-dismissible mb-3" role="alert">
                                <div class="d-flex align-items-center">
                                    <div>
                                        <i class="ti ti-alert-triangle icon me-2"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        你有一笔待支付的续费账单，请尽快完成支付以避免订阅中断。
                                    </div>
                                    <div class="ms-3">
                                        <a href="/user/invoice/{$pendingInvoice->id}/view" class="btn btn-warning btn-sm">
                                            <i class="ti ti-credit-card icon"></i>
                                            立即支付
                                        </a>
                                    </div>
                                </div>
                            </div>
                        {/if}

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="ti ti-id-badge icon me-2"></i>
                                    订阅详情
                                </h3>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-vcenter card-table">
                                    <tbody>
                                        <tr>
                                            <td class="text-secondary w-50">套餐名称</td>
                                            <td class="fw-medium">{$subscription->content->name}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-secondary">订阅状态</td>
                                            <td>
                                                {if $subscription->status === 'active'}
                                                    <span class="badge bg-success-lt">
                                                        <i class="ti ti-check icon me-1"></i>
                                                        {$subscription->status_text}
                                                    </span>
                                                {elseif $subscription->status === 'pending_renewal'}
                                                    <span class="badge bg-warning-lt">
                                                        <i class="ti ti-clock icon me-1"></i>
                                                        {$subscription->status_text}
                                                    </span>
                                                {else}
                                                    <span class="badge bg-secondary-lt">{$subscription->status_text}</span>
                                                {/if}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-secondary">账单周期</td>
                                            <td>{$subscription->billing_cycle_text}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-secondary">月流量额度</td>
                                            <td>{$subscription->content->bandwidth} GB</td>
                                        </tr>
                                        <tr>
                                            <td class="text-secondary">当前周期</td>
                                            <td>{$subscription->start_date} 至 {$subscription->end_date}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-secondary">下次流量重置</td>
                                            <td>{$subscription->next_reset_date}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-secondary">续费价格</td>
                                            <td>{$subscription->renewal_price} 元</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    {/if}
                </div>
            </div>
        </div>
    </div>

    {include file='user/footer.tpl'}
