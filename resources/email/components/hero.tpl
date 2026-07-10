{* 渐变 Hero 标题卡。参数:$hero_title(必填)。
   契约:渲染标题卡 → 闭合 header 留下的色带 td/tr → 间隔行 → 打开白色正文
   <tr><td>(不闭合,footer 统一收)。 *}
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:6px 0 26px;">
                            <tr>
                                <td align="center" style="border-radius:8px;background-color:#2f7ac9;background:linear-gradient(135deg,#1a5db0 0%,#3d8fd1 100%);padding:38px 24px;">
                                    <h1 style="margin:0;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:26px;line-height:34px;font-weight:700;color:#ffffff;">{$hero_title}</h1>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="height:16px;line-height:16px;font-size:0;">&nbsp;</td>
                </tr>
                <tr>
                    <td align="left" style="background-color:#ffffff;border-radius:10px;padding:32px 40px 36px;font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">
