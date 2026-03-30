{include file='admin/header.tpl'}

<div class="page-wrapper">
    <div class="container-xl">
        <div class="page-header d-print-none text-white">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">
                        <span class="home-title">订阅管理</span>
                    </h2>
                    <div class="page-pretitle my-3">
                        <span class="home-subtitle">在这里管理用户订阅</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-12">
                    <div class="card">
                        <div class="table-responsive">
                            <table id="data-table" class="table card-table table-vcenter text-nowrap datatable">
                                <thead>
                                <tr>
                                    {foreach $details['field'] as $key => $value}
                                        <th>{$value}</th>
                                    {/foreach}
                                </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {include file='datatable.tpl'}

    <script>
        tableConfig.ajax = {
            url: '/admin/subscription/ajax',
            type: 'POST',
            dataSrc: 'subscriptions'
        };
        tableConfig.order = [
            [1, 'desc']
        ];
        tableConfig.columnDefs = [
            {
                targets: [0],
                orderable: false
            }
        ];

        let table = new DataTable('#data-table', tableConfig);

        function loadTable() {
            table;
        }

        function cancelSubscription(subscription_id) {
            $('#notice-message').text('确定取消此订阅？取消后用户将被降级。');
            $('#notice-dialog').modal('show');
            $('#notice-confirm').off('click').on('click', function () {
                $.ajax({
                    url: "/admin/subscription/" + subscription_id + "/cancel",
                    type: 'POST',
                    dataType: "json",
                    success: function (data) {
                        if (data.ret === 1) {
                            $('#success-message').text(data.msg);
                            $('#success-dialog').modal('show');
                            reloadTableAjax();
                        } else {
                            $('#fail-message').text(data.msg);
                            $('#fail-dialog').modal('show');
                        }
                    }
                })
            });
        }

        function reloadTableAjax() {
            table.ajax.reload(null, false);
        }

        loadTable();
    </script>

    {include file='admin/footer.tpl'}
