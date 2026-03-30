{include file='admin/header.tpl'}

<div class="page-wrapper">
    <div class="container-xl">
        <div class="page-header d-print-none text-white">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">
                        <span class="home-title">订阅 #{$subscription->id}</span>
                    </h2>
                    <div class="page-pretitle my-3">
                        <span class="home-subtitle">编辑订阅信息</span>
                    </div>
                </div>
                <div class="col-auto ms-auto d-print-none">
                    <div class="btn-list">
                        <a id="save-subscription" href="#" class="btn btn-primary">
                            <i class="icon ti ti-device-floppy"></i>
                            保存
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-6 col-sm-12">
                    <div class="card">
                        <div class="card-header card-header-light">
                            <h3 class="card-title">订阅详情</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-3 row">
                                <label class="form-label col-3 col-form-label">订阅ID</label>
                                <div class="col">
                                    <input type="text" class="form-control" value="{$subscription->id}" disabled>
                                </div>
                            </div>
                            <div class="form-group mb-3 row">
                                <label class="form-label col-3 col-form-label">用户ID</label>
                                <div class="col">
                                    <input type="text" class="form-control" value="{$subscription->user_id}" disabled>
                                </div>
                            </div>
                            <div class="form-group mb-3 row">
                                <label class="form-label col-3 col-form-label">用户邮箱</label>
                                <div class="col">
                                    <input type="text" class="form-control" value="{$user->email}" disabled>
                                </div>
                            </div>
                            <div class="form-group mb-3 row">
                                <label class="form-label col-3 col-form-label">套餐名称</label>
                                <div class="col">
                                    <input type="text" class="form-control" value="{$subscription->content->name}" disabled>
                                </div>
                            </div>
                            <div class="form-group mb-3 row">
                                <label class="form-label col-3 col-form-label">账单周期</label>
                                <div class="col">
                                    <input type="text" class="form-control" value="{$subscription->billing_cycle_text}" disabled>
                                </div>
                            </div>
                            <div class="form-group mb-3 row">
                                <label class="form-label col-3 col-form-label">状态</label>
                                <div class="col">
                                    <input type="text" class="form-control" value="{$subscription->status_text}" disabled>
                                </div>
                            </div>
                            <div class="form-group mb-3 row">
                                <label class="form-label col-3 col-form-label">开始日期</label>
                                <div class="col">
                                    <input type="text" class="form-control" value="{$subscription->start_date}" disabled>
                                </div>
                            </div>
                            <div class="form-group mb-3 row">
                                <label class="form-label col-3 col-form-label">到期日期</label>
                                <div class="col">
                                    <input type="text" class="form-control" value="{$subscription->end_date}" disabled>
                                </div>
                            </div>
                            <div class="form-group mb-3 row">
                                <label class="form-label col-3 col-form-label">流量重置日</label>
                                <div class="col">
                                    <input type="text" class="form-control" value="{$subscription->reset_day}" disabled>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-sm-12">
                    <div class="card">
                        <div class="card-header card-header-light">
                            <h3 class="card-title">价格设置</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-3 row">
                                <label class="form-label col-3 col-form-label required">续费价格</label>
                                <div class="col">
                                    <input id="renewal_price" type="text" class="form-control"
                                           value="{$subscription->renewal_price}">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    $("#save-subscription").click(function () {
        $.ajax({
            url: '/admin/subscription/{$subscription->id}/price',
            type: 'PUT',
            dataType: "json",
            data: {
                renewal_price: $('#renewal_price').val(),
            },
            success: function (data) {
                if (data.ret === 1) {
                    $('#success-message').text(data.msg);
                    $('#success-dialog').modal('show');
                    window.setTimeout("location.href=top.document.referrer", {$config['jump_delay']});
                } else {
                    $('#fail-message').text(data.msg);
                    $('#fail-dialog').modal('show');
                }
            }
        })
    });
</script>

{include file='admin/footer.tpl'}
