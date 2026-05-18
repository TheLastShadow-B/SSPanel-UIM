---
title: "feat: 用户首页改造为 2×2 主卡片布局"
type: feat
status: active
date: 2026-05-18
origin: docs/brainstorms/2026-05-18-user-home-2x2-cards-requirements.md
---

# feat: 用户首页改造为 2×2 主卡片布局

## Summary

将 `/user` 首页主体从当前不平衡的双列 vstack 重构为严格 2×2 主卡片网格 — 左上「快速配置」、右上「流量用量」（合并每小时用量并应用 Domestic Pool Usage reference 风格）、左下「置顶公告」、右下「在线 IP」；同时移除模板顶部已成死代码的 `info_cards` foreach + 不可见 hero 区，以及控制器中未被新模板引用的 assign。零后端业务改动，仅模板 / 控制器 assign / 内嵌 ApexCharts 配置三处。

---

## Problem Frame

当前页面顶部存在一段空白黑区（`$info_cards` 未被控制器赋值的死代码 + `text-white` 标题在浅色背景下不可见），右上「流量用量」卡内容稀薄而下方堆了两张卡，左右两列高度严重失衡。同时「流量用量」用堆叠双段进度条 + 一行灰字混合表达"已用/剩余/到期"，信息层级混乱。详见 origin。

---

## Requirements

- R1. 主体在 ≥lg 屏呈现严格 2×2 卡片网格，单元位置按 origin §2 表格固定（origin R 对应）
- R2. 移除顶部死代码 `info_cards` foreach 与不可见 hero 标题区
- R3. 「流量用量」与「每小时用量」合并为单卡，含三段式：右上等级徽标 / 三统计框 / 折线图 + 图例
- R4. 折线图样式向 reference 靠拢：smooth 曲线、隐藏 toolbar、淡虚线网格、`流量上限`（dashed）参考线
- R5. `enable_checkin` 开启时签到块以紧凑 banner 形式嵌入「快速配置」卡顶部，不破坏 2×2 网格
- R6. `traffic_log` 关闭、`transfer_enable === 0`、`class === 0`、`ann === null`、`online_ips` 为空等降级状态都能优雅渲染
- R7. <lg 屏四张卡纵向堆叠，无水平溢出
- R8. 既有 JS（推荐客户端检测、剪贴板、签到 HTMX、ApexCharts 渲染）行为不回归

---

## Scope Boundaries

- 不改动后端模型、控制器业务逻辑、路由、签到/订阅服务（仅清理控制器中未被新模板使用的 `assign()` 调用）
- 不引入新依赖（沿用 Tabler core CDN 中已捆绑的 ApexCharts）
- 不改 Tabler 主题色、暗色模式、全站 layout / 导航
- 不重写国际化文案
- 不调整签到业务逻辑（仅移动 DOM 位置）
- 不联动改造其它页面（`/user/announcement`、`/user/product`、`/user/profile` 等）

---

## Context & Research

### Relevant Code and Patterns

- `resources/views/tabler/user/index.tpl` — 改造主目标（当前文件 768 行，含模板 + 内嵌 CSS + 内嵌 JS）
- `src/Controllers/UserController.php::index`（行 30-93） — 模板数据装配
- `src/Models/User.php` — 提供 `enableTraffic()`（带单位字符串）、`transfer_enable`（原始字节数）、`LastusedTraffic()`、`TodayusedTraffic()`、`unusedTraffic()`、`unusedTrafficPercent()`、`class`、`class_expire`、`isAbleToCheckin()`、`lastCheckInTime()`
- `resources/views/tabler/admin/index.tpl`（行 20-107） — 现成的 `row row-deck row-cards` + `col-sm-6 col-lg-3` 小卡片排版范式，可直接参考其等高机制（Tabler `row-cards` + `row-deck` 自带等高）
- `resources/views/tabler/user/profile.tpl`（行 20-60） — 同套小卡片样式范式
- ApexCharts 加载：`//{$config['jsdelivr_url']}/npm/@tabler/core@latest/dist/libs/apexcharts/dist/apexcharts.min.js`（无需变更）

### Institutional Learnings

- `docs/solutions/` 目录暂无与本改造直接相关的条目（项目尚未铺开 ce-compound 沉淀）

### External References

- 用户提供的 reference 图：Domestic Pool Usage 卡片（三统计框横排 + 折线图 + 右下 dashed/solid 双图例）
- ApexCharts `annotations.yaxis` API — 用于绘制 dashed pool limit 水平参考线
- Tabler `row-deck` utility — 同一行内多张卡片自动拉伸到同高

---

## Key Technical Decisions

- **Pool limit 参考线数据源**：使用 `$user->transfer_enable`（字节数）转换为 MB 后写入 ApexCharts `annotations.yaxis[].y`。`transfer_enable === 0` 时省略该 annotation（图例同步隐藏）。理由：origin §7 的假设已在 Phase 1 研究中确认字段存在
- **三统计框文案**：今日用量 / 过去用量 / 剩余流量。理由：与现有方法名一一对应、与现有 UI 文案保持连续性，origin §8 决策 5 默认值
- **右上角徽标**：`class > 0` → `LV.{class} · {class_expire_days} 天到期`；`class === 0` → "前往商店购买套餐" 链接按钮。理由：origin §8 决策 4，保留现有"购买入口"用户旅程
- **签到 banner 位置**：嵌入「快速配置」卡 `.card-body` 顶部作为一行紧凑 alert 样式（`alert alert-success` 或 `bg-green-lt` 圆角条），而非整张子卡。理由：保持 2×2 严格结构，origin §8 决策 3
- **签到关闭时**：banner 整块 `{if}` 包裹不渲染，「快速配置」卡内容直接从推荐客户端开始
- **ApexCharts 配色**：保留现有橙红 `#FF4500` 不切换为 primary 蓝。理由：现状辨识度高，origin §8 决策 6 默认值
- **网格结构**：采用 `<div class="row row-cards row-deck">` + 4 个 `col-lg-6` 子项；每张卡用 `card h-100` 配合 `row-deck` 实现真正等高，避免内容差导致 visual jitter
- **「置顶公告」长内容**：给 `.card-body` 加 `max-height: 480px; overflow-y: auto`，防止单卡撑爆整列高度；同时移除 origin 截图中右上角的浮动 `ribbon` 改为常规 card-header 样式，与其他三卡视觉对齐
- **控制器 assign 清理**：移除 `user_class`、`user_money`、`ip_limit`、`speed_limit` 四个未被新模板引用的 assign（模板直接通过 `$user->...` 访问相同字段）。`captcha`、`traffic_logs`、`class_expire_days`、`UniversalSub`、`clientData`、`platformIcons`、`online_ips`、`ann` 保留

---

## Open Questions

### Resolved During Planning

- `$user->transfer_enable` 字段是否存在 → 存在，是 User 模型的数据库列（字节数），`enableTraffic()` 是带单位字符串版本
- ApexCharts 版本是否支持 `annotations.yaxis` → Tabler core 捆绑版本支持
- 是否需要新依赖 → 否

### Deferred to Implementation

- 折线图在卡片内的最佳像素高度（240 / 280 / 300）— 实施时实际渲染再微调；plan 默认 `260px`
- 「置顶公告」`max-height` 的最终像素值 — 与右下「在线 IP」卡片的总高度对齐，实施时 visual diff
- 右上「流量用量」三统计框在窄屏下是否堆叠为 1×3 列还是保持 3×1 行 — 实施时依 Bootstrap 默认行为观察；plan 默认 `col-4` + 在 `<sm` 自然换行
- `bg-green-lt` 与 `alert alert-success` 哪个签到 banner 视觉更和谐 — 实施时对比；plan 默认 `bg-green-lt` 圆角条 + `ribbon` 风格 icon

---

## High-Level Technical Design

> *本节示意目标布局结构，是 review 用的方向性指引，不是实现规格。实施时按照 Tabler/Bootstrap 实际类名调整。*

```text
.page-wrapper
└── .container-xl
    └── .row.row-cards.row-deck                ← 2×2 网格容器
        ├── .col-lg-6  (左上)
        │   └── .card.h-100                    ← 快速配置
        │       └── .card-body
        │           ├── [bg-green-lt banner]   ← 签到（条件渲染）
        │           ├── h3 "快速配置"
        │           ├── .recommended-section   ← 推荐客户端（保留）
        │           └── #all-platforms         ← 折叠其他平台（保留）
        │
        ├── .col-lg-6  (右上)
        │   └── .card.h-100                    ← 流量用量（重构）
        │       ├── .card-header
        │       │   ├── h3 "流量用量"
        │       │   └── .badge "LV.X · N 天到期"   ← class>0；否则 CTA 链接
        │       └── .card-body
        │           ├── .row.row-cards (3×col-4)
        │           │   ├── 统计框：今日用量
        │           │   ├── 统计框：过去用量
        │           │   └── 统计框：剩余流量
        │           └── #traffic-log (height: 260px)   ← ApexCharts 折线
        │              └── (annotations.yaxis: 流量上限 dashed)
        │
        ├── .col-lg-6  (左下)
        │   └── .card.h-100                    ← 置顶公告
        │       ├── .card-header  h3 "置顶公告" + 日期
        │       └── .card-body (max-height: 480px; overflow-y: auto)
        │
        └── .col-lg-6  (右下)
            └── .card.h-100                    ← 在线 IP
                ├── .card-header
                └── .card-body
                    └── .table-responsive (max-height: 300px)
```

ApexCharts 配置 diff（方向性）：
```js
// 新增到 getTrafficChartConfig 返回对象内
chart: { ...existing, toolbar: { show: false }, sparkline: { enabled: false } },
stroke: { curve: "smooth", width: 2 },
grid: { borderColor: 'var(--tblr-border-color-translucent)', strokeDashArray: 4 },
annotations: transfer_enable_mb > 0 ? {
  yaxis: [{
    y: transfer_enable_mb,
    borderColor: 'var(--tblr-primary)',
    strokeDashArray: 6,
    label: { text: '流量上限', position: 'left' }
  }]
} : {},
```

---

## Implementation Units

### U1. 清理模板顶部死代码并搭建 2×2 网格骨架

**Goal:** 移除 `info_cards` foreach 块与不可见 hero 区；将主体重构为 `row row-cards row-deck` + 4 个 `col-lg-6` 卡片占位（卡片内部内容暂留旧实现以便逐 unit 替换）。

**Requirements:** R1, R2, R7

**Dependencies:** 无

**Files:**
- Modify: `resources/views/tabler/user/index.tpl`（删除 hero `.page-header` 段 + `info_cards` foreach；包裹主体为 `row row-cards row-deck`；调整 4 个卡片占位的 col 类名与顺序）

**Approach:**
- 删除模板行 108-120（`.page-header` 段）+ 行 124-156（外层 `col-12` 包裹 `info_cards` foreach）
- 把现有两个 `col-lg-6 col-sm-12` 列内的 vstack 拆开：左列保留「快速配置」、右列保留「流量用量」（合并目标卡，本 unit 仅占位），下排新增「置顶公告」与「在线 IP」两个 `col-lg-6`
- 移除两列原有的 `vstack` 中间多余卡片（签到块与「每小时用量」独立卡，由 U2/U4 处理；这一步仅做骨架重排）
- 外层网格类名：`<div class="row row-cards row-deck">`；每卡：`<div class="card h-100">`

**Patterns to follow:**
- `resources/views/tabler/admin/index.tpl` 行 20-107 的 `row row-deck row-cards` 用法
- 移动端断点行为沿用 Tabler 默认

**Test scenarios:**
- Happy path: 加载 `/user`，主体首屏看到 4 个等高占位卡片，2×2 排列。顶部不再有黑/暗空白区
- Edge case: 浏览器收窄到 < lg（默认 992px）断点，4 卡纵向堆叠为单列
- Edge case: 在 ≥xl 屏幕下，左右两列依然各占 50%，不出现 3-1 错位
- Test expectation: 视觉手动验证 + DevTools 断点切换；无单元测试

**Verification:**
- 顶部死代码彻底删除（grep `info_cards` 在模板内 0 匹配）
- 主体 DOM 树呈 `row-cards row-deck > 4×col-lg-6 > card` 结构
- `< lg` 断点四卡纵向堆叠

---

### U2. 「流量用量」卡片重构（合并每小时用量、三段式布局）

**Goal:** 把原右列的「流量用量」+「每小时用量」两张卡合并为一张三段式卡片：card-header（标题 + 等级徽标）→ 三统计框 → 折线图区域。

**Requirements:** R3, R6

**Dependencies:** U1

**Files:**
- Modify: `resources/views/tabler/user/index.tpl`（替换右上卡片内容；删除原"每小时用量"独立卡块）

**Approach:**
- 新结构（位于 U1 占位的右上 `col-lg-6 > .card`）：
  - `.card-header`：左侧 `<h3 class="card-title">流量用量</h3>`，右侧按 `$user->class > 0` 三态徽标
    - `class > 0`：`<span class="badge bg-blue-lt">LV.{$user->class} · {$class_expire_days} 天到期</span>`
    - `class === 0`：`<a href="/user/product" class="btn btn-sm btn-primary">购买套餐</a>`
  - `.card-body`：
    - 顶部 `<div class="row row-cards mb-3">` 三栏（每个 `col-4`，内嵌 `.bg-light` 或 `.bg-muted-lt` 圆角小卡片）
      - 框 1：值 `{$user->TodayusedTraffic()}`，标签"今日用量"
      - 框 2：值 `{$user->LastusedTraffic()}`，标签"过去用量"
      - 框 3：值 `{$user->unusedTraffic()}`，标签"剩余流量"
    - 折线图容器：`<div id="traffic-log" style="height: 260px"></div>`（仅当 `$public_setting['traffic_log']` 为真时渲染；否则留白占位 `<div class="text-center text-secondary py-4">每小时用量未启用</div>`）
- 删除原模板中"每小时用量"独立 `.card`（行 322-329）

**Patterns to follow:**
- 三统计框样式参考 reference 图：浅色圆角底 + 大号粗体数值 + 小标签
- 可直接套 `card-body` 内 `.bg-muted-lt rounded p-3 text-center` 组合

**Test scenarios:**
- Happy path: `class=3`、`transfer_enable>0`、`traffic_log=true`、有用量 → 右上角显示等级徽标，三统计框各填三个数值，折线图渲染
- Edge case (class=0): 右上角变为"购买套餐"按钮链接 `/user/product`
- Edge case (traffic_log=false): 折线区域显示"每小时用量未启用"占位，三统计框正常显示
- Edge case (新注册零用量): 三统计框分别显示 `0 MB / 0 MB / X GB`，不报错
- Test expectation: 视觉手动验证 + 切换 `Config::set('traffic_log', false)` 验证降级

**Verification:**
- 「流量用量」卡片高度与左侧「快速配置」目测差 ≤ 15%
- 三个统计数字在一屏内可见，无需滚动卡内
- `traffic_log` 关闭时无 JS 报错

---

### U3. ApexCharts 配置升级（dashed pool limit + smooth + Tabler 风格）

**Goal:** 重写 `getTrafficChartConfig`，对齐 reference 视觉 — smooth 曲线、隐藏 toolbar、淡虚线网格、`流量上限` annotations 横线、图例样式。

**Requirements:** R3, R4, R6

**Dependencies:** U2（折线图容器需先就位）

**Files:**
- Modify: `resources/views/tabler/user/index.tpl`（内嵌 `<script>` 中的 `getTrafficChartConfig`、`initTrafficChart`、`window.APP_CONFIG`）

**Approach:**
- 在 `window.APP_CONFIG` 中新增 `transferEnableMb: {$user->transfer_enable / 1048576}`（PHP 端整数计算后注入；`transfer_enable === 0` 时输出 `0`）
- `getTrafficChartConfig(trafficData, transferEnableMb)` 签名加第二参数；返回对象增删如下：
  - `chart.toolbar.show = false`（原已有）
  - `stroke.curve = "smooth"`、`stroke.width = 2`
  - `grid.strokeDashArray = 4`、`grid.borderColor` 透明化
  - `annotations.yaxis = transferEnableMb > 0 ? [{ y: transferEnableMb, borderColor: 'var(--tblr-primary)', strokeDashArray: 6, label: { text: '流量上限', position: 'left', style: { background: 'var(--tblr-primary)', color: '#fff' } } }] : []`
  - `legend.show = true`、`legend.position = 'bottom'`、`legend.horizontalAlign = 'right'`
  - `series[0].name = '当日用量（MB）'`
- `initTrafficChart()` 调用处把 `transferEnableMb` 传入
- 保留 `colors: ["#FF4500"]`、`xaxis.categories`、`yaxis.title.text = "使用流量（MB）"`

**Patterns to follow:**
- ApexCharts 官方 `annotations.yaxis` 文档（已捆绑在 Tabler core，无需新依赖）

**Test scenarios:**
- Happy path: `transfer_enable = 200 GB` → 折线图渲染、`200000 MB` 处有红/蓝 dashed 横线标注 `流量上限`
- Edge case: `transfer_enable === 0` → 不渲染 annotation 横线，图表正常工作
- Edge case: `traffic_log = false` → `initTrafficChart` 被 U2 的 `{if}` 包裹，不执行（控制台无错误）
- Edge case: 一天内某些小时 = 0 → 折线在底部贴轴平滑过渡
- Integration: 与 U2 联调 — 折线图区域高度填满 260px，annotation label 文字不溢出右上角 toolbar 占用区
- Test expectation: 浏览器手动验证 + DevTools Network 看 apexcharts.min.js 200 + Console 0 error

**Verification:**
- 折线图视觉接近 reference：smooth 曲线、虚线网格、右下角图例 `当日用量（MB）`
- pool limit 横线在 transfer_enable>0 时可见

---

### U4. 「快速配置」卡内嵌签到 banner

**Goal:** 把原独立的「每日签到」卡片改造为「快速配置」`.card-body` 顶部的紧凑 banner；保留 HTMX 提交逻辑、captcha 兼容、签到状态切换。

**Requirements:** R5, R8

**Dependencies:** U1

**Files:**
- Modify: `resources/views/tabler/user/index.tpl`（删除原独立签到 `.card`；在「快速配置」`.card-body` 顶部插入 banner 块）

**Approach:**
- Banner 位置：`<div class="card-body">` 的第一个子元素
- 包裹条件：`{if $public_setting['enable_checkin']}` ... `{/if}`
- Banner DOM：
  ```html
  <div class="alert bg-green-lt border-0 mb-3 d-flex align-items-center">
    <i class="ti ti-gift icon me-2"></i>
    <div class="flex-fill">
      签到可领取 <code>{$min}</code>{if min!==max}-<code>{$max}</code>{/if} MB 流量
      <small class="text-secondary d-block">上次签到：<span id="last-checkin-time">{$user->lastCheckInTime()}</span></small>
    </div>
    <div>
      {if !$user->isAbleToCheckin()}
        <button id="check-in" class="btn btn-sm btn-success" disabled>已签到</button>
      {else}
        {if $public_setting['enable_checkin_captcha']}{include file='captcha/div.tpl'}{/if}
        <button id="check-in" class="btn btn-sm btn-success"
                hx-post="/user/checkin" hx-swap="none"
                hx-vals='js:{ {if ...}{include file='captcha/ajax.tpl'}{/if} }'>签到</button>
      {/if}
    </div>
  </div>
  ```
- 保持原有 captcha include 与 HTMX 属性（结构、参数不变）
- 删除原独立签到 `.card`（行 208-253）

**Patterns to follow:**
- Tabler `alert bg-{color}-lt` 软背景 banner
- 保留 origin 模板的 captcha include 路径不变

**Test scenarios:**
- Happy path (enable_checkin=true, 可签到): 「快速配置」卡顶部 banner 可见、签到按钮可点击；提交后 HTMX 无刷新更新（沿用现有行为）
- Edge case (enable_checkin=true, 已签到): banner 显示"已签到"灰按钮，禁用态
- Edge case (enable_checkin=false): banner 不渲染；「快速配置」卡顶部直接显示推荐客户端
- Edge case (enable_checkin_captcha=true): captcha div 正常 include，验证流程可用
- Integration: HTMX `hx-post /user/checkin` 路由响应保持原契约，无后端变更
- Test expectation: 三种 config 组合下手动验证；签到端到端走一次

**Verification:**
- 2×2 网格在签到开关任意状态下都保持 4 卡
- 签到行为与改造前等价

---

### U5. 「置顶公告」与「在线 IP」卡片高度均衡 + ribbon 改样式

**Goal:** 让下排两卡视觉协调；公告内容过长时卡内滚动而非撑爆整列；移除原右上角浮动 ribbon 改为常规 card-header。

**Requirements:** R1, R6, R7

**Dependencies:** U1

**Files:**
- Modify: `resources/views/tabler/user/index.tpl`（左下「置顶公告」卡 + 右下「在线 IP」卡微调）

**Approach:**
- 「置顶公告」：
  - 用 `.card-header` 替换原 `ribbon` 浮动样式：`<div class="card-header"><h3 class="card-title"><i class="ti ti-bell-ringing text-yellow me-2"></i>置顶公告</h3>{if $ann}<div class="card-subtitle">{$ann->date}</div>{/if}</div>`
  - `.card-body` 加 `style="max-height: 480px; overflow-y: auto"`
  - `$ann === null` 时 body 显示居中 empty state（`empty empty-icon`）
- 「在线 IP」：基本沿用现状（已经有 `max-height: 300px` 的 table-responsive），仅微调使其与左下卡同高趋势 — 把 `.card-body` 外层不加 max-height，让 `row-deck` 自动拉伸；表格本身保持内滚

**Patterns to follow:**
- Tabler `.empty` 空态组件
- `card-header + card-subtitle` 同级布局

**Test scenarios:**
- Happy path (有公告 + 有 IP): 两卡高度大致一致；公告内容超长时卡内出现垂直滚动条
- Edge case (无公告): 左下卡显示 `empty` 空态
- Edge case (无在线 IP): 右下卡显示原有 `empty` 空态（已存在）
- Edge case (大量在线 IP, >10): 表格内滚 300px，卡片不无限延伸
- Test expectation: 视觉手动验证 + 临时插入 5000 字公告测试 overflow

**Verification:**
- 下排两卡高度差 ≤ 15%
- 长公告/多 IP 不破坏 2×2 网格

---

### U6. 清理控制器中未被新模板使用的 assign

**Goal:** 移除 `UserController::index` 中模板未使用的 4 个 assign，降低噪音。

**Requirements:** R8（确保不回归 — 验证清理后页面不变）

**Dependencies:** U1, U2, U4, U5（确认新模板不再引用这些变量）

**Files:**
- Modify: `src/Controllers/UserController.php`

**Approach:**
- 在 `index()` 方法的 `return $response->write(...)` 块中删除四行：
  - `->assign('user_class', $this->user->class)`
  - `->assign('user_money', $this->user->money)`
  - `->assign('ip_limit', $this->user->node_iplimit)`
  - `->assign('speed_limit', $this->user->node_speedlimit)`
- 保留 `ann / captcha / traffic_logs / class_expire_days / UniversalSub / clientData / platformIcons / online_ips`

**Patterns to follow:**
- 既有的 `->assign('...')` 链式风格

**Test scenarios:**
- Happy path: 删除后页面渲染与 U1-U5 完成后状态完全一致
- Test expectation: 删除前后 `grep -E '\$(user_class|user_money|ip_limit|speed_limit)' resources/views/tabler/user/index.tpl` 必须 0 匹配（前提条件）；删除后页面访问无 PHP warning/notice

**Verification:**
- `grep -rn "user_class\|user_money\|ip_limit\|speed_limit" resources/views/tabler/user/index.tpl` 0 匹配
- 页面 200 + 内容渲染正常

---

## System-Wide Impact

- **Interaction graph:** 仅触及 `/user` GET 路由的视图层。HTMX 签到端点 `POST /user/checkin` 契约不变；订阅/客户端推荐 JS 行为不变
- **Error propagation:** 无新增错误路径。`transfer_enable === 0` 与 `traffic_log === false` 的降级走前端条件渲染，不抛异常
- **State lifecycle risks:** 无新增状态。签到按钮位置变化但 DOM id `check-in`、`last-checkin-time` 保留以兼容现有 JS
- **API surface parity:** 无 API 改动
- **Integration coverage:** 推荐客户端 JS（`detectOS`、`initClientSelector`、`initClipboard`）、ApexCharts 渲染、签到 HTMX 是跨层关键路径，需在测试矩阵中确认不回归
- **Unchanged invariants:** 路由表、控制器方法签名、模型方法、数据库 schema 全部不变；本计划只是 UI 视图层 + 4 个无用 assign 删除

---

## Risks & Dependencies

| Risk | Mitigation |
|------|------------|
| ApexCharts `annotations` 在 Tabler 捆绑版本中行为差异 | U3 完成后手动验证 dashed 线渲染；若不可用，降级为隐藏 annotation（已在 `transfer_enable === 0` 路径中处理）|
| 签到 banner 的 DOM 嵌套层级变化可能让外部 CSS / 第三方主题失配 | 保留 `id="check-in"` / `id="last-checkin-time"` / `class="copy"` 等关键 hook；改造仅调整外层 wrapper |
| `row-deck` 等高在 IE/老浏览器不支持 | 项目已基线放弃 IE（Tabler 4 要求），不投入兼容成本 |
| 控制器 assign 清理误删模板仍在引用的变量 | U6 在 U1-U5 全部 merge 后再执行；grep 检查作为前置 verification |
| 长公告 + 高频签到组合下 banner 状态不刷新 | 沿用现有 HTMX 行为，本改造不改 `hx-post` 语义，回归风险低 |

---

## Documentation / Operational Notes

- 无需更新外部用户文档（UI 视觉变化，自解释）
- 无需 DB migration
- 无需 feature flag / 灰度（前端 only，配置开关已通过既有 `$public_setting` 控制）
- 部署：常规模板更新，无需清缓存（Smarty 编译会自动刷新）；若运维启用了 OPcache，按既有发布流程 reload

---

## Test Matrix

合并各 unit 的关键配置组合，作为最终验收清单：

| # | enable_checkin | traffic_log | class | transfer_enable | ann | online_ips | 预期 |
|---|----------------|-------------|-------|-----------------|-----|------------|------|
| 1 | true | true | 3 | >0 | 有 | 多个 | 全功能：签到 banner、徽标、三统计 + 折线 + 上限线、公告、IP 表 |
| 2 | false | true | 3 | >0 | 有 | 多个 | 无签到 banner，其余如 #1 |
| 3 | true | false | 3 | >0 | 有 | 多个 | 折线区降级为"未启用"占位，其余如 #1 |
| 4 | true | true | 0 | 0 | 有 | 多个 | 徽标变"购买套餐" CTA、无 pool limit 线 |
| 5 | true | true | 3 | >0 | null | 0 | 公告空态 + IP 空态 |
| 6 | true | true | 3 | >0 | 超长公告 | 多个 | 公告卡内滚动，不撑爆 2×2 |

每条手动跑一次，截图存档以备 review。

---

## Sources & References

- **Origin document:** `docs/brainstorms/2026-05-18-user-home-2x2-cards-requirements.md`
- 主模板：`resources/views/tabler/user/index.tpl`
- 控制器：`src/Controllers/UserController.php` 行 30-93
- 模型：`src/Models/User.php` 行 138-195（流量相关方法）
- 现有等高卡片模式：`resources/views/tabler/admin/index.tpl` 行 20-107
- ApexCharts 加载源：Tabler core CDN（无需变更）
- Reference 图：用户对话中提供的 Domestic Pool Usage 截图
