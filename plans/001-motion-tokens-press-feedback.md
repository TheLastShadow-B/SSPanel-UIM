# 001 — 动效基建：easing token、按钮按压反馈、抽屉曲线

- **Status**: TODO
- **Commit**: d361545d
- **Severity**: MEDIUM（但是后续所有动效计划的前置）
- **Category**: Cohesion & tokens / Physicality
- **Estimated scope**: 3 files（app.css + 2 个 shell 模板），约 15 行

## Problem

cafe 主题没有任何动效 token。曲线与时长散落各处、互不一致：

```css
/* resources/theme/cafe/app.css:118-122 — 现状：按钮无按压反馈 */
@utility btn {
    @apply inline-flex cursor-pointer items-center justify-center gap-1.5
        rounded-full px-5 py-2.5 text-sm font-medium whitespace-nowrap
        transition-colors disabled:cursor-not-allowed disabled:opacity-50;
}
```

```html
<!-- resources/views/cafe/shell/header.tpl:27-29 — 现状：抽屉用 Tailwind 默认曲线 -->
<aside :class="sidebar ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="bg-card border-hairline fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col
              border-r px-4 py-5 transition-transform duration-200 lg:translate-x-0">
```

`resources/views/cafe/shell/admin_header.tpl:26-28` 有同样的 aside（class 内容相同）。

问题：

1. 按钮按下时没有任何物理反馈。所有 `.btn-*`（primary/secondary/outline/danger-soft）只有颜色过渡。
2. 移动端侧栏抽屉用 Tailwind 默认 `cubic-bezier(0.4, 0, 0.2, 1)`，不是抽屉该有的 iOS 曲线。
3. 后续计划（toast、确认模态）需要 `var(--ease-out)` 这个 CSS 变量存在。

## Target

### 1. app.css 的 `@theme` 块（第 37-75 行）末尾、`--radius-tile: 10px;` 之后追加：

```css
    /* 动效曲线：强 ease-out（UI 进出场）、iOS 抽屉曲线 */
    --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
    --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
```

说明：`--ease-out` 会覆盖 Tailwind v4 内置的 `ease-out` utility 的值——目前模板里没有任何地方用 `ease-out` 类，无回归风险。同时这两个变量会以 `var(--ease-out)` / `var(--ease-drawer)` 的形式出现在产物 CSS 的 `:root`，供手写 CSS 引用。

### 2. `@utility btn`（app.css:118-122）替换为：

```css
@utility btn {
    @apply inline-flex cursor-pointer items-center justify-center gap-1.5
        rounded-full px-5 py-2.5 text-sm font-medium whitespace-nowrap
        transition-[color,background-color,border-color,transform] duration-150 ease-out
        active:scale-[0.97]
        disabled:cursor-not-allowed disabled:opacity-50;
}
```

（`transition-colors` → 显式属性列表加 `transform`；150ms 在按压反馈 100-160ms 预算内；`ease-out` 经过第 1 步已是强曲线。disabled 元素收不到 :active，无需额外处理。）

### 3. 两个抽屉 aside 的 class 里，把 `transition-transform duration-200` 改为 `transition-transform duration-250 ease-drawer`：

- `resources/views/cafe/shell/header.tpl:29`
- `resources/views/cafe/shell/admin_header.tpl:28`

## Repo conventions to follow

- 设计 token 全部住在 app.css 的 `@theme` 块（颜色、圆角都在那里，见 app.css:37-75），easing 加同一个块。
- 模板类名用 Tailwind utility；`duration-250` 是 Tailwind v4 合法的按需数值。
- **改完 app.css 必须跑 `npm run build:cafe`**，产物 `public/theme/cafe/app.css` 入库（一起提交）。

## Steps

1. 在 `resources/theme/cafe/app.css` 的 `@theme` 块末尾加两行 easing token（见 Target 1）。
2. 替换 `@utility btn`（见 Target 2）。
3. 修改 `resources/views/cafe/shell/header.tpl:29` 与 `resources/views/cafe/shell/admin_header.tpl:28` 的抽屉过渡类（见 Target 3）。
4. 运行 `npm run build:cafe`。

## Boundaries

- 不要动 `.btn-sm`、`.badge`、`.side-link`、`.pill-tab` 等其他组件类。
- 不要改任何模板的结构/标记，只改 class 字符串中列出的那几个类。
- 不要新增依赖。
- 如果 app.css 行号对不上（相对 commit d361545d 有漂移），按内容定位；内容也对不上就停下报告。

## Verification

- **Mechanical**: `npm run build:cafe` 成功退出；`git diff public/theme/cafe/app.css` 非空且包含 `cubic-bezier(0.23, 1, 0.32, 1)`；`grep -c "ease-drawer" resources/views/cafe/shell/header.tpl resources/views/cafe/shell/admin_header.tpl` 各为 1。
- **Feel check**（本地起服务或推测试服后）：
  - 鼠标按住任意主按钮不放：按钮缩到 97% 并保持；松开回弹。缩放应细微、不弹跳。
  - 窗口缩到 <1024px 宽，点汉堡打开侧栏：滑入起步快、末段缓，类似 iOS 侧滑；DevTools Animations 面板 10% 速度确认曲线前快后慢。
  - 深色模式下重复以上,行为一致。
- **Done when**: 上述三条 feel check 通过且产物 CSS 已重建入库。
