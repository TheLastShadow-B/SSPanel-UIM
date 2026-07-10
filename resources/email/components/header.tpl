{* 邮件底座 · 画布 + 600px 容器 + 顶部色带 logo 行。
   标签契约:本文件打开 <html><body>、画布 table(#1 tr td)、640px table(#2)、
   色带 <tr><td>(不闭合);hero.tpl 负责结束色带并打开白色正文区;
   footer.tpl 统一闭合全部容器。内容模板不得自行闭合任何容器标签。
   可选参数:$preheader(收件箱预览文案,隐藏渲染)。 *}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta http-equiv="X-UA-Compatible" content="IE=edge"/>
    <title>{$config['appName']}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;-webkit-text-size-adjust:100%;">
{if isset($preheader)}<div style="display:none;font-size:1px;color:#f4f5f7;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{$preheader}</div>{/if}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f5f7;">
    <tr>
        <td align="center" valign="top" style="padding:0 10px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;">
                <tr>
                    <td style="background-color:#206bc4;border-radius:0 0 10px 10px;padding:0 20px;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                            <tr>
                                <td align="left" style="padding:18px 4px;">
                                    <a href="{$config['baseUrl']}" target="_blank" style="text-decoration:none;">
                                        <img src="{$config['baseUrl']}/images/uim-logo-round_192x192.png" width="36" height="36" alt="{$config['appName']}" style="vertical-align:middle;border:0;border-radius:50%;"/>
                                        <span style="font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:18px;font-weight:700;color:#ffffff;vertical-align:middle;padding-left:10px;">{$config['appName']}</span>
                                    </a>
                                </td>
                                <td align="right" style="padding:18px 4px;">
                                    <a href="{$config['baseUrl']}" target="_blank" style="font-family:-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;font-size:12px;color:#d7e5f7;text-decoration:none;">前往 {$config['appName']} →</a>
                                </td>
                            </tr>
                        </table>
