# 邮件模板体系重做(Lagom 风格)— 设计文档

日期:2026-07-10。已获用户批准(主色 Tabler 蓝、全部 11 封 + new_user 实装、组件化底座)。

## 目标

把 `resources/email/` 下全部邮件换成参考 Lagom(WHMCS 主题)风格的新视觉体系:顶部品牌色带 + 渐变 Hero 标题卡 + 信息卡片(KV 行、语义色状态)+ 实心 CTA 按钮;并为订阅系列注入结构化字段与支付链接,实装注册欢迎邮件(new_user)。

## 现状要点(盘点结论)

- 渲染机制:`Mail::genHtml($template, $ary)`(src/Services/Mail.php:47-60)— 独立 Smarty 实例,模板目录 `resources/email/`,自动 assign `config`(View::getConfig(),含 appName/baseUrl)+ 调用方变量。**可直接用于 Pest 渲染测试**。
- 两条发送路径:`Mail::send` 直发(验证码/密码重置/测试);`EmailQueue::add($to,$subject,$template,$array)` 入队,cron 每 5 分钟批发。
- `Notification::notifyUser/notifyAdmin/notifyAllUser($user,$title,$msg,$template)` 固定打包 `['user','title','text']`;`contact_method!=1 且绑了 IM` 的用户走 IM 纯文本,不发邮件。
- 旧模板问题:div 假表格(table 属性挂在 div 上无效)、Google Fonts、订阅系列只有一段 `{$text}` 无 CTA/金额/日期、warn.tpl 标题写死「系统提示」、new_user.tpl 零引用。
- 旧 header/footer 有「标签跨文件不闭合」契约(warn 等正文模板不闭合 wrapper,由 footer 统一闭合)。新体系保留同样的 include 契约但必须在组件内注释说明。

## 视觉规范

- 画布:页底 `#f4f5f7`,内容 600px 居中,纯 `<table>` 布局 + 内联样式;禁用外部字体,字体栈 `-apple-system,'Segoe UI','PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif`。
- 顶部色带:`#206bc4`;左 logo `{$config['baseUrl']}/images/uim-logo-round_192x192.png`(显示 36px)+ appName 白字 18px 加粗;右侧白色小字链接「前往 {appName}」→ baseUrl。
- Hero 卡:位于色带内底部,圆角 8px,`background:#2f7ac9;background:linear-gradient(135deg,#1a5db0,#3d8fd1);`(Outlook 降级纯色),白色 26px 加粗标题居中,内边距约 36px。不做负 margin 悬浮(邮件兼容差),色带包卡近似参考图。
- 信息卡组件:1px `#e6e7e9` 边框、圆角 6px;可选浅灰(#f8f9fa)标题行 + 分隔线;KV 行 = 标签 `#475467` 常规 + 值加粗;语义色:green `#2e7d32` / red `#c62828` / orange `#ef6c00`。
- CTA:防弹按钮(table+bgcolor+a inline-block),主色 `#206bc4`,圆角 6px,居中。
- 问候:正文左对齐,`你好,{user_name}` 18px 加粗开头(无 user 上下文的信用通用问候)。
- 页脚:居中灰色小字:appName 链接 | 「修改邮件接收设置」→ `{baseUrl}/user/edit`;附「系统邮件请勿直接回复」。
- 预览文案:header 支持可选 `$preheader` 隐藏 div。
- 不做深色模式适配(客户端强制反色不可控),白底黑字保底。

## 组件(`resources/email/components/`)

| 文件 | 参数 | 职责 |
|---|---|---|
| header.tpl | `$preheader`(可选) | `<html><head>`(仅 meta,无 style 块依赖)+ 打开 body/画布/600px 容器 + 色带 logo 行 |
| hero.tpl | `$hero_title` | 色带内渐变标题卡,并结束色带、打开白色正文区 |
| card.tpl | `$card_title`(可选)、`$card_rows`(数组:label/value/color) | 信息卡 |
| button.tpl | `$btn_text`、`$btn_url`、`$btn_color`(可选,默认主色) | CTA 按钮 |
| footer.tpl | — | 关闭正文区 + 页脚 + 闭合全部标签 |

契约:每封邮件 = `header → hero → 正文(自由 HTML + card/button include)→ footer`;header/hero 打开的容器由 footer 统一闭合,组件内注释说明标签平衡,内容模板一律不自行补闭合。

## 12 封邮件的针对性设计

(全部换新底座;正文文案如下,执行时逐字使用)

1. **verify_code.tpl** — Hero「邮箱验证码」;通用问候「你好,」;正文「感谢注册 {appName},你的邮箱验证码是:」;大号验证码块(浅蓝底 `#ecf3fb` 圆角、28px 等宽加粗、字距 6px,居中):`{$code}`;下方灰字「验证码有效期至 {$expire},请勿泄露给他人。」;辅助文「如非本人操作,请忽略此邮件。」;无 CTA。
2. **password_reset.tpl** — Hero「重置密码」;通用问候;正文「我们收到了你的密码重置请求,点击下方按钮设置新密码:」;CTA「重置密码」→ `{$resetUrl}`(变量名与 Services/Password.php 现状一致);辅助文「链接在有效期内一次有效;如非本人操作,请忽略此邮件,你的密码不会被更改。」。
3. **new_user.tpl(实装)** — Hero「欢迎加入 {appName}」;问候「你好,{user_name}」;正文「你的账户已创建成功,以下是账户信息:」;卡片:邮箱 / 注册时间;CTA「进入用户中心」→ `{baseUrl}/user`;辅助文「如需帮助,请通过工单联系我们。」。**接入点**:AuthController::registerHelper 内 `$user->save() && !$is_admin_reg` 成功分支(约 286 行)入队 EmailQueue(主题 `{appName}-欢迎加入`,变量 user + reg_time)。
4. **subscription_renewal.tpl(续费提醒)** — Hero「订阅续费提醒」;问候;正文保留调用方 `{$text}` 一段;卡片「续费订单」:套餐 `{$plan_name}` / 计费周期 `{$billing_cycle_text}` / 金额 `{$amount} 元`(orange) / 本期到期日 `{$end_date}` / 订单号 `#{$order_id}`;CTA「立即支付」→ `{$invoice_url}`;辅助文「订阅默认自动续费:到期时优先扣账户余额,其次扣已绑定的银行卡;手动支付后自动续费本期不再执行。」。
5. **subscription_reminder.tpl(二次提醒)** — Hero「续费订单待支付」;同上卡片;正文 `{$text}`;CTA「立即支付」→ `{$invoice_url}`;辅助文「若未在到期前完成支付,服务将在宽限期后中断。」。
6. **subscription_renewal_failed.tpl** — Hero「订阅续费失败」;正文 `{$text}`(调用方已区分余额不足/扣卡失败);卡片:套餐 / 金额(red)/ 宽限截止 `{$grace_until}`(red);CTA「前往支付」→ `{$invoice_url}`;辅助文「在宽限期内完成支付即可无缝续期。」。
7. **subscription_expired.tpl** — Hero「订阅已过期」;正文 `{$text}`;卡片:套餐 / 过期日 `{$end_date}`(red)/ 状态「已过期」(red);CTA「重新订阅」→ `{baseUrl}/user/product`;辅助文「重新购买后服务立即恢复。」。
   - 订阅四封的结构化变量由 `SubscriptionService` 四个通知点补传(对象字段现成:subscription->product_content 里 name、billing_cycle、renewal_price、end_date、grace_until、order/invoice id);**任一结构化变量缺失时模板须容错**(Smarty `{if isset(...)}` 包卡片行),cron 老队列里的历史邮件参数不含新字段,不能炸队列。
8. **traffic_report.tpl** — Hero「每日流量报告」;问候 `{$user->user_name}`;卡片:昨日用量 `{$lastday_traffic}` / 已用 `{$used_traffic}` / 剩余 `{$unused_traffic}` / 总量 `{$enable_traffic}`;**用量百分比条**(嵌套 table 宽度百分比实现,≥80% 红色 `#c62828`,否则主色;百分比由模板内基于 `{$used_pct}` 渲染,PHP 补传该整数,缺失则不渲染进度条);正文尾保留 `{$text}`(站点公告);CTA「查看用量详情」→ `{baseUrl}/user`。
9. **finance.tpl(管理员)** — Hero `{$title}`;正文 `{$text}` 原样装入信息卡(text 为 Cron 预格式化内容);无 CTA。
10. **warn.tpl(通用)** — Hero `{$title|default:'系统提示'}`(参数化);问候(有 `$user` 时带用户名);正文 `{$text}`;无 CTA。
11. **test.tpl** — Hero「测试邮件」;正文「如果你收到这封邮件,说明邮件发送配置正确。」+ 卡片:发送时间(模板内 `{$smarty.now|date_format:'%Y-%m-%d %H:%M:%S'}`);无 CTA。
12. 旧 `header.tpl`/`footer.tpl` 删除(被 components/ 取代);所有内容模板同轮切换。

## PHP 侧改动

1. `Notification::notifyUser/notifyAdmin/notifyAllUser` 增加可选参数 `array $extra = []`,与 `['user','title','text']` 合并后入队(extra 不覆盖三个基础键);IM 分支行为不变。
2. `SubscriptionService` 四个通知点(generateRenewalOrder / sendSecondRenewalNotification / enterGrace(renewal_failed) / expireSubscription+terminateLapsed)传 extra:plan_name、billing_cycle_text(月付/季付/年付)、amount、end_date、grace_until(仅 failed)、order_id、invoice_url(`$_ENV['baseUrl'].'/user/invoice/'.$invoiceId.'/view'`)。以调用点现有对象为准,取不到的字段不传(模板容错)。
3. `AuthController::registerHelper`:注册成功(非 admin 创建)入队欢迎邮件。
4. `User::sendDailyTrafficReport`(Models/User.php:283 起)补传 `used_pct`(0-100 int)。

## 测试

- 渲染冒烟(新增 `tests/Unit/Views/EmailTemplatesRenderTest.php`):对 12 个模板逐一 `Mail::genHtml($tpl, $stubVars)`,断言不抛异常、输出含关键内容(标题文案/金额/链接/验证码),并断言**标签平衡**(`<body`/`</body>`、div 开闭数量一致)。订阅四封各测两组:全字段 + 缺结构化字段(容错路径)。
- PHP:notifyUser $extra 合并断言(EmailQueue 行 array JSON 断言);注册欢迎邮件入队断言;订阅通知点 extra 内容断言(复用 AutoRenew 测试基建)。
- 验收:渲染 12 封输出 HTML 至 scratchpad 截图供用户过目;测试服后台「发送测试邮件」实测。

## 约束

- 不新增 composer 依赖;`declare(strict_types=1)`;遵循现有代码风格。
- EmailQueue 兼容:老队列行(旧参数结构)在新模板下渲染不得抛异常(容错 isset)。
- 不动 IM 通知路径与发送驱动。
