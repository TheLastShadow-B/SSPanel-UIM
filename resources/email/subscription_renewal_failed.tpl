{include file='header.tpl'}

<body style="background-color:#EEEEEE;">
    <div style="text-align: center">
        <div border="0" cellpadding="0" cellspacing="0" width="100%" style="padding-top:30px;table-layout:fixed;background-color:#EEEEEE;">
            <div align="center" valign="top" style="padding-right:10px;padding-left:10px;">
                <div border="0" cellpadding="0" cellspacing="0" style="background-color:#FFFFFF;max-width:600px;text-align:center;" width="100%">
                    <div align="center" valign="top">
                        <div border="0" cellpadding="0" cellspacing="0" width="100%">
                            <div align="center" valign="middle" style="padding-top:60px;padding-bottom:60px;">
                                <h2 class="bigTitle">
                                    订阅续费失败通知
                                </h2>
                            </div>
                        </div>
                        <div border="0" cellpadding="0" cellspacing="0" style="background-color:#FFFFFF" width="100%">
                            <div align="center" valign="top" style="padding-bottom:60px;padding-left:20px;padding-right:20px;">
                                <p class="midText">
                                    {$text}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

{* 外层 <body> 与 3 个布局 wrapper <div> 在此故意不闭合，由 footer.tpl 统一闭合
   （footer 末尾额外的 3 个 </div> + </body></html>）——与全部 subscription_*.tpl 共用同一套
   header/footer 布局契约。整封邮件 header+body+footer 渲染后标签是平衡的（13/13 个 div、1/1 个
   body）。切勿在此补 </div>/</body>，否则会与 footer 重复闭合、产生畸形 HTML。 *}
{include file='footer.tpl'}
