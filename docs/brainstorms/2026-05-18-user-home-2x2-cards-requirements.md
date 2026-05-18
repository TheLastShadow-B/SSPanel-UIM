# 用户首页 2×2 主卡片布局改造

- **日期**: 2026-05-18
- **范围**: Standard（前端模板 + 局部脚本，零后端改动）
- **目标页面**: `/user` 首页（`resources/views/tabler/user/index.tpl`）
- **状态**: Requirements — 待 `/ce-plan`

## 1. 背景与动机

当前 `/user` 首页存在以下视觉/结构问题（参见 `2026-05-18 实际截图`）：

1. 模板顶部 `{foreach $info_cards as $card}` 引用的 `$info_cards` 变量在 `src/Controllers/UserController.php::index` 中从未被赋值 → 渲染为空，页面顶部出现一大段黑/暗色空白（hero 区域 `text-white` 标题"用户中心"在浅色背景下也不可见）。
2. 主体采用 `col-lg-6` 双列纵向 vstack，左右两列内容量严重失衡：
   - 左列：快速配置（很高）→ 置顶公告（很高）
   - 右列：流量用量（很矮，只有 1 个进度条 + 1 行文字）→ 每小时用量（中等）→ 在线 IP（中等）
3. 「流量用量」展示用堆叠双段进度条，密度低、数字埋在图例下方、"你的 LV.X 账户 N 天后到期" 是混在正文中的灰字，缺乏层次。
4. 「每小时用量」单独成卡，浪费纵向空间。

## 2. 目标

把首页主体重构为 **严格 2×2 主卡片网格**，对齐 reference（Domestic Pool Usage）的简洁信息密度。

| 位置 | 卡片 | 内容来源 |
|------|------|----------|
| 左上 | 快速配置 | 沿用现有推荐客户端 + 折叠的其他平台 |
| 右上 | 流量用量（合并每小时用量） | 三联统计 + 折线图 + 右上角等级到期徽标 |
| 左下 | 置顶公告 | 沿用现有公告渲染 |
| 右下 | 在线 IP 列表 | 沿用现有 5 分钟在线连接表格 |

## 3. 用户故事

- **作为用户**，我打开 `/user` 想一眼看到：怎么用、用了多少、官方说什么、谁在用我的账号 — 因此希望四件事各占一象限，无需滚动半屏才找到。
- **作为用户**，我打开"流量用量"想立刻看到三个关键数字（已用 / 剩余 / 周期）并通过曲线判断今天是否异常 — 不希望分散在两张卡里。

## 4. 范围

### 4.1 In Scope

- 删除模板顶部死代码（`info_cards` foreach 块）与对应的 `page-header` hero 区（或修复其在浅色背景下不可见的问题，方案见 §6）。
- 主体改造为 `row-cards` 下的 2×2 网格：`col-lg-6` × 4，移动端自然堆叠为单列。
- **右上「流量用量」卡片重构**为三段式：
  - **Header**：`流量用量` 标题（左）+ 右上角 `LV.X · N 天到期` 徽标（或新手"前往商店购买套餐" CTA）。
  - **Stats Row**：3 个 reference 风格圆角浅底统计框 — `今日用量` / `过去用量` / `剩余流量`。
  - **Chart**：折线图（沿用 `$traffic_logs` 数据 + 现有 ApexCharts），样式向 reference 靠拢 — smooth 曲线、淡虚线网格、隐藏 toolbar、添加 `流量上限` 虚线参考线（按等级套餐总额计算）。
  - **Legend**：右下小图例 — `流量上限`（虚线）+ `当日用量`（实线）。
  - **若 `traffic_log` 关闭**：折线区域优雅退化为留白占位（保持卡片高度均衡），统计框保留。
- **左上「快速配置」**：保留推荐客户端 + 折叠其他平台逻辑；若 `enable_checkin` 开启，把签到块改为卡片**顶部紧凑横条 banner**（一行：签到状态 + MB 区间 + 按钮），不破坏 2×2 网格。
- **左下「置顶公告」**：内容过长时给 card-body 加 `max-height` + 内部滚动，避免单卡撑爆整列。
- **右下「在线 IP」**：沿用现有表格 + 空态。最大高度沿用现有 `300px` 内滚。
- 移动端断点：`< lg` 时四张卡堆叠为单列。

### 4.2 Deferred / Out of Scope

- 后端数据接口、模型、控制器调整（沿用 `LastusedTraffic / TodayusedTraffic / unusedTraffic / class_expire_days / traffic_logs / online_ips / Ann`）。
- 推荐客户端 JS 行为（`detectOS`、`generateClientHtml`、剪贴板等保持不变；仅外层卡片样式可能微调）。
- Tabler 主题色 / 暗色模式 / 全站 layout 变更。
- 国际化文案改写。
- 每日签到业务逻辑。
- 顶部 hero 区是否最终保留 — 默认**移除**该区域（连同死代码 `info_cards` 一并清理），让 2×2 网格直接顶到容器顶部；若产品后续想要标题区，再单独决策。

## 5. 设计参考与规范

### 5.1 视觉 Reference

Reference 图（用户提供）：Domestic Pool Usage 卡片，由 4 段构成

1. 标题（含信息提示图标）+ 右上角日期范围
2. 三个圆角浅底统计框（值 + 标签）
3. 大尺寸折线图（带虚线池上限基准）
4. 右下图例：`Pool limit`（虚线）+ `Current cycle`（实线）

### 5.2 字段映射（右上"流量用量"卡）

| Reference 元素 | 本站映射 | 数据源 |
|----------------|----------|--------|
| 卡片标题 | 流量用量 | 静态 |
| 信息提示图标（i） | tooltip：解释"今日"指当日 00:00 起 | 静态 |
| 右上角日期范围 | `LV.{$user->class} · {$class_expire_days} 天到期` 或当 `class === 0` 显示"购买套餐" CTA | `$user->class`, `$class_expire_days` |
| Stat 1 | 今日用量 | `$user->TodayusedTraffic()` |
| Stat 2 | 过去用量 | `$user->LastusedTraffic()` |
| Stat 3 | 剩余流量 | `$user->unusedTraffic()` |
| 折线图 series | 每小时用量（24 点） | `$traffic_logs` (JSON, MB) |
| Pool limit 虚线 | 套餐流量上限 | `$user->transfer_enable`（如可用）— 在 plan 阶段确认字段名 |
| 图例 | `流量上限` + `当日用量` | 静态 |

### 5.3 高度均衡

- 左上、右上目标高度大致相等；
- 左下、右下目标高度大致相等；
- 通过 `h-100` + `d-flex flex-column` 让卡片在同一行内拉伸到同高，内部 chart 用 `height: 100%` 自适应。

## 6. 成功标准

1. 新页面在 `≥ lg` 屏下呈现严格 2×2 网格，四张卡片视觉对齐、左右两列高度差 ≤ 15%。
2. 顶部不再出现空白黑/暗区。
3. 「流量用量」卡片在 `≥ lg` 屏一屏可见三个统计数字 + 完整 24 小时曲线，无需滚动。
4. 移动端（< lg）四张卡纵向堆叠，单卡无水平溢出。
5. `enable_checkin` 开启时，签到按钮仍可正常使用且不破坏 2×2 网格结构。
6. `traffic_log` 关闭时，「流量用量」卡正常显示三统计框 + 留白占位（不报 JS 错）。
7. 已有 JS（推荐客户端检测、剪贴板、ApexCharts 渲染、签到 HTMX 提交）行为不回归。

## 7. 依赖与假设

- 假设 `$user->transfer_enable`（或等价字段）能提供"总流量"用作 Pool limit 参考线 — 若不存在，则 Pool limit 虚线**降级隐藏**，图例同步去掉，不阻塞改造。
- 假设 Tabler core CSS + ApexCharts 版本不变（不引入新依赖）。
- 假设页面其它入口（`/user/announcement`、`/user/product` 等）的样式无需联动调整。

## 8. 关键决策（默认值）

| # | 决策点 | 默认 | 备选 |
|---|--------|------|------|
| 1 | 顶部 hero（"用户中心"标题）处理 | 移除 | 修复 `text-white` 在浅色背景下不可见的问题并保留 |
| 2 | 顶部 `info_cards` 死代码 | 直接删除 | — |
| 3 | 每日签到位置 | 并入"快速配置"卡顶部 banner | 不动 / 移到流量卡 |
| 4 | "流量用量"右上角徽标内容 | 等级 + 到期天数 | 当前周期日期范围 |
| 5 | 三个统计框字段 | 今日 / 过去 / 剩余 | 已用% / 总额 / 等级 |
| 6 | 折线图配色 | 沿用当前橙红 `#FF4500` | 改为 Tabler primary 蓝 |
| 7 | Pool limit 字段不可得时 | 降级隐藏虚线 | 用 100% 比例替代 |

任一决策可在 plan 阶段调整，不阻塞当前需求落地。

## 9. 引用文件

- `resources/views/tabler/user/index.tpl` — 主模板（改造目标）
- `src/Controllers/UserController.php` — 数据装配（仅可能需移除已无引用的 assign，如 `user_class`、`user_money` 等若新模板不再用）
- `resources/views/tabler/user/header.tpl` / `footer.tpl` — 不动
- `public/` 下 Tabler / ApexCharts 静态资源 — 不动
