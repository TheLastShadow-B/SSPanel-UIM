# 订阅自动续费(余额优先 + Stripe off-session + 宽限期)实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让订阅到期当天自动续费——先扣站内余额、不足再扣 Stripe 存档卡;两者都失败则进入 3 天宽限期(服务不断),超期终止并作废账单。

**Architecture:** 自管 cron 驱动续费(非 Stripe 原生订阅)。Stripe 退化为"存档卡的一次性 off-session 扣款"。复用现有续费引擎(`generateRenewalOrder` 生成未付续费 invoice;`processRenewalActivation` 推进周期)与 `StripeService`/`Exchange`/`PriceResolver::toMinorUnits`。

**Tech Stack:** PHP 8.x、Slim 4、Eloquent、Carbon、Stripe PHP SDK v20(API `2026-03-25.dahlia`)、Pest + 真 MariaDB(`sspanel_test`)。

参考 spec:`docs/superpowers/specs/2026-06-27-stripe-balance-auto-renew-design.md`。

## Global Constraints

- 所有 PHP 文件:`declare(strict_types=1);`,沿用现有命名空间/风格。
- 测试:Pest;`StripeService` 一律打桩(`setInstance(fake)`),**绝不触网**;DB 走 `Tests\TestDatabase`(其 `config.value` 已是 `varchar(2048) NOT NULL`,与生产一致)。
- Stripe 调用**绝不传 `payment_method_types`**(动态支付方式)。
- 金额换算:CNY→外币用 `(new Exchange())->exchange($cny,'CNY',$stripeCurrency)`,再 `PriceResolver::toMinorUnits($amount,$stripeCurrency)` 转最小单位(零小数币种不 ×100)。
- 幂等:任何扣款前断言对应 invoice 仍 `unpaid`;off-session 扣款带幂等键 `renew_inv_{invoiceId}`。
- 续费金额权威 = CNY `subscription.renewal_price`;外币实扣额记 `subscription.stripe_amount/stripe_currency`。
- 提交粒度:每个 Task 结束 `git commit`。本仓库直接提交到 `master`(用户工作流)。

---

# Plan A —— 后端自动续费引擎(本文件详写)

> 独立可测:实现后,"已有订阅 + (余额或存档卡)"的用户即可在到期当天被自动续费/进宽限/终止。首购改造、绑卡页、前端 UI、邮件模板在 Plan B/C。

## 文件结构(Plan A)

- 修改 `src/Services/Stripe/StripeService.php` —— 加 off-session 扣款 + 默认卡读写。
- 修改 `src/Services/SubscriptionService.php` —— 加 `payRenewalFromBalance` / `chargeRenewalToCard` / `advanceRenewedPeriod` / `enterGrace` / `processAutoRenew`;改 `expireSubscription` 为宽限感知的 `terminateLapsed` + 自然过期。
- 修改 `src/Command/Cron.php` —— 每日任务里接入 `processAutoRenew` 与改造后的终止逻辑。
- 测试:`tests/Unit/Services/Stripe/OffSessionChargeTest.php`、`tests/Unit/Services/AutoRenew/*Test.php`。

---

### Task A1: `StripeService::chargeOffSession`(off-session 扣款原语)

**Files:**
- Modify: `src/Services/Stripe/StripeService.php`
- Test: `tests/Unit/Services/Stripe/OffSessionChargeTest.php`

**Interfaces:**
- Produces: `StripeService::chargeOffSession(string $customerId, string $paymentMethodId, int $amountMinor, string $currency, string $idempotencyKey, array $metadata = []): \Stripe\PaymentIntent`
- Consumes: `StripeService::setInstance()`(打桩)。

- [ ] **Step 1: 写失败测试**

```php
<?php
declare(strict_types=1);

use App\Services\Stripe\StripeService;
use Stripe\StripeClient;

it('creates a confirmed off-session PaymentIntent with an idempotency key', function () {
    $captured = null;
    $client = new class($captured) extends StripeClient {
        public function __construct(public &$captured) { parent::__construct(['api_key' => 'sk_test_x']); }
        public function __get($name) {
            if ($name === 'paymentIntents') {
                return new class($this->captured) {
                    public function __construct(public &$captured) {}
                    public function create($params, $opts = null) {
                        $this->captured = ['params' => $params, 'opts' => $opts];
                        return (object) ['id' => 'pi_1', 'status' => 'succeeded'];
                    }
                };
            }
            return parent::__get($name);
        }
    };
    $svc = new class($client) extends StripeService {
        public function __construct(private StripeClient $c) { parent::__construct($c); }
        public function client(): StripeClient { return $this->c; }
    };

    $pi = $svc->chargeOffSession('cus_1', 'pm_1', 1408, 'usd', 'renew_inv_42', ['invoice_id' => '42']);

    expect($pi->status)->toBe('succeeded');
    expect($captured['params']['amount'])->toBe(1408);
    expect($captured['params']['currency'])->toBe('usd');
    expect($captured['params']['customer'])->toBe('cus_1');
    expect($captured['params']['payment_method'])->toBe('pm_1');
    expect($captured['params']['off_session'])->toBeTrue();
    expect($captured['params']['confirm'])->toBeTrue();
    expect($captured['params'])->not->toHaveKey('payment_method_types');
    expect($captured['opts']['idempotency_key'])->toBe('renew_inv_42');
});
```

- [ ] **Step 2: 跑测试确认失败** — `./vendor/bin/pest tests/Unit/Services/Stripe/OffSessionChargeTest.php` → Expected: FAIL("Call to undefined method ... chargeOffSession")。

- [ ] **Step 3: 实现**

```php
public function chargeOffSession(
    string $customerId,
    string $paymentMethodId,
    int $amountMinor,
    string $currency,
    string $idempotencyKey,
    array $metadata = []
): \Stripe\PaymentIntent {
    return $this->client()->paymentIntents->create([
        'amount' => $amountMinor,
        'currency' => $currency,
        'customer' => $customerId,
        'payment_method' => $paymentMethodId,
        'off_session' => true,
        'confirm' => true,
        'metadata' => $metadata,
    ], [
        'idempotency_key' => $idempotencyKey,
    ]);
}
```

- [ ] **Step 4: 跑测试确认通过** → PASS。
- [ ] **Step 5: 提交** — `git add -A && git commit -m "feat(stripe): off-session charge primitive"`

---

### Task A2: `StripeService::getDefaultPaymentMethod` + `setCustomerDefaultPaymentMethod`

**Files:** Modify `src/Services/Stripe/StripeService.php`;Test 追加到 `OffSessionChargeTest.php`。

**Interfaces:**
- Produces:
  - `getDefaultPaymentMethod(string $customerId): ?string` —— 读 `customer.invoice_settings.default_payment_method`,无则 null。
  - `setCustomerDefaultPaymentMethod(string $customerId, string $paymentMethodId): void` —— attach + 设为 customer 默认(**不**涉及 subscription;区别于现有 `setDefaultPaymentMethod`)。

- [ ] **Step 1: 写失败测试**(两个 `it`:`getDefaultPaymentMethod` 解析 `invoice_settings.default_payment_method`(可为对象或字符串,取 `->id ?? 字符串`);`setCustomerDefaultPaymentMethod` 调 `paymentMethods->attach` 再 `customers->update(invoice_settings.default_payment_method)`)。打桩同 A1 风格,断言入参。
- [ ] **Step 2: 跑测试确认失败。**
- [ ] **Step 3: 实现**

```php
public function getDefaultPaymentMethod(string $customerId): ?string
{
    $customer = $this->client()->customers->retrieve($customerId, []);
    $pm = $customer->invoice_settings->default_payment_method ?? null;
    if ($pm === null) {
        return null;
    }
    return is_string($pm) ? $pm : ($pm->id ?? null);
}

public function setCustomerDefaultPaymentMethod(string $customerId, string $paymentMethodId): void
{
    $this->client()->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);
    $this->client()->customers->update($customerId, [
        'invoice_settings' => ['default_payment_method' => $paymentMethodId],
    ]);
}
```

- [ ] **Step 4: 跑测试确认通过。**
- [ ] **Step 5: 提交** — `git commit -m "feat(stripe): read/set customer default payment method"`

---

### Task A3: `SubscriptionService::payRenewalFromBalance`(余额结算续费 invoice)

**Files:** Modify `src/Services/SubscriptionService.php`;Test `tests/Unit/Services/AutoRenew/PayFromBalanceTest.php`。

**Interfaces:**
- Produces: `static payRenewalFromBalance(Subscription $sub, Invoice $invoice): bool` —— 事务内行锁该 invoice,复查 `status='unpaid'` 且 `user.money >= invoice.price`;满足则扣 `money`、写 `UserMoneyLog`、`invoice.status='paid_balance'`、`pay_time`,返回 true;否则 false。
- Consumes: `App\Models\{User,Invoice,UserMoneyLog}`;`App\Services\DB`(事务)。

- [ ] **Step 1: 写失败测试**

```php
<?php
declare(strict_types=1);

use App\Models\{Config, Invoice, Subscription, User};
use App\Services\SubscriptionService;
use Tests\TestDatabase;

beforeEach(fn () => TestDatabase::init());
afterEach(fn () => TestDatabase::dropTables());

it('deducts balance and marks the invoice paid when money is enough', function () {
    $user = makeUserWithMoney(50.0);           // helper: 见 §测试辅助
    $sub  = makeSub($user, renewalPrice: 30.0);
    $inv  = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    $ok = SubscriptionService::payRenewalFromBalance($sub, $inv);

    expect($ok)->toBeTrue();
    expect((new User())->find($user->id)->money)->toBe(20.0);
    expect((new Invoice())->find($inv->id)->status)->toBe('paid_balance');
});

it('does nothing and returns false when money is insufficient', function () {
    $user = makeUserWithMoney(10.0);
    $sub  = makeSub($user, renewalPrice: 30.0);
    $inv  = makeUnpaidRenewalInvoice($user, $sub, 30.0);

    expect(SubscriptionService::payRenewalFromBalance($sub, $inv))->toBeFalse();
    expect((new User())->find($user->id)->money)->toBe(10.0);
    expect((new Invoice())->find($inv->id)->status)->toBe('unpaid');
});
```

> 测试辅助(放 `tests/Helpers.php` 或本文件顶部):`makeUserWithMoney(float)`、`makeSub(User,renewalPrice,endDate='today')`、`makeUnpaidRenewalInvoice(User,Sub,price)`。各自 `new Model()` 赋必填字段后 `save()`(参照 `SubscriptionCheckoutModeTest` 的 `makeSubBuyer`/`makeSubProduct`)。

- [ ] **Step 2: 跑测试确认失败。**
- [ ] **Step 3: 实现**(事务 + 行锁;复查 unpaid;扣款 + `UserMoneyLog::add(uid, before, after, amount, memo)`;`invoice.status='paid_balance'`、`pay_time=time()`)。镜像 `Invoice::refundToBalance` 的 `UserMoneyLog` 调用形态。
- [ ] **Step 4: 跑测试确认通过。**
- [ ] **Step 5: 提交** — `git commit -m "feat(autorenew): settle renewal invoice from balance"`

---

### Task A4: `SubscriptionService::chargeRenewalToCard`(off-session 兜底)

**Files:** Modify `src/Services/SubscriptionService.php`;Test `tests/Unit/Services/AutoRenew/ChargeToCardTest.php`。

**Interfaces:**
- Produces: `static chargeRenewalToCard(Subscription $sub, Invoice $invoice): bool` —— `ensureCustomer`→`getDefaultPaymentMethod`,无卡返回 false;有卡则把 `renewal_price` 经 Exchange+`toMinorUnits` 换成 `stripe_currency` 最小单位,`chargeOffSession(...,'renew_inv_'.$invoice->id,...)`;成功(PI `status==='succeeded'`)→ `invoice.status='paid_gateway'`、写 `sub.stripe_amount/stripe_currency`,返回 true;抛 `CardException`/`ApiErrorException` 或非 succeeded → false。
- Consumes: `StripeService::getInstance()`(打桩)、`Exchange`、`PriceResolver::toMinorUnits`、`Config::obtain('stripe_currency')`。

- [ ] **Step 1: 写失败测试**(打桩 `StripeService`:`getDefaultPaymentMethod` 返回 `pm_1`/`null`;`chargeOffSession` 返回 succeeded PI / 抛 `\Stripe\Exception\CardException`。三例:有卡成功→true+invoice paid_gateway;无卡→false+invoice 不变;扣款抛 CardException→false+invoice 不变。汇率用离线注入或在测试里 stub `Exchange`——若 `Exchange` 需 Redis,则按 `PriceResolverTest` 的 `markTestSkipped` 守卫,仅断言无 Redis 时跳过 FX 段,核心分支用"无卡→false"覆盖)。
- [ ] **Step 2: 跑测试确认失败。**
- [ ] **Step 3: 实现**(try/catch `\Stripe\Exception\ApiErrorException`;失败一律 false,不抛)。
- [ ] **Step 4: 跑测试确认通过。**
- [ ] **Step 5: 提交** — `git commit -m "feat(autorenew): off-session card fallback for renewal"`

---

### Task A5: `SubscriptionService::advanceRenewedPeriod`(抽出周期推进)

**Files:** Modify `src/Services/SubscriptionService.php`;Test `tests/Unit/Services/AutoRenew/AdvancePeriodTest.php`。

**Interfaces:**
- Produces: `static advanceRenewedPeriod(Subscription $sub, User $user): void` —— `newStart=end_date+1d`,`newEnd=calculateEndDate(newStart,cycle)`;`sub.start_date/end_date` 更新、`status='active'`、`grace_until=null`;`user.class_expire=newEnd 23:59:59`;按 `product_content` 重置流量(`u=d=transfer_today=0`,`transfer_enable=gbToB(bandwidth)`)。**抽取自现有 `processRenewalActivation` :172-185 + bandwidth 重置**,供 A7 复用。
- [ ] **Step 1: 写失败测试**(给一个 end_date=today 的 month 订阅,调用后断言 end_date 顺延约 1 月、class_expire 对齐、grace_until 清空)。
- [ ] **Step 2: 确认失败。**
- [ ] **Step 3: 实现;并把 `processRenewalActivation` 改为调用本方法(DRY)。**
- [ ] **Step 4: 确认通过(含原 `processRenewalActivation` 相关测试不回归)。**
- [ ] **Step 5: 提交** — `git commit -m "refactor(subscription): extract advanceRenewedPeriod"`

---

### Task A6: `SubscriptionService::enterGrace`(进宽限 + 失败邮件)

**Files:** Modify `src/Services/SubscriptionService.php`;Test `tests/Unit/Services/AutoRenew/EnterGraceTest.php`。

**Interfaces:**
- Produces: `static enterGrace(Subscription $sub, User $user): void` —— `graceDays=(int)Config::obtain('stripe_grace_days')`(默认 3);`grace_until = end_date + graceDays`(`Y-m-d H:i:s`);`sub.status='pending_renewal'`;**延** `user.class_expire = grace_until`(保活);发邮件(`Notification::notifyUser($user, appName.'-续费失败', '...请在 N 天内支付...', 'subscription_renewal_failed.tpl')`,`try/catch` 同现有)。
- [ ] **Step 1: 写失败测试**(断言 grace_until = end_date+3、class_expire 延到 grace_until、status=pending_renewal;`Notification` 在无渠道时不抛/被 catch——可只断言状态字段,邮件发送容错)。
- [ ] **Step 2: 确认失败。**
- [ ] **Step 3: 实现。**(新建 `resources/views/...` 邮件模板留待 Plan C;此处发送被 try/catch 包裹,模板缺失不致命——或先建一个最小 `subscription_renewal_failed.tpl`。)
- [ ] **Step 4: 确认通过。**
- [ ] **Step 5: 提交** — `git commit -m "feat(autorenew): enter 3-day grace on renewal failure"`

---

### Task A7: `SubscriptionService::processAutoRenew`(瀑布主流程)

**Files:** Modify `src/Services/SubscriptionService.php`;Test `tests/Unit/Services/AutoRenew/ProcessAutoRenewTest.php`。

**Interfaces:**
- Produces: `static processAutoRenew(): void` —— 选 `status='pending_renewal'`(或 active 到期)、`end_date=today`、`auto_renew=1`、`billing_provider IN SELF_MANAGED` 且存在 `unpaid` 续费 invoice 的订阅;对每个:
  1. `payRenewalFromBalance` 成功 → `advanceRenewedPeriod` ✓
  2. 否则 `chargeRenewalToCard` 成功 → `advanceRenewedPeriod` ✓
  3. 否则 `enterGrace`
- Consumes: A3/A4/A5/A6。

- [ ] **Step 1: 写失败测试**(四分支,打桩 `StripeService`):
  - 余额够 → active、end_date 顺延、invoice paid_balance。
  - 余额不足 + 卡成功 → active、顺延、invoice paid_gateway。
  - 余额不足 + 卡被拒 → pending_renewal、grace_until=+3、**未降级**(class>0)。
  - 余额不足 + 无卡 → 同上进宽限。
- [ ] **Step 2: 确认失败。**
- [ ] **Step 3: 实现。**
- [ ] **Step 4: 确认通过。**
- [ ] **Step 5: 提交** — `git commit -m "feat(autorenew): balance-first then card renewal waterfall"`

---

### Task A8: 宽限感知的终止(改造 `expireSubscription` → 自然过期 + `terminateLapsed`)

**Files:** Modify `src/Services/SubscriptionService.php`;Test `tests/Unit/Services/AutoRenew/TerminateTest.php`。

**Interfaces:**
- 改 `expireSubscription()`:仅处理 **`auto_renew=0`**(用户已取消)且 `end_date=today` 的 `pending_renewal` 订阅 → 自然过期(现有降级逻辑保持)。
- 新增 `static terminateLapsed(): void`:处理 `status='pending_renewal'`、`auto_renew=1`、`grace_until < now` 且续费 invoice 仍 `unpaid` 的订阅 → 取消 order/invoice(`status='cancelled'`,**使其不可再支付**)、`sub.status='expired'`、降级用户(同现有)、发"已失效"邮件(`subscription_expired.tpl`)。
- [ ] **Step 1: 写失败测试**:
  - grace_until 在未来 → `terminateLapsed` 不动它(仍 pending_renewal、未降级)。
  - grace_until 已过 + invoice unpaid → 终止:sub expired、user class=0、invoice cancelled。
  - 宽限内 invoice 被付掉(paid_*) → `terminateLapsed` 跳过。
  - `auto_renew=0` 到期 → `expireSubscription` 自然过期降级。
- [ ] **Step 2: 确认失败。**
- [ ] **Step 3: 实现。**
- [ ] **Step 4: 确认通过(原 `expireSubscription` 相关测试相应调整)。**
- [ ] **Step 5: 提交** — `git commit -m "feat(autorenew): grace-aware termination, void lapsed invoice"`

---

### Task A9: 接入每日 cron

**Files:** Modify `src/Command/Cron.php`(每日块 :88-92 附近)。

**Interfaces:** 每日顺序改为:`generateRenewalOrder()` → **`processAutoRenew()`(新)** → `expireSubscription()` → **`terminateLapsed()`(新)** → `sendSecondRenewalNotification()` → `resetSubscriptionBandwidth()`。

- [ ] **Step 1: 写失败测试** `tests/Unit/Command/AutoRenewScheduleTest.php` —— 断言 `Command\Cron` 源码包含按序的 `processAutoRenew()` 与 `terminateLapsed()` 调用(字符串/正则断言,参照现有 `StripeWebhookEventsTest` 的"源码声明"测试风格)。
- [ ] **Step 2: 确认失败。**
- [ ] **Step 3: 实现(插入两行调用)。**
- [ ] **Step 4: 确认通过。**
- [ ] **Step 5: 提交** — `git commit -m "feat(autorenew): wire auto-renew + termination into daily cron"`

---

### Task A10: 配置默认值

**Files:** 确认 `stripe_grace_days` 默认 = 3(`2026062600` 迁移种子;若为 7,新增小迁移 `2026062701-set-grace-days-default.php`:`UPDATE config SET value='3', default='3' WHERE item='stripe_grace_days' AND value IN ('','7')`)。Test 可选(`Config::obtain('stripe_grace_days')` 类型为 int)。
- [ ] 实现 + 提交 `git commit -m "chore(autorenew): default grace days to 3"`

---

## Plan A 自检(spec 覆盖)

- D2 瀑布 ✓ A3+A4+A7;D4 到期当天 ✓ A7;D7 失败进宽限不降级 ✓ A6+A7;D9 超期终止+作废账单 ✓ A8;币种换算 ✓ A4;幂等 ✓ A3 行锁 + A4 幂等键。
- 未覆盖(留 Plan B/C):首购余额优先+Checkout 存卡、webhook 改造、绑卡页、取消、我的订阅/下单页 UI、邮件模板、删除原生订阅死代码、`stripe_grace_days` 文案。

---

# Plan B —— 首购改造 + 绑卡(后续,outline)

1. 改 `OrderController::subscription`(:390+):建单后**余额优先**(`money≥buyPrice`→扣余额标 `paid_balance`→现有激活链;默认 `auto_renew=1`);不足→`StripeService::createCheckoutForInvoice`(新,`mode:'payment'` + `payment_intent_data.setup_future_usage:'off_session'` + metadata{user,order,invoice})。去掉 `auto_renew_provider`。
2. `WebhookHandler` 改造:`checkout.session.completed`(mode=payment)→标 invoice 已付 + 激活 + 存默认卡;`payment_intent.succeeded`→兜底确认;`setup_intent.succeeded`→`setCustomerDefaultPaymentMethod`。**移除** `invoice.paid`/`customer.subscription.*` 处理。`BillingController::setStripeWebhook` 事件集合同步。
3. 绑卡页:`PaymentMethodController`(`GET /user/payment-method`、`POST .../setup-intent`、`POST .../detach`)+ `payment_method.tpl`(Stripe Elements)+ 路由。每步 TDD(打桩 StripeService + 越权测试)。

# Plan C —— UX + 取消 + 邮件 + 清理(后续,outline)

1. 取消:`POST /user/subscription/cancel`→`auto_renew=0`(按 user 鉴权)+ "我的订阅"按钮与状态(active/宽限/失效)展示。
2. 邮件模板:`subscription_renewal_failed.tpl`(进宽限,含付款入口)、复用/完善 `subscription_expired.tpl`。
3. 发票支付端拒绝 `cancelled` 账单再支付(`InvoiceController`/`payBalance` 守卫)。
4. 下单页文案:去掉不存在的 provider 选择,显示"默认自动续费"。
5. 清理死代码:`PriceResolver::resolve`(循环 Price)、`StripeService::{createSubscriptionCheckout,createAlignedSubscription,updateSubscriptionPrice,cancelAtPeriodEnd}` 等原生订阅件按需移除;`WebhookHandler` 原生分支移除。
