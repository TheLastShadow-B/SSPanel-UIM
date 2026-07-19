# 002 — Toast 入场动画 + 出场方向修正

- **Status**: TODO（依赖 001 提供 `var(--ease-out)`）
- **Commit**: d361545d
- **Severity**: HIGH
- **Category**: Purpose & frequency / Physicality & origin
- **Estimated scope**: 2 files（toast.tpl + app.css），约 20 行

## Problem

toast 是全站出现最频繁的动态元素（每次 htmx 操作后都会弹），但它**没有入场动画**——`appendChild` 后直接闪现。出场倒是有动画，方向却是**向下**淡出（`translateY(6px)`），而 toast 栈固定在**右上角**：元素朝远离自己所属边缘的方向退场，空间叙事是反的（该从哪来回哪去）。

```js
// resources/views/cafe/shell/toast.tpl:8-19 — 现状
const el = document.createElement('div');
el.className = 'toast';
// ...innerHTML...
stack.appendChild(el);                       // ← 无入场过渡，闪现
setTimeout(function () {
    el.style.transition = 'opacity .3s, transform .3s';
    el.style.opacity = '0';
    el.style.transform = 'translateY(6px)';  // ← 右上角的 toast 却向下退场
    setTimeout(function () { el.remove(); }, 300);
}, 3200);
```

```css
/* resources/theme/cafe/app.css:330-333 — 现状：无过渡属性 */
.toast {
    @apply c-card text-ink pointer-events-auto flex w-fit max-w-sm items-center
        gap-2.5 px-4 py-3 text-sm font-medium shadow-lg;
}
```

## Target

进出同一条边（顶部）：入场从上方 8px 滑落 + 淡入 200ms；出场回到上方 8px + 淡出 150ms（出场比入场快）。全部走 CSS class + transition（可中断、可重定向），去掉内联 style。

### app.css：`.toast` 规则替换为

```css
    /* ---- toast:按内容自适应宽度,过长换行;进出均走顶部边缘 ---- */
    .toast {
        @apply c-card text-ink pointer-events-auto flex w-fit max-w-sm items-center
            gap-2.5 px-4 py-3 text-sm font-medium shadow-lg;
        transition: opacity 200ms var(--ease-out), transform 200ms var(--ease-out);
    }
    .toast.toast-out {
        opacity: 0;
        transform: translateY(-8px);
        transition-duration: 150ms;
    }
```

（目标态带 `.toast-out` 时用 150ms —— 即出场 150ms；移除 class 回到基态时用基态的 200ms —— 即入场 200ms。）

### toast.tpl：`showToast` 函数替换为

```js
    function showToast(msg, type) {
        const stack = document.getElementById('toast-stack');
        const el = document.createElement('div');
        el.className = 'toast toast-out';
        const ico = type === 'success' ? 'ti-circle-check text-success' : 'ti-circle-x text-danger';
        el.innerHTML = '<i class="ti ' + ico + ' text-lg"></i><span></span>';
        el.querySelector('span').textContent = msg;
        stack.appendChild(el);
        void el.offsetHeight;                 // 先落一帧初始态,入场过渡才播得出来
        el.classList.remove('toast-out');
        setTimeout(function () {
            el.classList.add('toast-out');
            setTimeout(function () { el.remove(); }, 150);
        }, 3200);
    }
```

其余部分（ClipboardJS、htmx:afterRequest 协议处理）一律不动。

## Repo conventions to follow

- `var(--ease-out)` 由计划 001 写入 `@theme`，产物 CSS 会输出到 `:root`。**先执行 001。**
- 组件 CSS 住在 app.css 的 `@layer components`（`.toast` 已在其中，原地改）。
- 改完 app.css 跑 `npm run build:cafe`，产物入库。

## Steps

1. 按 Target 替换 `resources/theme/cafe/app.css` 中 `.toast` 规则（app.css:329-334 附近）。
2. 按 Target 替换 `resources/views/cafe/shell/toast.tpl` 的 `showToast`（toast.tpl:6-20）。
3. `npm run build:cafe`。

## Boundaries

- 不动 `#toast-stack` 容器的定位/布局 class。
- 不动 toast.tpl 里 htmx JSON 协议处理与剪贴板逻辑。
- 不引入 `@keyframes`（必须是 transition，保持可中断）。
- 与 commit d361545d 相比代码对不上时停下报告，不要即兴改。

## Verification

- **Mechanical**: `npm run build:cafe` 成功；产物 `public/theme/cafe/app.css` 中能 grep 到 `.toast-out`。
- **Feel check**：
  - 任意页面点一个「复制」按钮：toast 从上方 8px 轻轻落下淡入；3.2s 后向上淡出。出场应明显比入场干脆。
  - 连点复制 5 次：多条 toast 依次入栈，各自动画独立，无跳帧、无从头重播。
  - DevTools Animations 面板 10% 速度：入场是强 ease-out（起步快收尾缓），无二段跳。
- **Done when**: feel check 全过，产物已重建。
