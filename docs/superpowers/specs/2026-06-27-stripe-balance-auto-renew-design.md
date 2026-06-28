# 订阅自动续费(余额优先 + Stripe off-session 兜底 + 宽限期)设计文档

- 日期:2026-06-27
- 范围:给订阅(`subscription` 产品类型)加**自管自动续费**:到期当天先扣站内余额,余额不足再用 Stripe 存档卡 off-session 扣款;任一方式都失败则进入 **3 天宽限期(服务不中断)**,宽限内可手动补款续期,超期未付则终止降级、且续费账单作废不可再付。
- 取代方向:本设计**放弃 Stripe 原生订阅(Billing API / Checkout `mode:subscription` / 循环 Price / `invoice.paid` 推进周期)**,改为"自管续费 + Stripe 仅作存档卡支付工具"。前一版 `2026-06-26-stripe-auto-billing-design.md`(原生订阅方案)作废。

---

## 1. 为什么改方向

需求是"续费时**先扣余额、不够再走 Stripe**"。Stripe 原生订阅只会到点自动刷卡,无法先查站内余额,因此**无法在同一订阅上实现"余额优先 → 刷卡兜底"的瀑布**。所以续费时机与扣款方式必须由我们自己的 cron 决定,Stripe 退化为"存档卡的一次性 off-session 扣款"工具。

可复用的现有件:`StripeService`、`Exchange` + `PriceResolver::toMinorUnits`(CNY→外币 + 最小单位换算)、`user.stripe_customer_id`、`subscription.grace_until`、`stripe_event`(webhook 幂等表)、`Subscription` 模型与自管续费引擎骨架。

需要改造/移除:`OrderController::subscription` 的 Stripe 原生订阅分支、`PriceResolver::resolve`(循环 Price)、`WebhookHandler` 里 `invoice.paid(cycle)` / `customer.subscription.*` 的周期推进、`auto_renew_provider` 这个"下单选支付方式"的参数与门控。

---

## 2. 已确定的决策

| # | 决策 | 取值 |
|---|---|---|
| D1 | 续费机制 | 自管 cron;Stripe 仅作存档卡 off-session 扣款,**非**原生订阅 |
| D2 | 扣款瀑布 | 续费时**先扣 `user.money`(按 CNY `renewal_price`)**;不足再 off-session 扣存档卡(CNY→`stripe_currency` 换算) |
| D3 | 自动续费开关 | **默认开启(opt-out)**;用户可在"我的订阅"取消(`auto_renew=0`),到期自然过期 |
| D4 | 续费时机 | **到期当天**(`end_date = today`),每日 cron |
| D5 | 绑卡 | **可选**。卡在两处留下:首购走 Stripe 付首期时顺手存(`setup_future_usage=off_session`);或在独立"支付方式"页用 SetupIntent 主动绑。存档卡用 Stripe customer 默认 PM,不落本地新列 |
| D6 | 首购付款 | **余额优先**:余额够→扣余额开通(可不绑卡);不够→Stripe Checkout 付首期并存卡 |
| **D7** | **失败处理** | 余额不足且(无卡 或 扣卡被拒)→ 进入 **3 天宽限期,服务不中断**(不立即降级);宽限内**不自动重试**,用户可手动支付续费账单(余额或 Stripe);宽限内付清→续期 |
| **D8** | **失败通知** | 进入宽限时**发邮件**(提示 N 天内支付、附付款入口);终止时再发"订阅已失效"邮件 |
| **D9** | **超期终止** | 超过 `grace_until` 仍未付 → **终止归 0**(降级:`class=0` + 按现有过期逻辑重置流量) + `status='expired'`,并**把该续费 Order/Invoice 置 `expired`,使其不可再支付**(需重新购买) |
| D10 | 范围 | 仅 `subscription` 产品默认自动续费;一次性产品(流量包等)不受影响 |

---

## 3. 续费决策树(核心逻辑)

每日 cron,对 `status='active'`、`auto_renew=1`、`end_date = today` 的订阅:

```
├─ user.money ≥ renewal_price(CNY)
│     → 扣余额 + 记 UserMoneyLog + 标账单 paid_balance → 推进一个周期、续会员权益、保持 active ✓
└─ 余额不足:
   ├─ 有存档卡(customer 默认 PM)
   │     → off-session PaymentIntent(renewal_price 经 Exchange 换算成 stripe_currency 最小单位,confirm=true, off_session=true, 幂等键=invoice)
   │        ├─ 成功(payment_intent.succeeded webhook 确认)→ 推进一个周期、续会员权益、保持 active ✓
   │        └─ 被拒/需 3DS/无 PM → 进入宽限
   └─ 无存档卡 → 进入宽限
```

**进入宽限(服务不中断)**
- `status='pending_renewal'`、`grace_until = end_date + stripe_grace_days(默认 3)`。
- **保持账户有效**:把 `class_expire` 延至 `grace_until`(等价地,降级 cron 跳过宽限中的订阅),服务照常。
- 保留续费 `Order(pending_payment)` + `Invoice(unpaid)`(由 `generateRenewalOrder` 生成或即时生成)。
- **不降级、不断服、不自动重试**;发邮件提示在 `grace_until` 前支付。

**宽限内付清**(用户手动付该续费账单:余额点付 / Stripe Checkout / 其他网关)
- 走现有发票支付链 → 续期钩子:`end_date` 从原值顺延一个周期、按 `product_content` 续会员权益、`status='active'`。

**超期终止**(每日 cron:`status='pending_renewal'` 且 `grace_until < today`)
- `status='expired'`、降级(`class=0` + 重置流量)、把续费 `Order/Invoice` 置 `expired`(支付端拒绝再付)、发"已失效"邮件。

> 幂等:扣款(余额或卡)严格 gate 在"该续费 invoice 仍 `unpaid`"+ 事务行锁;off-session 扣款用 Stripe 幂等键(键于 invoice id),webhook 用 `stripe_event` 去重。双跑 cron / 重投 webhook 不重复扣款、不重复推进。

---

## 4. 数据模型(**基本无需新列**,复用 `2026062600` 已加列)

`2026062600-add-stripe-auto-billing.php` 已加:`subscription.{billing_provider, auto_renew, stripe_status, grace_until, stripe_amount, stripe_currency, last_paid_stripe_invoice_id, ...}`、`user.stripe_customer_id`、`order/invoice.billing_provider`、`stripe_event` 表、配置项。本方案直接复用:

- `subscription.grace_until` —— 宽限期截止(服务保留至此;之后终止)。
- `subscription.auto_renew` —— 是否自动续(默认开,取消置 0)。
- `user.stripe_customer_id` —— 服务端持有。**存档卡**用 Stripe customer 的 `invoice_settings.default_payment_method`,不落本地新列。
- 续费金额以 CNY `renewal_price` 为权威;卡扣外币金额记 `stripe_amount/stripe_currency` 供对账。
- `billing_provider` 在本方案取值简化:自动续订阅为 `manual`(瀑布:余额→卡),**不再有 `stripe` 原生订阅腿**。
- **不新增迁移列**(如实现期决定缓存 PM id 再单独加,可选)。

---

## 5. 流程细节

### 5.1 首次订阅(`OrderController::subscription` 改造)
1. 校验可订阅(无活跃订阅、已登录等,沿用现有)。算首期 CNY 价(`calculateCyclePrice`,含优惠码)。
2. 建 `Order`+`Invoice`(`product_type='subscription'`,默认 `auto_renew=1`)。
3. **余额优先**:`money ≥ 首期价` → 扣余额、标 `paid_balance` → 现有激活链开通;不强制绑卡。
4. 余额不足 → Stripe Checkout(`mode:'payment'`,`payment_intent_data.setup_future_usage='off_session'`,`customer=本人`),付首期同时存卡;`checkout.session.completed` / `payment_intent.succeeded` 确认后开通并设 customer 默认 PM。
5. 移除 `auto_renew_provider` 参数与"选 Stripe 原生订阅"分支。

### 5.2 独立绑卡页(账户内,新)
- `GET /user/payment-method` —— 显示当前卡(品牌/末四位)、绑定/更换/解绑。
- `POST /user/payment-method/setup-intent` —— `SetupIntent({customer:本人, usage:'off_session'})`,返回 `client_secret` + publishable key。
- 前端 Stripe.js + Payment Element → `confirmSetup` → **`setup_intent.succeeded` webhook** 里把 PM attach 到 customer、设为默认。**绝不信任前端"已成功"**;服务端断言 SetupIntent.customer == 本人。
- `POST /user/payment-method/detach` —— 解绑。

### 5.3 取消自动续费
- `POST /user/subscription/cancel` —— `auto_renew=0`(按 `user_id` 鉴权),到期不再续、自然过期。"我的订阅"加按钮。

### 5.4 续费 / 宽限 / 终止
落在 `SubscriptionService`:新增 `processAutoRenew()`(瀑布,§3)、`enterGrace()`(发失败邮件、设 grace_until、延 class_expire)、`renewOnPayment()`(宽限内付清续期,挂发票支付钩子)、`terminateLapsed()`(超期终止 + 作废账单 + 失效邮件)。

---

## 6. Webhook 事件(`WebhookHandler` 改造)

`/payment/notify/stripe` 沿用;`notify()` 用 `stripe_event.event_id` 去重后 `switch`:
- `payment_intent.succeeded` —— 确认首期/续费的扣款(off-session 续费、首购 Checkout),落账并触发开通或周期推进(按 metadata 关联本地 invoice/order/subscription,服务端反查校验 `customer` 归属)。
- `checkout.session.completed` / `checkout.session.async_payment_succeeded` / `..._failed` —— 首期 Checkout 完成/异步结果。
- `setup_intent.succeeded` —— 绑卡成功,设默认 PM。
- **移除** `invoice.paid` / `customer.subscription.updated|deleted` 等原生订阅事件处理。
- `BillingController::setStripeWebhook()` 注册事件同步改为上述集合。

---

## 7. 后台配置

- `stripe_auto_billing_enabled` —— **总开关**(关 → 自动续费停用,回退纯手动)。
- `stripe_grace_days` —— **宽限天数**(默认设为 **3**;名副其实)。
- 复用 `stripe_api_key` / `stripe_publishable_key` / `stripe_endpoint_secret` / `stripe_currency` / `subscription_renewal_days`。
- `balance_auto_renew_enabled` —— 新模型下余额本就是瀑布第一段,该开关弃用(保留空配置兼容)。
- 全部走已修复的 `/admin/setting/billing` 保存(`Config::set` null 合并已修)。

---

## 8. 安全基线

- **IDOR**:沿用现有"按 `user_id` 过滤 invoice"(已有 `StripePurchaseIdorTest` 保障);所有 `/user/*` 新端点一律 `where('user_id', 本人)` 定位,**绝不**信任前端传入的 `stripe_customer_id`/`subscription_id`/`payment_method`。
- **CSRF**:`/user` 写操作经现有 CSRF 中间件(`src/Middleware/CSRF.php` + `CsrfTest`)。
- **off-session 归属断言**:每个 webhook 按 Stripe id 反查本地行,断言 `customer == 本人 stripe_customer_id` 才动作。
- **密钥**:secret 仅服务端,publishable 才进前端;webhook 验签沿用 `Webhook::constructEvent`。

---

## 9. 已知局限 / 风险

- **off-session + 3DS**:部分卡 off-session 扣款会要求 3DS 而失败 → 按 D7 进入宽限(服务不断),邮件提示用户在宽限内**手动支付**(on-session,可完成 3DS)。宽限期正是这一场景的缓解。
- **FX 漂移**:卡扣按扣款当时汇率换算 CNY→外币(非锁定),与余额按 CNY 扣存在汇率差异——可接受。
- **续期锚点**:宽限内付清后,周期从原 `end_date` 顺延一个周期(宽限的免费天数不另补偿),内容(class/流量/速度)不变。

---

## 10. 测试策略(Pest + 打桩 `StripeService`;`config.value` 已对齐生产 NOT NULL)

- 续费瀑布:余额够→扣余额续期;余额不足+卡成功→续期;余额不足+卡被拒→进入宽限(**不降级**);无卡→进入宽限。
- 宽限:进入宽限后服务仍有效(`class_expire` 延至 `grace_until`、降级 cron 跳过);宽限内手动付清→续期回 active;**超 `grace_until` 未付→终止降级 + Order/Invoice 置 expired + 该账单不可再支付**。
- 首购:余额够→扣余额开通(无卡);余额不足→走 Checkout(存卡)。
- 绑卡:SetupIntent 成功→设默认 PM;解绑;越权(不能 attach 到他人 customer)。
- 幂等:同一续费 invoice 双跑 cron 不双扣;重投 `payment_intent.succeeded` no-op;off-session 幂等键。
- 取消:`auto_renew=0` 后到期不续、自然过期。
- 不变量:一次性产品不受自动续费影响。

---

## 11. 受影响文件清单(实现期展开)

| 文件 | 改动 |
|---|---|
| `src/Services/Stripe/PriceResolver.php` | 去掉循环 Price 创建;保留/抽出 `toMinorUnits` + Exchange 换算供一次性扣款 |
| `src/Services/Stripe/StripeService.php` | 加 `ensureCustomer`、`createSetupIntent`、`chargeOffSession`、首购 Checkout(setup_future_usage) |
| `src/Services/Stripe/WebhookHandler.php` | 改为 payment_intent/checkout/setup_intent 事件;移除原生订阅事件;保留 `stripe_event` 去重 |
| `src/Services/SubscriptionService.php` | `processAutoRenew()`(瀑布)、`enterGrace()`、`renewOnPayment()`、`terminateLapsed()`;`generateRenewalOrder` 适配;移除原生腿护栏 |
| `src/Services/Cron.php` / `src/Command/Cron.php` | 每日调度新增自动续/终止;`expirePaidUserAccount` 跳过条件改为"有活跃/宽限中的自管订阅" |
| `src/Controllers/User/OrderController.php` | 首购余额优先 + 不足转 Checkout;去掉 `auto_renew_provider` 分支;默认 `auto_renew=1` |
| `src/Controllers/User/SubscriptionController.php`(或现有) | `cancel`;"我的订阅"概览(状态/宽限/到期) |
| `src/Controllers/User/PaymentMethodController.php` | 新建:绑卡页 + setup-intent + detach |
| `src/Controllers/Admin/Setting/BillingController.php` | webhook 事件集合改为新集合 |
| `app/routes.php` | `/user/payment-method/*`、`/user/subscription/cancel` 路由 |
| `src/Models/Invoice.php` / 发票支付端 | `expired` 账单拒绝支付;宽限内可付、超期作废 |
| `resources/views/tabler/user/order/create.tpl` | 去掉(不存在的)provider 选择;显示"默认自动续费"说明 |
| `resources/views/tabler/user/subscription.tpl` | 取消按钮 + 宽限/失效状态展示 |
| `resources/views/tabler/user/payment_method.tpl` | 新建:Stripe Elements 绑卡 |
| `resources/views/email/subscription_renewal_failed.tpl` / `subscription_expired.tpl` | 新建:进入宽限提醒(含付款入口)/ 终止失效通知 |
| `tests/...` | §10 |

> 数据模型基本复用 `2026062600` 已加列,**通常无需新迁移**(如实现期决定缓存 PM id 再加)。

---

## 12. 明确不在本期范围(YAGNI)

- 换套餐(升/降级)、推荐返利改造、存量原生订阅迁移、退款/争议同步、用量计费、税务、Stripe Customer Portal。
- 不接入 `invoice.*` / `customer.subscription.*` / `charge.refunded` / `dispute` 处理。
