# 004 — Alpine 模态与侧栏遮罩过渡（10 个模态 + 2 个遮罩）

- **Status**: TODO（依赖 001 提供 `var(--ease-out)`）
- **Commit**: d361545d
- **Severity**: MEDIUM（单点低频，但覆盖全站所有对话框，整体收益大）
- **Category**: Purpose & frequency / Cohesion
- **Estimated scope**: 9 files（8 个模板 + app.css），每处 1-2 个属性/类

## Problem

全站 10 个 `x-teleport` 模态全部用裸 `x-show`，无任何过渡——遮罩和卡片瞬移出现/消失。同时移动端侧栏的黑色遮罩也是裸 `x-show`：侧栏在 200ms 滑动，遮罩却瞬间闪现，动静不同步。

模态 wrapper 现状（10 处结构完全一致，生成器产物）：

```html
<div x-show="showCreate" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40" @click="showCreate = false"></div>
    <div class="c-card relative … p-6 shadow-xl" …>
```

全部位置（wrapper 行号）：

| 文件 | 行 | x-show 变量 |
| --- | --- | --- |
| `resources/views/cafe/user/money.tpl` | 81 | showTopup |
| `resources/views/cafe/user/money.tpl` | 105 | showGiftcard |
| `resources/views/cafe/user/edit.tpl` | 389 | showTotp |
| `resources/views/cafe/user/edit.tpl` | 411 | showKill |
| `resources/views/cafe/user/ticket/index.tpl` | 55 | showCreate |
| `resources/views/cafe/admin/coupon.tpl` | 89 | showCreate |
| `resources/views/cafe/admin/giftcard.tpl` | 77 | showCreate |
| `resources/views/cafe/admin/detect.tpl` | 73 | showCreate |
| `resources/views/cafe/admin/user/index.tpl` | 92 | showCreate |
| `resources/views/cafe/admin/docs/create.tpl` | 57 | showGen |

侧栏遮罩现状：

```html
<!-- resources/views/cafe/shell/header.tpl:23-24（admin_header.tpl:22-23 同构） -->
<div x-show="sidebar" x-cloak @click="sidebar = false"
     class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>
```

仓库内的正确范例（用户菜单下拉，唯二用了 x-transition 的地方）：`resources/views/cafe/shell/header.tpl:131-133`。

## Target

- 模态 wrapper：整体淡入淡出 150ms（`x-transition.opacity.duration.150ms`）。
- 模态卡片：叠加纯 CSS 的入场缩放（`@starting-style`，从 `scale(0.96)` 到 1，200ms）。旧浏览器优雅降级为只有淡入。
- 侧栏遮罩：250ms 淡入淡出，与计划 001 改后的抽屉 `duration-250` 同步。

### app.css：`@layer components` 新增共享类

```css
    /* ---- 模态卡入场缩放:wrapper 由 x-transition 负责淡入,
            卡片用 @starting-style 补一点从 96% 的放大(旧浏览器降级为纯淡入) ---- */
    .modal-pop {
        transition: transform 200ms var(--ease-out);
        @starting-style {
            transform: scale(0.96);
        }
    }
```

### 10 个模态 wrapper：在 `x-cloak` 后加过渡指令

```html
<div x-show="showCreate" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-50 flex items-center justify-center p-4">
```

### 10 个模态卡片：紧跟 wrapper 的 `.c-card relative …` div 加 `modal-pop` 类

```html
<div class="c-card modal-pop relative max-h-[90vh] w-full max-w-md overflow-y-auto p-6 shadow-xl" …>
```

（各文件卡片 class 细节略有出入——`max-w-sm`/`max-w-md`、有无 `overflow-y-auto`——只加 `modal-pop`，其余原样保留。）

### 2 个侧栏遮罩：

```html
<div x-show="sidebar" x-cloak x-transition.opacity.duration.250ms @click="sidebar = false"
     class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>
```

- `resources/views/cafe/shell/header.tpl:23`
- `resources/views/cafe/shell/admin_header.tpl:22`

（若执行时抽屉仍是 `duration-200`——即 001 未先执行——遮罩也用 `.duration.200ms`，两者必须一致。）

## Repo conventions to follow

- Alpine 过渡用修饰符语法，参照 `header.tpl:132` 的 `x-transition.origin.top.right`。
- 共享组件类放 app.css `@layer components`。
- 改完 app.css 跑 `npm run build:cafe`，产物入库。
- 注意：这些列表页模板由生成器脚本生成过（scratchpad 的 gen_admin_lists.py），但脚本已是一次性产物，直接改模板即可。

## Steps

1. app.css 加 `.modal-pop`（见 Target），`npm run build:cafe`。
2. 逐个修改上表 10 处 wrapper（加 `x-transition.opacity.duration.150ms`）和对应卡片 div（加 `modal-pop`）。
3. 修改 2 处侧栏遮罩（加 `x-transition.opacity.duration.250ms`）。
4. 复核：`grep -rn "x-transition.opacity" resources/views/cafe/ | wc -l` 应为 12。

## Boundaries

- 不动模态内部的表单/按钮/htmx 属性。
- 不给 wrapper 加缩放（黑色全屏遮罩缩放会露出边缘）。
- 不引入 @alpinejs/collapse 等新依赖。
- 某个文件结构与上表对不上时跳过该文件并在结果中报告，不要即兴适配。

## Verification

- **Mechanical**: 上面第 4 步 grep 计数 = 12；`npm run build:cafe` 成功且产物含 `.modal-pop`。
- **Feel check**：
  - 用户端「余额充值」打开充值模态：遮罩淡入，卡片轻微放大浮现；取消时淡出，无瞬移。
  - Esc 关闭（有绑定的模态）与点遮罩关闭，退场一致。
  - 窗口 <1024px 开/关侧栏：遮罩淡入淡出与抽屉滑动同起同落。
  - 管理端优惠码「创建」模态同样生效（验证生成器系模板改到位）。
- **Done when**: grep 计数对上，任选 3 个模态 feel check 通过。
