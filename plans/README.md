# cafe 主题动效改进计划

由 `improve-animations` 审计产出（2026-07-20，基于 commit d361545d）。每份计划自包含，可交给任意执行者（含低配模型）直接实施。审计完整报告见当次会话记录。

| # | 计划 | 严重度 | 状态 |
| --- | --- | --- | --- |
| 001 | [动效基建：easing token、按钮按压反馈、抽屉曲线](001-motion-tokens-press-feedback.md) | MEDIUM（前置） | TODO |
| 002 | [Toast 入场动画 + 出场方向修正](002-toast-enter-exit.md) | HIGH | TODO |
| 003 | [确认模态进出场过渡](003-confirm-modal-transition.md) | HIGH | TODO |
| 004 | [Alpine 模态与侧栏遮罩过渡](004-alpine-modal-scrim-transitions.md) | MEDIUM | TODO |
| 005 | [prefers-reduced-motion 支持](005-reduced-motion.md) | MEDIUM | TODO |

## 执行顺序与依赖

1. **001 必须最先**：它写入 `--ease-out` / `--ease-drawer` token，002/003/004 都引用 `var(--ease-out)`；004 的遮罩时长要与 001 改后的抽屉 `duration-250` 对齐。
2. 002、003、004 相互独立，可并行或任意顺序。
3. **005 最后**：全局 reduced-motion 兜底，最后加以覆盖前面所有新增动效。

每份计划改动 `resources/theme/cafe/app.css` 后都要 `npm run build:cafe` 并把产物 `public/theme/cafe/app.css` 一起提交。

## 审计中记录但未开计划的低优先级项

- 灯箱关闭无退场动画（`ticket_chat.tpl:81` 直接 `overlay.remove()`；入场却有 fade+zoom）——低频，150ms 淡出即可。
- 订阅页平台手风琴瞬开瞬关（`user/subscription.tpl:264`，chevron 有旋转动画但面板无过渡）——可用 `grid-template-rows: 0fr→1fr` 过渡，不必引入 collapse 插件。
- `.meter` 进度条 `transition-[width]`（app.css:263）目前是休眠代码（服务端渲染定宽，从不触发）；若未来做成实时更新，应改用 transform 方案。
