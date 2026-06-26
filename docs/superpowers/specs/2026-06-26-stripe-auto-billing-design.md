# Stripe 自动扣费 + 余额自动扣 设计文档

- 日期:2026-06-26
- 范围:为 SSPanel-UIM 的订阅(`subscription` 产品类型)增加 **Stripe 原生自动续费** 与 **余额自动扣费** 两条自动续费腿,同时完整保留现有手动支付。
- 本文档是实现计划(plan)的输入,已吸收一次 13-agent 落地核实 + 对抗式审查(48 个问题,24 个高危)的结论。

---

## 1. 背景与现状

SSPanel-UIM 已有一套**自建的订阅 + 续费引擎**,但续费是"手动"的:

- 模型:`Subscription`(`db/migrations/2026033000-add-subscription-system.php:14-32`),状态机 `active → pending_renewal → expired/cancelled`,`billing_cycle ∈ {month,quarter,year}`。
- 引擎:`src/Services/SubscriptionService.php`
  - `processNewSubscriptionActivation()`(:58-127):每 5 分钟,把已支付的订阅订单激活成 `Subscription`,并写会员权益到 `user`(:106-116:`class / class_expire / transfer_enable / node_group / node_speedlimit / node_iplimit`,流量清零)。
  - `generateRenewalOrder()`(:230-314):每天,到期前 `subscription_renewal_days` 天生成续费 `Order`(`pending_payment`)+ `Invoice`(`unpaid`),订阅置 `pending_renewal`,发提醒。
  - `processRenewalActivation()`(:132-179):每 5 分钟,续费订单支付后推进 `end_date` 与 `class_expire`。**注意:无"本期是否已激活"幂等护栏(:140-176)**。
  - `expireSubscription()`(:374-443):每天,`pending_renewal` 且 `end_date=today` 未付 → 取消订单/账单 + **降级用户**(class=0 等)。**无宽限期**。
  - `resetSubscriptionBandwidth()`(:184-225):每天按 `reset_day` 重置流量。
- 现有 Stripe 网关:`src/Services/Gateway/Stripe.php`,目前仅 **一次性** Checkout(`mode:'payment'`),`notify()`(:148-175)只处理 `payment_intent.succeeded → Base::postPayment()`。
- 支付确认:`Base::postPayment()`(`src/Services/Gateway/Base.php:53-91`)更新 `Paylist`/`Invoice`,溢付入 `money`,并发**推荐返利**(:88-90,经 `Reward::issuePaybackReward`,按 `(userid, invoice_id)` 去重 `src/Services/Reward.php:18-29`)。
- 桥接 job:`src/Services/Cron.php` 的 `processPendingOrder()`(:410-428)每 5 分钟把"已付款的 `pending_payment` 订单"翻成 `pending_activation`。
- 第二条降级路径:`Cron::expirePaidUserAccount()`(`src/Services/Cron.php:156-195`)每 5 分钟降级 `class_expire < now()` 的用户,**仅跳过本地状态为 active/pending_renewal 的订阅用户**(:163-170)。

要补的就是**最后一环:到期自动扣款**,且分两条腿(Stripe 扣卡 / 余额自扣),并新增换套餐与用户自助管理。

---

## 2. 已锁定的决策

| # | 决策 | 取值 |
|---|---|---|
| D1 | 自动扣费机制 | **Stripe 原生订阅**(Billing API + Checkout `mode:'subscription'` + 循环 `Price`),Stripe 是该腿的真相源 |
| D2 | 保留余额支付 | 保留;且**余额也支持自动扣**(独立 cron 自动用 `money` 结算续费账单) |
| D3 | 币种 | 沿用 `stripe_currency`,创建订阅时把 CNY 经 Exchange 换算并**锁定**为该币种的循环 Price |
| D4 | 用户自助管理界面 | **面板内自建页面**(改卡 / 退订 / 发票列表),改卡用 Stripe Elements;3DS 恢复借 `hosted_invoice_url` |
| D5 | 退款 | **网站不做退款功能**。禁止对 Stripe 账单使用 `refundToBalance`;争议/退款一律 Stripe 后台人工 |
| D6 | 换套餐(升/降级) | **进 v1**。升级=立即生效+按比例补差价;降级=下一周期生效(契合"不退款") |
| D7 | 推荐返利 | **仅首次订阅返**,返到**网站余额**;续费不返 |
| D8 | 存量迁移 | **支持中途转入** Stripe 自动续(SetupIntent 存卡 + `billing_cycle_anchor` 对齐),不双扣、不丢时间;复用现有订阅行,不新建 |

---

## 3. 核心架构原则(解决"真相源冲突")

> **铁律:每个订阅在创建时打 `billing_provider`,并把该标记同时落到 `subscription`、`order`、`invoice` 三张表。自建 cron 引擎只用「正向匹配」处理 `manual` / `balance` 行,绝不触碰 `stripe` 行;Stripe 订阅的 `end_date` / `class_expire` / 状态只能由 webhook 写。**

`billing_provider ∈ {manual, balance, stripe}`:

- `manual` — 手动支付(余额点付 / 支付宝 / EPay / 一次性 Stripe),沿用现有引擎,完全不变。
- `balance` — 余额自动扣:现有引擎生成续费账单,新 job 自动用 `money` 结算。
- `stripe` — Stripe 原生自动续:Stripe 是真相源,**不生成本地续费账单**,全部由 webhook 驱动。

**为什么必须落到 order/invoice 且用正向匹配**(审查 #1/#6/#21):
1. 自建 cron 实际查询的是 `order` 表(`processNewSubscriptionActivation` `:60-64`、`processRenewalActivation` `:134-138`);首购订单创建时还没有 subscription 行可 join,故标记必须直接在 order 上。
2. 结算竞争发生在 `invoice` 行上(余额 job vs Stripe webhook vs 手动 payBalance),故 invoice 也要带 `billing_provider`,每个结算方动手前断言匹配。
3. 写成 `!= 'stripe'` 会踩 MySQL 三值逻辑:存量 NULL 行 `NULL != 'stripe'` 结果为 NULL(非 TRUE),导致**老订阅永不续费也永不降级**。因此一律用正向匹配 `whereIn('billing_provider', ['manual','balance'])`,且迁移回填 + 新建时显式盖值。

---

## 4. 数据模型(新迁移,遵循 `2026033000` 命名与 `MigrationInterface` 约定)

新文件:`db/migrations/2026062600-add-stripe-auto-billing.php`

### 4.1 `user`
- `stripe_customer_id` VARCHAR(64) NULL —— 服务端持有;**任何接口都不接受前端传入**。

### 4.2 `subscription`
- `billing_provider` VARCHAR(16) **NOT NULL DEFAULT 'manual'**
- `auto_renew` TINYINT(1) **NOT NULL DEFAULT 0**(存量显式 opt-out)
- `stripe_subscription_id` VARCHAR(64) NULL,UNIQUE(允许多 NULL)
- `stripe_status` VARCHAR(24) NULL —— 镜像 Stripe 订阅状态(active/past_due/canceled/...)
- `grace_until` DATETIME NULL —— 进入 past_due 后的宽限截止
- `hosted_invoice_url` VARCHAR(512) NULL —— 失败账单的 3DS/补缴托管页链接
- `stripe_amount` BIGINT NULL + `stripe_currency` VARCHAR(8) NULL —— 锁定的实际外币扣款金额(最小货币单位),用于审计对账(CNY 的 `renewal_price` 仍保留给 balance/manual)

### 4.3 `order` 与 `invoice`
- 各加 `billing_provider` VARCHAR(16) NOT NULL DEFAULT 'manual'(创建时落)。

### 4.4 新表 `stripe_event`(webhook 幂等)
```
id BIGINT PK AUTO_INCREMENT
event_id VARCHAR(64) NOT NULL UNIQUE   -- Stripe evt_xxx
type VARCHAR(64) NOT NULL
created_at DATETIME NOT NULL
```

### 4.5 迁移 `up()` 必做
1. 上述 `ALTER TABLE` / `CREATE TABLE`。
2. **回填**:`UPDATE subscription SET billing_provider='manual' WHERE billing_provider IS NULL OR billing_provider='';` 同理 order/invoice。
3. 种子配置行(见 §11)。

### 4.6 配套代码改动
- `src/Services/SubscriptionService.php:91-104` 新建订阅时显式 `billing_provider='manual'`(除非由 Stripe/balance 流程指定)。

---

## 5. 三条腿的生命周期

### 5.1 Stripe 原生(自动扣卡)——Stripe 为唯一真相源

**购买**(新方法,建议 `src/Services/Gateway/Stripe.php` 增 `purchaseSubscription()`,或新 `StripeSubscription` 服务):
1. 确保有 `user.stripe_customer_id`(无则 `customers.create({email})` 并存)。
2. 取/建对应的循环 `Price`:按 D3 把产品 CNY 价经 Exchange 换算成 `stripe_currency` 的金额(注意零小数币种 JPY/VND 不 ×100,与现有 `Stripe.php` 逻辑一致),`recurring.interval` 由 `billing_cycle` 映射(month=1mo,quarter=3mo,year=1yr)。Price 可缓存复用(产品+周期+币种+金额做 key)。
3. `checkout.sessions.create({ mode:'subscription', customer, line_items:[{price, quantity:1}], subscription_data:{ metadata:{ sspanel_user_id, product_id, billing_cycle } }, success_url, cancel_url })`。**不传 `payment_method_types`**(动态支付方式)。
4. order/invoice 落 `billing_provider='stripe'`。

**首期开通只由一个事件负责**(审查 #3):
- `checkout.session.completed` —— **唯一**负责"创建本地 `Subscription` + 写 `stripe_subscription_id` 映射 + 首期开通会员权益"(幂等于 `stripe_subscription_id` 唯一约束)。
- `invoice.paid`(`billing_reason='subscription_create'`)—— 对日期**不再延期**(no-op for dates),仅落账/对账。

**续期**:
- `invoice.paid`(`billing_reason='subscription_cycle'`)—— 推进 `subscription.end_date` 与 `user.class_expire`、按需重置流量(镜像 `processRenewalActivation` `:156-168` 的赋值,但以 Stripe 周期为准)。

**失败 / SCA**(审查 #12/#13/#14):
- `invoice.payment_failed` 或 `invoice.payment_action_required` → `stripe_status='past_due'`,设 `grace_until = now + stripe_grace_days`,存 `hosted_invoice_url`。**期间保持服务**,面板显示补缴横幅(链接到 `hosted_invoice_url`,off-session 3DS 唯一可行做法)。
- **降级只在** `customer.subscription.deleted`(Stripe 智能重试耗尽/到期取消后)触发 → `subscription.status='expired'` + 降级用户。

**状态映射表(写进迁移注释 + 代码)**:

| Stripe 事件 | `subscription.status` | `stripe_status` | 会员动作 |
|---|---|---|---|
| checkout.session.completed | active | active | 首期开通 |
| invoice.paid (cycle) | active | active | 推进 end_date/class_expire |
| invoice.payment_failed / action_required | active(不变) | past_due | 设 grace_until + 存 hosted_invoice_url,**不降级** |
| customer.subscription.deleted | expired | canceled | **降级** |
| customer.subscription.updated | (按需同步 cancel_at_period_end 等) | 同步 | — |

### 5.2 余额自动扣 —— 独立幂等 job(审查 #22)

- 现有 `generateRenewalOrder()` 照常为 `balance`/`manual` 订阅生成续费 `Order`(pending_payment)+ `Invoice`(unpaid)。
- **新增** `SubscriptionService::deductRenewalFromBalance()`(每天,排在 `expireSubscription` 与 `generateRenewalOrder` **之后**):
  1. 查 `billing_provider='balance'` 且 `auto_renew=1` 的订阅的**未付**续费 invoice。
  2. 在 **DB 事务 + `SELECT ... FOR UPDATE`** 锁该 invoice 行,**再次确认** `status='unpaid'` 且 `user.money >= price`。
  3. 满足:扣 `money`、写 `UserMoneyLog`、`invoice.status='paid_balance'`。**不直接改 order 状态**——交给现有 `processPendingOrder()`(`src/Services/Cron.php:410-428`)桥接到 `pending_activation`,再由 `processRenewalActivation()` 续期。
  4. 不满足(余额不足):发通知,回退手动;到期仍未付 → `expireSubscription()` 降级。
- 幂等:扣款严格 gated 在"invoice 仍 unpaid"+ 行锁,双跑 cron 不会重复扣。

### 5.3 手动 —— 完全不变

余额点付 / 支付宝 / EPay / 一次性 Stripe 全部沿用现有流程,`billing_provider='manual'`。

---

## 6. 换套餐(升级 / 降级,D6 进 v1)

`product_content` 是冻结快照,无原生换套餐路径(审查 #11),需新建流程。统一规则(由"不退款"D5 推导):

- **升级(新价 ≥ 旧价)= 立即生效 + 补差价**
- **降级(新价 < 旧价)= 下一周期生效**(本期不退款不补)

### 6.1 Stripe 腿
- 升级:`subscriptions.update(sub, { items:[{id, price:newPriceId}], proration_behavior:'always_invoice' })` → 立即开出按比例差价账单;其 `invoice.paid` webhook 落地并**立即更新会员权益**到新 `product_content`(class/transfer_enable/node_group/limits)。
- 降级:`subscriptions.update(sub, { items:[{id, price:newPriceId}], proration_behavior:'none' })`,新价**下周期**生效;会员权益在下个 `invoice.paid(cycle)` 时切换。可选用 Subscription Schedule 更显式。
- 同步更新本地 `subscription.product_content`(升级即时 / 降级在生效时)、`renewal_price`、`stripe_price_id`、`reset_day`(若周期变)。

### 6.2 balance / manual 腿
- 升级:即时按 `(新月价−旧月价) × 本周期剩余比例` 从余额扣差价(余额不足则拒绝或提示充值),立即换 `product_content` + 权益;更新 `renewal_price`。
- 降级:仅登记"下周期套餐",在下次 `processRenewalActivation` 时切换 `product_content`/`renewal_price`/权益/`reset_day`。

### 6.3 约束
- 换套餐入口在用户自助页;**按 `user_id` 鉴权**,只能改自己的订阅。
- Invoice.content 是扁平 JSON 行项;Stripe proration 账单映射回本地时,记录为单行"套餐变更差价",不强行拆 credit note。

---

## 7. 存量中途转入(D8)

对**已有活跃订阅**的老用户开启 Stripe 自动续(审查 #24,**不双扣、不丢时间**):
1. **不**走会立即收费的 Checkout。改为 SetupIntent 仅**存卡不扣款**。
2. 服务端 `subscriptions.create({ customer, items:[{price}], billing_cycle_anchor = 现有 subscription.end_date+1 天的时间戳, proration_behavior:'none', default_payment_method })` —— 首次 Stripe 扣款正好接在当前到期日,本期不重复收费。
3. **复用现有 `Subscription` 行**(`processNewSubscriptionActivation` 的"已有 active/pending_renewal 则不再建"护栏 `:75-83` 决定了**绝不能新建第二行**),把它 `billing_provider='stripe'`、`auto_renew=1`、写 `stripe_subscription_id`。
4. 转入前若已存在该周期的 `pending_renewal` 续费单,先拒绝/清理,避免两套引擎同时认账。
- balance 自动续转入更简单:对现有行 `billing_provider='balance'`、`auto_renew=1`,零 Stripe 介入。
- **provider 切换一律"下周期生效",不即时**(审查 #7):Stripe→balance 的退订要等 `customer.subscription.deleted` 到达才翻 `billing_provider`,期间不让 balance 引擎插手。

---

## 8. 用户自助管理页(面板内自建,参照 Claude 截图)

新路由(挂 `/user` 组,`app/routes.php:28-116` 内),全部 **按 `user_id` 鉴权 + CSRF**:
- `GET  /user/subscription` —— 订阅概览(套餐、状态、下次扣款、past_due 横幅)
- `POST /user/subscription/setup-intent` —— 建 SetupIntent(`customer=本人 stripe_customer_id, usage:'off_session'`),返回 `client_secret` + publishable key
- `POST /user/subscription/cancel` —— `subscriptions.update(sub, {cancel_at_period_end:true})`
- `POST /user/subscription/change-plan` —— §6
- `POST /user/subscription/toggle-balance-auto` —— 切 `balance` 腿的 `auto_renew`
- `GET  /user/subscription/invoices` —— `invoices.list({customer})`,"View" 链到 `hosted_invoice_url`

**改卡流程**(审查 #16):前端 Stripe.js 挂 Payment Element + Address Element(Link 自动出现)→ `stripe.confirmSetup({redirect:'if_required'})` → **`setup_intent.succeeded` webhook** 里把新 `payment_method` attach 到 customer 并设为 `subscription.default_payment_method` 与 `customer.invoice_settings.default_payment_method`,可顺带 `invoices.pay()` 立即重试 past_due 账单。**绝不信任前端"已成功"**;服务端断言 SetupIntent 的 customer == 本人。

**3DS / SCA 恢复**:past_due 横幅上的"完成支付"按钮跳 `hosted_invoice_url`(D4 例外:不用 Customer Portal,但 3DS 这一窄场景借 Stripe 托管发票页,这是 off-session 3DS 唯一现实做法)。

前端:主题模板(Smarty,`resources/views/tabler/`)加载 `js.stripe.com`,用 `stripe_publishable_key` 初始化 Elements。

---

## 9. 安全基线(必须随本功能一起落地)

| # | 项 | 动作 |
|---|---|---|
| S1(#17) | 现有 IDOR | `Stripe::purchase()`(`src/Services/Gateway/Stripe.php:52`)取 invoice 未按 user 过滤 → 加 `->where('user_id', $this->user->id)` |
| S2(#18) | 无 CSRF | 代码库无 CSRF 防护;为所有 `/user` 写操作加 CSRF 中间件(per-session token,经 HTMx `htmx:configRequest` 注入 `X-CSRF-Token`),并确保会话 cookie `SameSite` |
| S3(#17/#20) | 越权 | 所有新端点用 `(new Subscription())->where('user_id',$uid)->where('id',$id)->first()` 定位;**绝不**用前端传入的 `stripe_customer_id`/`stripe_subscription_id` 认目标,一律从本人行服务端取 |
| S4(#2/#15/#19) | webhook 幂等 | `notify()` 顶部先查 `stripe_event.event_id` 去重;每个处理器对本地状态转移再加护栏(只在 order 仍 pending 才开通;只在 stripe_subscription_id 匹配且仍为当前订阅才降级) |
| S5(#20) | webhook 绑定 | 每个订阅事件:按 `stripe_subscription_id` 反查本地 sub,并断言其 `stripe_customer_id == event.customer` 才动作 |
| S6 | 密钥 | 推荐受限密钥 RAK(`rk_`);secret 仅服务端;publishable 才进前端;密钥不进日志/源码;webhook 验签沿用 `Webhook::constructEvent`(`Stripe.php:151-155`) |

`notify()` 需从"只认 `payment_intent.succeeded`"重写为 `switch($event->type)`,用 Stripe ID 反查本地(不再依赖 `metadata.trade_no`,因订阅发票无此字段,审查 #15)。

---

## 10. 推荐返利(D7)

- **仅首次订阅返,返到网站余额,续费不返。**
- 抽出 `Reward::issueForPaidInvoice($invoice)` 助手(内部仍走 `issuePaybackReward`,按 `(userid, invoice_id)` 去重 `Reward.php:18-29`)。
- 调用点:仅当订单为**首购**(`Order.subscription_id IS NULL`)时调用;续费订单(`subscription_id` 非空)跳过。
- 三条首购支付路径都要能触发(网关 `postPayment` / 余额首购 / Stripe `checkout.session.completed`),续费一律不调。
- 顺带修正:现有"余额付款(`InvoiceController::payBalance` `:133`)不发返利"的既有不一致——首购余额支付应能返。

---

## 11. 币种与配置

- **币种**(D3):Stripe Price 用 `stripe_currency`,创建时 Exchange 换算并锁定;同时把锁定外币金额存到 `subscription.stripe_amount/stripe_currency` 供对账。balance/manual 腿仍用 CNY `renewal_price`。
- **已知并接受的局限**(写入文档,审查 #9/#10):
  - 同一套餐 balance 腿按 CNY、Stripe 腿按锁定外币,长期 FX 漂移会有金额差异——**祖父价**:存量订阅按创建时价格续费;admin 改产品价只影响新购。
- **新增配置**(`config` 表,class='billing',经 `Config::set/obtain`,admin 在 `/admin/setting/billing` 编辑):
  - `stripe_publishable_key`
  - `stripe_auto_billing_enabled`(主开关)
  - `balance_auto_renew_enabled`(余额自动续开关)
  - `stripe_grace_days`(默认 7)
  - 复用 `stripe_api_key` / `stripe_currency` / `stripe_endpoint_secret` / `subscription_renewal_days`
- `BillingController::setStripeWebhook()`(`:82-112`)订阅的事件需扩展为:`checkout.session.completed`、`invoice.paid`、`invoice.payment_failed`、`invoice.payment_action_required`、`customer.subscription.updated`、`customer.subscription.deleted`、`setup_intent.succeeded`。

---

## 12. Cron 调度(`src/Command/Cron.php`)

每天(daily 窗口内),**顺序**很重要:
1. `expireSubscription()`(已加 `whereIn('billing_provider',['manual','balance'])` 护栏 `:378`)
2. `generateRenewalOrder()`(同护栏 `:235`)
3. **`deductRenewalFromBalance()`(新增,在 1、2 之后)**
4. `resetSubscriptionBandwidth()` —— **加正向 provider 匹配,只处理 `manual`/`balance`**;**Stripe 腿的流量重置改由 `invoice.paid(cycle)` webhook 触发**(与会员续期同一事件,保证以 Stripe 周期为准)。

每 5 分钟:`processPendingOrder`、`processRenewalActivation`(加幂等护栏)、`processNewSubscriptionActivation` 维持,但都用**正向 provider 匹配**;`expirePaidUserAccount()`(`src/Services/Cron.php:163-170`)的跳过条件需保证:Stripe 腿的 `class_expire` 由 webhook 持续推进,或额外跳过"有 stripe_subscription_id 且 stripe_status ∈ {active,past_due,trialing}"的用户(审查 #5)。

---

## 13. 幂等与并发护栏汇总(防重复扣款 / 误降级)

- **invoice 级单一权威**:谁都要先确认 `invoice.billing_provider` 匹配 + `status='unpaid'`(行锁)才结算(#1)。
- **Stripe 腿不产本地续费 invoice**:`generateRenewalOrder` 用正向匹配天然不为 stripe 行生成,杜绝余额 job 误捡(#1)。
- **webhook 去重**:`stripe_event.event_id` 唯一 + 处理器状态护栏(#19)。
- **首期单事件开通**(#3);续期处理器对"本期已激活"幂等(键于 Stripe invoice id / period)(#15)。
- **provider 切换下周期生效**,任一时刻只有一个引擎对一行生效(#7)。
- **余额扣款事务 + FOR UPDATE + 复查 unpaid**(#22)。

---

## 14. 测试策略

- 单测:Price 换算(含零小数币种)、`calculateCyclePrice`、换套餐补差价计算、grace 计算。
- webhook 幂等:同一 `evt_id` 重投 → no-op;`checkout.session.completed`+`invoice.paid(create)` 不双开;`invoice.paid(cycle)` 推进一次。
- 关键不变量(集成测试):
  - Stripe-active 用户**绝不**被 `expireSubscription` / `expirePaidUserAccount` 降级(即使 `class_expire` 暂时陈旧)。
  - balance 自动扣双跑 cron 不双扣。
  - 存量 NULL→manual 回填后,老订阅仍能正常续/降。
  - 越权:A 用户无法 cancel/读 B 的订阅;改卡无法 attach 到他人 customer。
  - 首购返利发一次、续费不返。

---

## 15. 受影响文件清单(实现时按此展开)

| 文件 | 改动 |
|---|---|
| `db/migrations/2026062600-add-stripe-auto-billing.php` | 新建:列 + 回填 + 种子配置 + `stripe_event` 表 |
| `src/Models/{User,Subscription,Order,Invoice}.php` | 新列属性 |
| `src/Models/StripeEvent.php` | 新模型 |
| `src/Services/Gateway/Stripe.php` | 订阅购买、`notify()` 重写为多事件、修 IDOR(:52) |
| `src/Services/SubscriptionService.php` | 正向 provider 护栏(:235/:378/:62)、新建盖 manual(:104)、`deductRenewalFromBalance()`、换套餐、中途转入、续期幂等护栏 |
| `src/Services/Cron.php` | 调度新增 `deductRenewalFromBalance`、`expirePaidUserAccount` 跳过条件加固 |
| `src/Controllers/User/SubscriptionController.php` | 新建:概览/setup-intent/cancel/change-plan/toggle/invoices |
| `src/Controllers/User/OrderController.php` | 订阅购买分流到 Stripe 订阅 / 标 billing_provider |
| `src/Controllers/Admin/Setting/BillingController.php` | 新配置项 + webhook 事件扩展(:82-112) |
| `src/Services/Reward.php` | `issueForPaidInvoice()` 助手 |
| `src/Middleware/Csrf.php`(或等价) | 新建 CSRF 中间件 |
| `app/routes.php` | 新 `/user/subscription/*` 路由;webhook 事件无需新路由(沿用 `/payment/notify/stripe` :118-121) |
| `resources/views/tabler/user/subscription.tpl` 等 | 自助页 + Stripe Elements;购买页"开启自动续费"选项;past_due 横幅 |
| 配置种子 / `.config.example.php` | 新配置默认 |
| `tests/...` | §14 |

---

## 16. 明确不在 v1 范围(Out of scope)

- **退款 / 争议自动同步**:不做(D5);Stripe 后台人工。本文档据此**不**接入 `charge.refunded`/`dispute` 处理,且**禁止**对 `billing_provider='stripe'` 的 invoice 调 `refundToBalance`(`src/Models/Invoice.php:60-84`)。
- 用量计费(usage-based)、税务(Stripe Tax)。
- Stripe Customer Portal(改用自建页,3DS 例外借 hosted invoice 页)。

---

## 17. 实现期默认值(已定,如需可在评审推翻)

- Stripe 腿流量重置:由 `invoice.paid(cycle)` webhook 触发;`resetSubscriptionBandwidth` 只管 manual/balance(见 §12.4)。
- 换套餐降级:默认 `proration_behavior:'none'` 直接换价、下周期生效;Subscription Schedule 作为可选增强(见 §6.1)。
