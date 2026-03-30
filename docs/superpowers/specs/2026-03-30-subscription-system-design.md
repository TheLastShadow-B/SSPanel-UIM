# SSPanel-UIM 订阅制套餐系统设计文档

## 概述

将 SSPanel-UIM 从一次性购买模式转变为订阅制模式。用户购买流量套餐订阅，按月/季/年周期付费，流量每月重置。到期前自动生成续费账单并通知用户，到期未付款则终止服务。

## 需求摘要

1. 商店提供多种流量套餐订阅（300G、600G 等）
2. 每个套餐支持月/季/年账单周期，季/年可设置折扣比例
3. 流量按月重置（基于激活日计算重置日）
4. 到期前 X 天自动生成续费账单并发送邮件通知
5. 到期前 ceil(X/2) 天发送二次提醒邮件
6. 到期日当天发送终止通知
7. 到期未付款则用户等级和流量归零
8. 禁止已有活跃订阅的用户购买新订阅
9. 流量包一次性添加，下次月度重置时清零回套餐额度
10. 旧套餐类型（TABP/Time）前端隐藏，不再允许新购，代码保留，自然到期消亡
11. 管理员可手动修改订阅续费价格（未付款本次生效，已付款下次生效）
12. 优惠券仅允许新购使用，续费不可用

## 架构方案

**方案 A（采用）**：新增 `subscription` 表 + 复用现有 Order/Invoice/Payment 体系。

- 新增 `subscription` 表管理订阅状态
- Product 表新增 `subscription` 类型
- 复用现有 Order、Invoice、Payment、Paylist 流程
- Cron 服务扩展订阅相关任务
- 旧套餐逻辑完全不变

---

## 1. 数据模型

### 1.1 Product 表扩展

Product 表 `type` 字段新增值 `subscription`。`content` JSON 结构：

```json
{
  "bandwidth": 300,
  "class": 1,
  "node_group": 0,
  "speed_limit": 0,
  "ip_limit": 0,
  "billing_cycle": {
    "month": true,
    "quarter": true,
    "year": true
  },
  "discount": {
    "quarter": 1.0,
    "year": 1.0
  }
}
```

- `price` 字段存储月价格
- 季付价格 = `price * 3 * discount.quarter`
- 年付价格 = `price * 12 * discount.year`
- `discount` 默认 1.0（无折扣），管理员在后台为每个套餐单独设置

### 1.2 新增 subscription 表

```sql
CREATE TABLE subscription (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  product_id      INT UNSIGNED NOT NULL,
  product_content JSON NOT NULL,
  billing_cycle   ENUM('month','quarter','year') NOT NULL,
  renewal_price   DECIMAL(12,2) NOT NULL,

  start_date      DATE NOT NULL,
  end_date        DATE NOT NULL,
  reset_day       TINYINT UNSIGNED NOT NULL,
  last_reset_date DATE NOT NULL,

  status          ENUM('active','pending_renewal','expired','cancelled') NOT NULL DEFAULT 'active',

  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,

  INDEX idx_user (user_id),
  INDEX idx_status (status),
  INDEX idx_end_date (end_date)
);
```

**字段说明**：

| 字段 | 说明 |
|------|------|
| `product_content` | 购买时的产品快照，确保产品修改后不影响现有订阅 |
| `billing_cycle` | 用户选择的账单周期 |
| `renewal_price` | 续费价格，首次购买时写入（优惠后的实际支付价格），管理员可修改 |
| `start_date` | 当前周期起始日 |
| `end_date` | 当前周期到期日 |
| `reset_day` | 流量重置日（激活日的"日"，1-31） |
| `last_reset_date` | 上次实际重置日期，防止重复重置 |
| `status` | active=活跃, pending_renewal=待续费(已生成续费账单), expired=已过期, cancelled=已取消 |

### 1.3 现有表变化

- **Order 表**：新增可空字段 `subscription_id INT UNSIGNED NULL`。新购订单为 null，续费订单填入对应 subscription.id，用于 Cron 续期激活时关联订阅记录
- **Invoice 表**：无结构变化，复用现有流程
- **User 表**：无结构变化。订阅激活时更新 class/class_expire/transfer_enable 等字段
- **Config 表**：新增 `subscription_renewal_days`（到期前 X 天生成续费账单，默认 7）

### 1.4 产品类型总览

| 类型 | 状态 | 说明 |
|------|------|------|
| TABP | 旧，前端隐藏 | 代码保留，自然到期消亡 |
| Time | 旧，前端隐藏 | 代码保留，自然到期消亡 |
| Bandwidth | 保留，新增校验 | 流量包，需有活跃订阅才能购买 |
| Topup | 保留 | 充值余额 |
| Subscription | **新增** | 订阅套餐 |

---

## 2. 订阅生命周期

### 2.1 新购流程

```
用户选择套餐 -> 选择账单周期 -> (可选)输入优惠券 -> 创建 Order + Invoice
-> 用户支付 Invoice -> Cron 检测到已付款 -> 激活订阅
```

**激活逻辑**：

1. 创建 `subscription` 记录：
   - status = active
   - start_date = 今天
   - end_date = 按周期计算（见 2.4）
   - reset_day = 今天的日
   - last_reset_date = 今天
   - renewal_price = 实际支付的周期总价（优惠后）
   - product_content = 产品快照
2. 更新 User：
   - class = 套餐 class
   - class_expire = end_date
   - transfer_enable = bandwidth（GB 转 bytes）
   - u = 0, d = 0
   - node_group, node_speedlimit, node_iplimit 按套餐设置

### 2.2 续费流程（Cron 驱动）

```
到期前 X 天 -> 生成续费 Order + Invoice(price=renewal_price) -> 发邮件通知
-> 到期前 ceil(X/2) 天 -> 发二次提醒邮件
-> 用户支付 -> Cron 检测已付款 -> 续期订阅
-> 到期日当天未付款 -> 发终止通知 -> 降级用户
```

**续期激活逻辑**：

1. subscription：start_date = 旧 end_date + 1，end_date 按周期重新计算，status = active
2. User：class_expire = 新 end_date
3. 流量不在此时重置（由独立的月度重置逻辑处理）

**到期未付款逻辑**：

1. 取消未付款的续费 Invoice 和 Order
2. subscription status -> expired
3. User：class = 0, transfer_enable = 0, node_group = 0, node_speedlimit = 0, node_iplimit = 0

### 2.3 月度流量重置

每日 Cron 检查所有 status=active 的订阅：

```
resetDate = min(subscription.reset_day, 当月最后一天)

如果 今天的日 == resetDate 且 last_reset_date < 本月重置日:
    User: u = 0, d = 0, transfer_enable = product_content.bandwidth(GB转bytes)
    Subscription: last_reset_date = 今天
```

流量包（bandwidth 产品）添加的额外流量在重置时自然清零，因为 transfer_enable 被重置回套餐原始额度。

### 2.4 end_date 计算规则

使用 PHP Carbon 的 `addMonthsNoOverflow` 处理月末溢出，然后减 1 天。

| 激活日 | 周期 | end_date |
|--------|------|----------|
| 3月15日 | 月付 | 4月14日 |
| 3月15日 | 季付 | 6月14日 |
| 1月31日 | 月付 | 2月28日(或29日) |
| 3月31日 | 季付 | 6月30日 |
| 1月31日 | 年付 | 次年1月30日 |

续期时基于新 start_date 重新计算，确保到期日与流量重置日对齐。

---

## 3. 管理员后台

### 3.1 订阅套餐管理

在现有 Admin ProductController 中扩展 subscription 类型产品的创建/编辑：

- 名称、月价格、状态、库存（复用现有字段）
- 月流量额度 (GB)、用户等级 (class)
- 节点分组、速度限制、IP 限制
- 可用账单周期勾选（月/季/年）
- 季付折扣比例、年付折扣比例（默认 1.0 无折扣）

前端产品类型选择器中隐藏旧类型（TABP/Time），保留 Subscription、Bandwidth、Topup。

### 3.2 订阅管理页面（新增）

新增 Admin 路由 `/admin/subscription`：

- **订阅列表**：展示所有订阅，支持按用户/状态/到期日筛选
- **订阅详情**：查看订阅信息、关联的历史 Order/Invoice
- **修改续费价格**：编辑 renewal_price；如果存在未付款的续费 Invoice，同步更新该 Invoice 金额
- **手动取消订阅**：标记为 cancelled，降级用户

### 3.3 全局配置

Config 表新增：`subscription_renewal_days`（到期前 X 天生成续费账单，默认 7）

---

## 4. 前端用户界面

### 4.1 商店页面

**套餐列表页** `/user/product`：

- 展示所有上架的 subscription 类型套餐（卡片式）
- 每张卡片显示：名称、月流量、月价格、折扣标签（如"季付9折"、"年付8折"）
- 隐藏旧类型（TABP/Time）产品
- Bandwidth（流量包）和 Topup（充值）单独保留
- 用户已有活跃订阅时，套餐卡片显示不可购买状态，提示"您已有活跃订阅"

**套餐详情/下单页** `/user/product/{id}`：

- 显示套餐详细信息（流量、等级、限制等）
- 账单周期选择器（月/季/年），仅显示管理员启用的周期
- 每个周期显示计算后的价格，如有折扣显示原价和折后价
- 优惠券输入框
- 确认下单 -> 创建 Order + Invoice -> 跳转支付

### 4.2 用户订阅管理页面（新增）

**我的订阅** `/user/subscription`：

- 当前订阅状态（活跃/待续费/已过期）
- 套餐名称、账单周期、流量额度
- 当前周期起止日期、下次流量重置日
- 续费价格、下次账单日期
- 待支付账单快捷入口（如有未付款续费 Invoice）

---

## 5. Cron 任务

### 5.1 每 5 分钟任务

- `processSubscriptionOrderActivation()`：检测已付款的 subscription 类型新购订单，创建 subscription 记录并激活用户
- `processSubscriptionRenewalActivation()`：检测已付款的续费订单，更新 subscription 的 start_date/end_date，延长 user.class_expire

### 5.2 每日任务

按以下顺序执行：

1. `expireSubscription()`：end_date = 今天且续费 Invoice 仍未付款 -> 发终止通知，subscription status -> expired，降级用户
2. `generateSubscriptionRenewalOrder()`：end_date - X 天 = 今天 -> 生成续费 Order + Invoice，发首次通知邮件，subscription status -> pending_renewal
3. `sendSubscriptionSecondNotification()`：end_date - ceil(X/2) 天 = 今天 -> 发二次提醒邮件
4. `resetSubscriptionBandwidth()`：检查 active 订阅，今天是否为重置日，重置 u/d/transfer_enable

### 5.3 邮件模板（新增 3 个）

- `subscription_renewal.tpl`：首次续费通知（套餐即将到期 + 账单金额 + 支付链接）
- `subscription_reminder.tpl`：二次提醒（剩余天数 + 催促支付）
- `subscription_expired.tpl`：到期终止通知（套餐已终止 + 服务已停止）

---

## 6. 优惠券与支付约束

### 6.1 优惠券限制

- **新购订阅**：允许使用优惠券，走现有验证流程
- **续费订阅**：Cron 生成续费 Order 时 coupon 字段留空，前端续费支付页面不显示优惠券输入框
- 新购时 renewal_price 记录优惠后的实际支付价格，后续续费沿用此价格

### 6.2 购买互斥校验

- 购买 subscription 产品时：检查用户是否有 active 或 pending_renewal 状态的 subscription，有则拒绝
- 购买 bandwidth 流量包时：检查用户是否有 active 状态的 subscription，没有则拒绝
