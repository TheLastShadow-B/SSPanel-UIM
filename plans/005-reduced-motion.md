# 005 — prefers-reduced-motion 支持

- **Status**: TODO（建议最后执行，作为全局兜底）
- **Commit**: d361545d
- **Severity**: MEDIUM
- **Category**: Accessibility
- **Estimated scope**: 1 file（app.css），约 12 行

## Problem

整个 cafe 主题（源码与产物）没有任何 `prefers-reduced-motion` 处理：

```
$ grep -rn "prefers-reduced-motion" resources/views/cafe/ resources/theme/cafe/ public/theme/cafe/app.css
（无结果）
```

而主题里存在会触发前庭不适的位移/缩放/循环动画：侧栏抽屉平移（header.tpl:29）、节点在线状态的 `animate-ping` 无限脉冲（user/server.tpl:20）、灯箱缩放（app.css:307-310）、Alpine x-transition 缩放、toast 位移（计划 002 之后）。

## Target

app.css `@layer components` 之后、文件末尾追加全局规则：动画/过渡全部压到近零时长（保留状态但去掉运动），唯独加载指示 `animate-spin` 保留（它传达「正在加载」的功能语义，减速一半以降低刺激）：

```css
/* ---- 无障碍:系统偏好减少动效时,砍掉位移/缩放/循环动画,保留加载指示 ---- */
@media (prefers-reduced-motion: reduce) {
    *,
    ::before,
    ::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
    /* 旋转加载圈是理解性动画:保留,转速放慢 */
    .animate-spin {
        animation-duration: 2s !important;
        animation-iteration-count: infinite !important;
    }
}
```

说明：

- 样式表中的 `!important` 优先级高于 Alpine 运行时写的内联 transition 样式，因此 x-transition/x-show 的动画也会被一并压平——功能不受影响（状态切换照常，只是瞬时完成）。
- `animate-ping`（节点在线脉冲点）被压平后剩一个静止的实心点，语义仍在。
- 色彩/透明度 hover 过渡变为即时，属可接受范围；不做逐条豁免，保持规则简单可维护。

## Repo conventions to follow

- 全局样式住 `resources/theme/cafe/app.css`；追加到文件末尾（`@layer components` 花括号闭合之后，不要放进 layer 里——保证 !important 兜底不被 layer 排序影响）。
- 改完跑 `npm run build:cafe`，产物入库。

## Steps

1. 在 `resources/theme/cafe/app.css` 文件末尾（第 334 行 `}` 之后）追加上面的媒体查询块。
2. `npm run build:cafe`。

## Boundaries

- 只加这一个块，不改既有规则。
- 不要试图逐组件豁免（保持 opacity 过渡之类的精细化留给未来需要时再做）。
- 不动模板文件。

## Verification

- **Mechanical**: `npm run build:cafe` 成功；产物 CSS 能 grep 到 `prefers-reduced-motion`。
- **Feel check**（DevTools → Rendering → Emulate CSS media feature prefers-reduced-motion: reduce）：
  - 节点状态页：绿色脉冲点静止为实心点。
  - 开关移动端侧栏：瞬时开合，无滑动，功能正常。
  - 账单页加载中的 spinner 仍在旋转（变慢）。
  - 关闭模拟后一切动效恢复。
- **Done when**: 模拟开启时页面无位移/缩放/脉冲动画，spinner 仍可辨识为加载中。
