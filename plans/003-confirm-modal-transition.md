# 003 — 确认模态进出场过渡

- **Status**: TODO（依赖 001 提供 `var(--ease-out)`）
- **Commit**: d361545d
- **Severity**: HIGH
- **Category**: Purpose & frequency（防止生硬突变）
- **Estimated scope**: 2 files（confirm.tpl + app.css），约 30 行

## Problem

cafe 风格确认模态（替代原生 `confirm()`，把守删除等破坏性操作）目前用 `hidden`↔`flex` 硬切换，出现和消失都是零过渡的瞬移：

```html
<!-- resources/views/cafe/shell/confirm.tpl:2-11 — 现状 -->
<div id="cafe-confirm" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="c-card w-full max-w-sm p-6 shadow-xl">
```

```js
// resources/views/cafe/shell/confirm.tpl:21-22 — 现状
const show = function () { overlay.classList.remove('hidden'); overlay.classList.add('flex'); };
const hide = function () { overlay.classList.add('hidden'); overlay.classList.remove('flex'); };
```

## Target

遮罩淡入 150ms；卡片 `scale(0.96)+opacity` → 正常，200ms 强 ease-out。关闭整体 120ms 淡出（出场快于入场）。模态居中出现，`transform-origin` 保持默认 center（模态不锚定触发器，center 是对的）。

### app.css：`@layer components` 内（`.toast` 规则之前）新增

```css
    /* ---- hx-confirm 确认模态进出场 ---- */
    #cafe-confirm {
        opacity: 0;
        transition: opacity 120ms var(--ease-out);
    }
    #cafe-confirm.is-open {
        opacity: 1;
        transition-duration: 150ms;
    }
    #cafe-confirm > div {
        opacity: 0;
        transform: scale(0.96);
        transition: opacity 120ms var(--ease-out), transform 120ms var(--ease-out);
    }
    #cafe-confirm.is-open > div {
        opacity: 1;
        transform: scale(1);
        transition-duration: 200ms;
    }
```

### confirm.tpl：script 部分替换为

```js
    let cafeConfirmHideTimer = null;
    document.addEventListener('htmx:confirm', function (evt) {
        // htmx 对每个请求都发 confirm 事件;只拦截真正带 hx-confirm 的
        if (!evt.detail.question) return;
        evt.preventDefault();

        const overlay = document.getElementById('cafe-confirm');
        const show = function () {
            clearTimeout(cafeConfirmHideTimer);
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
            void overlay.offsetHeight;        // 先落一帧初始态,过渡才播得出来
            overlay.classList.add('is-open');
        };
        const hide = function () {
            overlay.classList.remove('is-open');
            cafeConfirmHideTimer = setTimeout(function () {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }, 120);
        };

        document.getElementById('cafe-confirm-msg').textContent = evt.detail.question;
        // onclick 赋值覆盖旧 handler,不会随弹窗次数累积
        document.getElementById('cafe-confirm-ok').onclick = function () { hide(); evt.detail.issueRequest(true); };
        document.getElementById('cafe-confirm-cancel').onclick = hide;
        overlay.onclick = function (e) { if (e.target === overlay) hide(); };
        show();
    });
```

（`cafeConfirmHideTimer` 在监听器外层声明：连续两次触发确认时，`show()` 会取消上一次未完成的隐藏定时器，避免刚打开的模态被旧定时器藏掉。）

模板的 HTML 结构（confirm.tpl:2-11）不变。

## Repo conventions to follow

- `var(--ease-out)` 来自计划 001。**先执行 001。**
- 手写组件 CSS 放 app.css `@layer components`（参照现有 `.lightbox-overlay`、`.toast` 的写法）。
- 改完 app.css 跑 `npm run build:cafe`，产物入库。

## Steps

1. 在 `resources/theme/cafe/app.css` 的 `@layer components` 中加入上面 CSS 块。
2. 替换 `resources/views/cafe/shell/confirm.tpl` 的 `{literal}<script>…</script>{/literal}` 内容为 Target 版本。
3. `npm run build:cafe`。

## Boundaries

- 不动 confirm.tpl 的 HTML 结构与按钮 id。
- 不改 htmx 拦截逻辑（`evt.detail.question` 判断、`issueRequest(true)`）。
- 不给卡片加非 center 的 transform-origin（模态就该从中心缩放）。
- 代码相对 d361545d 漂移则停下报告。

## Verification

- **Mechanical**: `npm run build:cafe` 成功；产物 CSS 能 grep 到 `#cafe-confirm.is-open`。
- **Feel check**：
  - 触发任意 `hx-confirm` 操作（如管理端删除一行）：遮罩淡入、卡片从 96% 轻轻放大浮现；点「取消」整体快速淡出（应比入场明显快）。
  - 快速连续触发两次确认：第二次弹窗不会被第一次的关闭定时器藏掉。
  - 点「确认」：模态消失且请求正常发出（toast 反馈照常）。
  - DevTools Animations 10% 速度：卡片缩放从中心发生,无位移漂移。
- **Done when**: feel check 全过，产物已重建。
