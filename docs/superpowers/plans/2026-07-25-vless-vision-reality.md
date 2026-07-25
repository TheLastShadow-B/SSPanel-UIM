# VLESS + Vision + REALITY 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 为 SSPanel-UIM 新增 `sort = 12` 的 VLESS 节点类型,支持 Vision + REALITY 全形态,后端对接 XrayR,订阅下发至 mihomo(Clash)与 Stash。

**Architecture:** admin 在节点的 `custom_config` 里写一份配置;`WebAPI\NodeController` 在下发给 XrayR 时按 `sort`/`security` 注入 `enable_vless` 与 `enable_reality` 两个开关;`Clash`/`Stash` 两个订阅生成器把同一份配置翻译成客户端方言,其中 REALITY 公钥由私钥经 X25519 基点标量乘实时推导。Surge 不支持 VLESS,不做改动。

**Tech Stack:** PHP 8.2+、Slim 4、Eloquent、Pest(PHPUnit 11)、libsodium(`ext-sodium`)、Smarty 模板

**Spec:** `docs/superpowers/specs/2026-07-25-vless-vision-reality-design.md`

## Global Constraints

- 所有 PHP 文件顶部为 `declare(strict_types=1);`
- `src/Utils/Tools.php` 的 `use function` / `use const` 导入列表按字母序排列,新增导入必须插入正确位置
- XrayR 判定 VLESS 的条件是 `enable_vless` **字符串** 等于 `"1"`,不是布尔 `true`,也不是 `"true"`
- XrayR 的 `reality-opts` 是**带连字符**的 JSON key;其内部字段用 snake_case(`server_names`、`private_key`、`short_ids`)
- mihomo / Stash 的 `reality-opts` 内部字段用**连字符**(`public-key`、`short-id`),且均为**单值字符串**,不是数组
- REALITY 公钥编码为 base64url 无 padding(字母表 `-_`,末尾不带 `=`)
- 单元测试不得依赖数据库:`tests/TestCase.php` 的 `$useDatabase` 默认为 `false`,保持不变
- Pest 在同一进程加载全部测试文件,测试辅助函数为全局函数,**函数名必须全仓库唯一**
- 提交信息用 Conventional Commits + 中文正文,并带两行 trailer:
  ```
  Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01J5wYZFANWzAFfoQ3q8s8QN
  ```
- 全部测试运行命令:`./vendor/bin/pest --testsuite=Unit`

## 已预先验证的测试机制

以下三项在编写本计划时已实测通过(PHP 8.5.7 + Pest),执行时无需再怀疑:

1. 对 Eloquent 模型用 `newInstanceWithoutConstructor()` 后直接赋值属性,再调用其方法读取该属性 —— 可行(`Node::$casts` 不含 `sort`,`match` 的严格比较拿到的是 int)
2. `ReflectionMethod::invoke()` 调用 `final class` 的 `private` 方法 —— 可行,PHP 8.1+ 无需 `setAccessible(true)`
3. Pest 断言链 `expect($x)->toHaveKey(...)->not->toHaveKey(...)` 与 `expect($x)->not->toHaveKey(...)->and($y)->toBe(...)` —— 两种混用写法均可行

## 关于 httpupgrade × flow 断言的有效性(一处已纠正的推理错误)

`buildVlessNode()` 里的 flow 守卫是 `if ($network !== 'tcp') { $flow = ''; }` —— 一个**拒绝列表**。

我最初认为「把 `httpupgrade → ws` 的改写块挪到守卫之后」就能让 `maps httpupgrade to ws and drops flow along with it` 这条断言失败。**这个判断是错的**:`'httpupgrade'` 与 `'ws'` 都不等于 `'tcp'`,两者都会触发守卫,所以调换这两块的次序不改变任何结果,断言照样通过。Task 4 的实现者实测后指出了这一点。

真正能让该断言失败的变异是**放宽守卫本身**,同时保持它在改写之前:

```php
if ($network !== 'tcp' && $network !== 'httpupgrade') {   // 错误的「httpupgrade 约等于裸 TCP」推理
    $flow = '';
}
```

这条变异下,`httpupgrade` 节点会保留 `flow: xtls-rprx-vision`,新断言精准失败(1 failed / 20 passed),而 `network=ws` 的直接用例与数值 short_id 用例不受影响。这也说明该断言防的是一个真实的误判方向 —— 有人推理「httpupgrade 本质上就是裸 TCP,Vision 应该照样能用」—— 而不是防次序调换。

Task 5 若要复核 Stash 侧的同一条断言,用上面这个变异,不要用调换次序。

## 相对 Spec 的偏离

1. **测试文件位置**:spec 写的是新建 `tests/Unit/Utils/RealityKeyTest.php`。实际改为并入已有的 `tests/Unit/Utils/ToolsTest.php` —— 该文件已是 `Tools` 的规范测试文件(312 行,每个方法一个 `describe()` 块),新建独立文件会打破这一约定。
2. **`buildVlessNode()` 的入参兜底**:`Clash.php:25` / `Stash.php:25` 的 `json_decode()` 在 `custom_config` 非法 JSON 时返回 `null`。新方法的参数类型为 `array`,故在调用处写 `$node_custom_config ?? []`,不修改共享的第 25 行(避免影响其他 5 个 case)。

## 文件结构

| 文件 | 职责 | 任务 |
|---|---|---|
| `src/Utils/Tools.php` | 新增 `genRealityPublicKey()`:X25519 私钥 → base64url 公钥 | 1 |
| `composer.json` | 声明 `ext-sodium` 依赖 | 1 |
| `tests/Unit/Utils/ToolsTest.php` | `genRealityPublicKey()` 的定值向量与失败路径测试 | 1 |
| `src/Models/Node.php` | `sort()` 枚举新增 `12 => 'VLESS'` | 2 |
| `resources/views/cafe/admin/node/create.tpl` | 接入类型下拉框新增 VLESS 选项 | 2 |
| `resources/views/cafe/admin/node/edit.tpl` | 同上,带 selected 判定 | 2 |
| `tests/Unit/Models/NodeSortTest.php` | `sort()` 枚举映射测试 | 2 |
| `src/Controllers/WebAPI/NodeController.php` | 向 XrayR 注入 `enable_vless` / `enable_reality` | 3 |
| `tests/Unit/Controllers/NodeXrayrCompatTest.php` | 注入逻辑测试 | 3 |
| `src/Services/Subscribe/Clash.php` | `buildVlessNode()` + `case 12` | 4 |
| `tests/Unit/Services/Subscribe/ClashVlessTest.php` | Clash VLESS 输出测试 | 4 |
| `src/Services/Subscribe/Stash.php` | `buildVlessNode()` + `case 12` | 5 |
| `tests/Unit/Services/Subscribe/StashVlessTest.php` | Stash VLESS 输出测试 | 5 |

任务顺序有依赖:任务 4、5 消费任务 1 产出的 `Tools::genRealityPublicKey()`。任务 2、3 相互独立。

## 测试向量

两个 X25519 定值向量,均已在 PHP 8.5.7 上实测确认:

**向量 A —— RFC 7748 §6.1 标准向量**(base64url 形态恰好不含 `-` / `_`)
- 私钥:`dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo`
- 私钥(带 padding):`dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo=`
- 公钥:`hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo`

**向量 B —— base64url 字母表含 `-` 与 `_`**(用于验证 `-_` → `+/` 转换)
- 私钥(base64url):`5lu859F0-nhDf-p_4T0en7M1iL0pCQqrfxiKmKdqTBQ`
- 私钥(标准 base64):`5lu859F0+nhDf+p/4T0en7M1iL0pCQqrfxiKmKdqTBQ=`
- 公钥:`gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0`

---

### Task 1: REALITY 公钥推导

**Files:**
- Modify: `src/Utils/Tools.php`(导入区 13-39 行;方法插入到 `genSs2022UserPk()` 之后,即第 232 行 `}` 与第 234 行 `toDateTime` 之间)
- Modify: `composer.json:21-22`(`ext-redis` 与 `ext-xml` 之间)
- Test: `tests/Unit/Utils/ToolsTest.php`(追加到文件末尾)

**Interfaces:**
- Consumes: 无
- Produces: `App\Utils\Tools::genRealityPublicKey(string $private_key): string` —— 入参为 base64url 或标准 base64 编码的 32 字节 X25519 私钥(有无 padding 均可);返回 base64url 无 padding 的公钥;任何解码失败或长度不为 32 字节时返回空字符串 `''`。任务 4、5 依赖此签名。

- [ ] **Step 1: 写失败的测试**

追加到 `tests/Unit/Utils/ToolsTest.php` 末尾:

```php
describe('Tools::genRealityPublicKey', function () {
    it('derives the RFC 7748 public key from a base64url private key', function () {
        expect(Tools::genRealityPublicKey('dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo'))
            ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('accepts a padded private key', function () {
        expect(Tools::genRealityPublicKey('dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo='))
            ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('accepts a base64url private key containing - and _', function () {
        expect(Tools::genRealityPublicKey('5lu859F0-nhDf-p_4T0en7M1iL0pCQqrfxiKmKdqTBQ'))
            ->toBe('gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0');
    });

    it('accepts the same key in the standard base64 alphabet', function () {
        expect(Tools::genRealityPublicKey('5lu859F0+nhDf+p/4T0en7M1iL0pCQqrfxiKmKdqTBQ='))
            ->toBe('gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0');
    });

    it('trims surrounding whitespace', function () {
        expect(Tools::genRealityPublicKey("  dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo\n"))
            ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('emits a base64url public key with no padding', function () {
        $pk = Tools::genRealityPublicKey('dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo');

        expect($pk)
            ->not->toContain('=')
            ->not->toContain('+')
            ->not->toContain('/');
    });

    it('returns an empty string when the key decodes to fewer than 32 bytes', function () {
        expect(Tools::genRealityPublicKey('c2hvcnQ='))->toBe('');
    });

    it('returns an empty string for non-base64 input', function () {
        expect(Tools::genRealityPublicKey('not!!valid!!base64'))->toBe('');
    });

    it('returns an empty string for empty input', function () {
        expect(Tools::genRealityPublicKey(''))->toBe('');
    });
});
```

- [ ] **Step 2: 运行测试确认失败**

Run: `./vendor/bin/pest --testsuite=Unit --filter='genRealityPublicKey'`
Expected: FAIL,报 `Call to undefined method App\Utils\Tools::genRealityPublicKey()`

- [ ] **Step 3: 在 composer.json 声明 ext-sodium**

修改 `composer.json`,在 `"ext-redis": "*",` 与 `"ext-xml": "*",` 之间插入一行(保持字母序):

```json
        "ext-redis": "*",
        "ext-sodium": "*",
        "ext-xml": "*",
```

- [ ] **Step 4: 补 Tools.php 的导入**

`src/Utils/Tools.php` 的 `use function` 列表按字母序,插入 5 个新导入:

- `use function base64_decode;` —— 插到第 15 行 `use function base64_encode;` **之前**
- `use function rtrim;` —— 插到 `use function round;` **之后**、`use function shuffle;` **之前**
- `use function sodium_crypto_scalarmult_base;` —— 插到 `use function shuffle;` **之后**、`use function strlen;` **之前**
- `use function strtr;` —— 插到 `use function strpos;` **之后**、`use function substr;` **之前**
- `use function trim;` —— 插到 `use function substr;` **之后**、`use const FILTER_FLAG_IPV4;` **之前**

- [ ] **Step 5: 实现方法**

在 `src/Utils/Tools.php` 的 `genSs2022UserPk()` 方法结束(`return base64_encode($pk);` 后的 `}`)之后、`toDateTime()` 之前插入:

```php
    /**
     * 由 REALITY 的 X25519 私钥推导公钥
     *
     * 等价于 `xray x25519` 的公钥输出:X25519 基点标量乘。
     * 入参容忍 base64url / 标准 base64、有无 padding。
     * 解码失败或长度不足 32 字节时返回空字符串。
     */
    public static function genRealityPublicKey(string $private_key): string
    {
        $raw = base64_decode(strtr(trim($private_key), '-_', '+/'), true);

        if ($raw === false || strlen($raw) !== 32) {
            return '';
        }

        return rtrim(strtr(base64_encode(sodium_crypto_scalarmult_base($raw)), '+/', '-_'), '=');
    }
```

- [ ] **Step 6: 运行测试确认通过**

Run: `./vendor/bin/pest --testsuite=Unit --filter='genRealityPublicKey'`
Expected: PASS,9 个测试全绿

- [ ] **Step 7: 跑全量 Unit 确认无回归**

Run: `./vendor/bin/pest --testsuite=Unit`
Expected: 0 个 FAIL。**注意:全量套件的退出码本来就是 1**,因为 `phpunit.xml` 设了 `failOnWarning="true"`,而 `tests/Unit/Utils/CookieTest.php` 有 2 个既有 warning(`Cannot modify header information - headers already sent`)。验收标准是没有新的 FAIL、也没有新的 warning,而不是退出码为 0。另外 pest 的汇总行在输出重定向时可能丢失,必要时用 `grep -cE '^\s+✓'` 数通过块。

- [ ] **Step 8: 提交**

```bash
git add src/Utils/Tools.php composer.json tests/Unit/Utils/ToolsTest.php
git commit -F - <<'EOF'
feat(utils): 新增 REALITY X25519 公钥推导 genRealityPublicKey

由 reality-opts.private_key 推导客户端订阅所需的 pbk,等价于
`xray x25519` 的公钥输出。入参容忍 base64url/标准 base64、有无
padding;解码失败或非 32 字节返回空串,由调用方决定后果。

测试用 RFC 7748 §6.1 定值向量,外加一组 base64url 字母表含 -/_
的向量以覆盖字母表转换。composer.json 补声明 ext-sodium。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01J5wYZFANWzAFfoQ3q8s8QN
EOF
```

---

### Task 2: 节点类型枚举与管理端下拉框

**Files:**
- Modify: `src/Models/Node.php:81-93`
- Modify: `resources/views/cafe/admin/node/create.tpl:51-58`
- Modify: `resources/views/cafe/admin/node/edit.tpl:61-68`
- Test: `tests/Unit/Models/NodeSortTest.php`(新建)

**Interfaces:**
- Consumes: 无
- Produces: `sort = 12` 约定为 VLESS 节点。任务 3、4、5 依赖此数值。

- [ ] **Step 1: 写失败的测试**

新建 `tests/Unit/Models/NodeSortTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Node;

function nodeSortLabel(int $sort): string
{
    $node = (new ReflectionClass(Node::class))->newInstanceWithoutConstructor();
    $node->sort = $sort;

    return $node->sort();
}

describe('Node::sort', function () {
    it('maps 12 to VLESS', function () {
        expect(nodeSortLabel(12))->toBe('VLESS');
    });

    it('keeps the existing protocol labels intact', function () {
        expect(nodeSortLabel(0))->toBe('Shadowsocks')
            ->and(nodeSortLabel(1))->toBe('Shadowsocks2022')
            ->and(nodeSortLabel(2))->toBe('TUIC')
            ->and(nodeSortLabel(3))->toBe('WireGuard')
            ->and(nodeSortLabel(11))->toBe('Vmess')
            ->and(nodeSortLabel(14))->toBe('Trojan')
            ->and(nodeSortLabel(15))->toBe('Hysteria2');
    });

    it('falls back to 未知 for unmapped values', function () {
        expect(nodeSortLabel(99))->toBe('未知');
    });
});
```

- [ ] **Step 2: 运行测试确认失败**

Run: `./vendor/bin/pest --testsuite=Unit --filter='Node::sort'`
Expected: FAIL,`maps 12 to VLESS` 断言失败,实际得到 `未知`

- [ ] **Step 3: 修改 Node 模型**

`src/Models/Node.php` 的 `sort()` 方法,在 `11 => 'Vmess',` 之后插入一行:

```php
    public function sort(): string
    {
        return match ($this->sort) {
            0 => 'Shadowsocks',
            1 => 'Shadowsocks2022',
            2 => 'TUIC',
            3 => 'WireGuard',
            11 => 'Vmess',
            12 => 'VLESS',
            14 => 'Trojan',
            15 => 'Hysteria2',
            default => '未知',
        };
    }
```

- [ ] **Step 4: 运行测试确认通过**

Run: `./vendor/bin/pest --testsuite=Unit --filter='Node::sort'`
Expected: PASS,3 个测试全绿

- [ ] **Step 5: 修改新建节点模板**

`resources/views/cafe/admin/node/create.tpl`,在 `<option value="14">Trojan</option>` 与 `<option value="11">Vmess</option>` 之间插入一行(下拉框按数值倒序排列):

```html
            <select id="sort" class="field-input">
                <option value="15">Hysteria2</option>
                <option value="14">Trojan</option>
                <option value="12">VLESS</option>
                <option value="11">Vmess</option>
                <option value="2">TUIC</option>
                <option value="1">Shadowsocks2022</option>
                <option value="0">Shadowsocks</option>
            </select>
```

- [ ] **Step 6: 修改编辑节点模板**

`resources/views/cafe/admin/node/edit.tpl`,同一位置插入,注意带 selected 判定:

```html
            <select id="sort" class="field-input">
                <option value="15" {if $node->sort === 15}selected{/if}>Hysteria2</option>
                <option value="14" {if $node->sort === 14}selected{/if}>Trojan</option>
                <option value="12" {if $node->sort === 12}selected{/if}>VLESS</option>
                <option value="11" {if $node->sort === 11}selected{/if}>Vmess</option>
                <option value="2" {if $node->sort === 2}selected{/if}>TUIC</option>
                <option value="1" {if $node->sort === 1}selected{/if}>Shadowsocks2022</option>
                <option value="0" {if $node->sort === 0}selected{/if}>Shadowsocks</option>
            </select>
```

- [ ] **Step 7: 跑全量 Unit 确认无回归**

Run: `./vendor/bin/pest --testsuite=Unit`
Expected: 0 个 FAIL。**注意:全量套件的退出码本来就是 1**,因为 `phpunit.xml` 设了 `failOnWarning="true"`,而 `tests/Unit/Utils/CookieTest.php` 有 2 个既有 warning(`Cannot modify header information - headers already sent`,源自 `vendor/composer/ClassLoader.php`)。这在本计划的第一个提交之前就存在(已在 `68947d67` 上复现)。验收标准是:**没有新的 FAIL,也没有新的 warning**,而不是退出码为 0。

- [ ] **Step 8: 提交**

```bash
git add src/Models/Node.php resources/views/cafe/admin/node/create.tpl resources/views/cafe/admin/node/edit.tpl tests/Unit/Models/NodeSortTest.php
git commit -F - <<'EOF'
feat(node): 新增 sort=12 VLESS 节点类型

Node::sort() 枚举与管理端新建/编辑页的接入类型下拉框同步加入 VLESS。
nodes.sort 已是 int 列,无需迁移。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01J5wYZFANWzAFfoQ3q8s8QN
EOF
```

---

### Task 3: WebAPI 向 XrayR 注入开关

**Files:**
- Modify: `src/Controllers/WebAPI/NodeController.php:13-14`(导入)、`:35-42`(`$data` 构造)
- Test: `tests/Unit/Controllers/NodeXrayrCompatTest.php`(新建)

**Interfaces:**
- Consumes: `sort = 12`(任务 2)
- Produces: `App\Controllers\WebAPI\NodeController::applyXrayrCompat(array $custom_config, int $sort): array` —— private 方法。`sort === 12` 时写入 `enable_vless = '1'`;若同时 `security === 'reality'` 则写入 `enable_reality = true`。其余 sort 原样返回。

**说明:** 把注入逻辑抽成独立方法而不是内联进 `getInfo()`,是为了能在不连数据库的前提下测试(`getInfo()` 需要 `Node::find()`)。

- [ ] **Step 1: 写失败的测试**

新建 `tests/Unit/Controllers/NodeXrayrCompatTest.php`:

```php
<?php

declare(strict_types=1);

use App\Controllers\WebAPI\NodeController;

function applyXrayrCompat(array $custom_config, int $sort): array
{
    $controller = (new ReflectionClass(NodeController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(NodeController::class, 'applyXrayrCompat');

    return $method->invoke($controller, $custom_config, $sort);
}

describe('NodeController::applyXrayrCompat', function () {
    it('injects enable_vless as the string "1" for sort=12', function () {
        $out = applyXrayrCompat(['offset_port_node' => '443'], 12);

        expect($out['enable_vless'])->toBe('1');
    });

    it('injects enable_reality as a real boolean when security is reality', function () {
        $out = applyXrayrCompat(['security' => 'reality'], 12);

        expect($out['enable_reality'])->toBeTrue();
    });

    it('omits enable_reality when security is not reality', function () {
        $out = applyXrayrCompat(['security' => 'tls'], 12);

        expect($out)
            ->toHaveKey('enable_vless')
            ->not->toHaveKey('enable_reality');
    });

    it('omits enable_reality when security is absent', function () {
        $out = applyXrayrCompat([], 12);

        expect($out)->not->toHaveKey('enable_reality');
    });

    it('preserves the admin-authored keys untouched', function () {
        $in = [
            'offset_port_node' => '443',
            'network' => 'tcp',
            'security' => 'reality',
            'flow' => 'xtls-rprx-vision',
            'reality-opts' => [
                'dest' => 'www.cloudflare.com:443',
                'server_names' => ['www.cloudflare.com'],
                'private_key' => 'dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo',
                'short_ids' => ['0123abcd'],
            ],
        ];

        $out = applyXrayrCompat($in, 12);

        expect($out['reality-opts'])->toBe($in['reality-opts'])
            ->and($out['flow'])->toBe('xtls-rprx-vision')
            ->and($out['network'])->toBe('tcp');
    });

    it('leaves other node types untouched', function () {
        foreach ([0, 1, 2, 3, 11, 14, 15] as $sort) {
            expect(applyXrayrCompat(['security' => 'reality'], $sort))
                ->toBe(['security' => 'reality']);
        }
    });
});
```

- [ ] **Step 2: 运行测试确认失败**

Run: `./vendor/bin/pest --testsuite=Unit --filter='applyXrayrCompat'`
Expected: FAIL,报 `ReflectionException: Method App\Controllers\WebAPI\NodeController::applyXrayrCompat() does not exist`

- [ ] **Step 3: 修改 getInfo 并新增方法**

`src/Controllers/WebAPI/NodeController.php`,把 `$data` 的构造改为先解码再注入:

```php
        $custom_config = json_decode((string) $node->custom_config, true) ?? [];

        $data = [
            'node_speedlimit' => $node->node_speedlimit,
            'sort' => $node->sort,
            'server' => $node->server,
            'custom_config' => $this->applyXrayrCompat($custom_config, (int) $node->sort),
            'type' => $_ENV['appName'],
            'version' => $this->convertVersionFormat(VERSION),
        ];
```

原第 39 行的 `json_decode($node->custom_config, true, JSON_UNESCAPED_SLASHES)` 把 `JSON_UNESCAPED_SLASHES`(值 64)误当作 `$depth` 传入、`$flags` 位留空,一并修正。

随后删掉不再使用的导入 `use const JSON_UNESCAPED_SLASHES;`(第 14 行)。`use function json_decode;`(第 13 行)保留。

在 `getInfo()` 方法之后、`convertVersionFormat()` 的文档注释之前,插入:

```php
    /**
     * 为 XrayR 补齐由面板 sort 推导出的开关
     *
     * XrayR 不读面板下发的 sort(api/sspanel/sspanel.go 中 nodeInfoResponse.Sort
     * 从未被引用),协议由其自身 config.yml 的 NodeType 决定。VLESS 入站的开关
     * 是 custom_config.enable_vless,且判等的是字符串 "1"。
     *
     * 由面板注入而非要求 admin 手写,可避免 sort 与 enable_vless 两处声明不一致。
     */
    private function applyXrayrCompat(array $custom_config, int $sort): array
    {
        if ($sort !== 12) {
            return $custom_config;
        }

        $custom_config['enable_vless'] = '1';

        if (($custom_config['security'] ?? '') === 'reality') {
            $custom_config['enable_reality'] = true;
        }

        return $custom_config;
    }
```

- [ ] **Step 4: 运行测试确认通过**

Run: `./vendor/bin/pest --testsuite=Unit --filter='applyXrayrCompat'`
Expected: PASS,6 个测试全绿

- [ ] **Step 5: 跑全量 Unit 确认无回归**

Run: `./vendor/bin/pest --testsuite=Unit`
Expected: 0 个 FAIL。**注意:全量套件的退出码本来就是 1**,因为 `phpunit.xml` 设了 `failOnWarning="true"`,而 `tests/Unit/Utils/CookieTest.php` 有 2 个既有 warning(`Cannot modify header information - headers already sent`,源自 `vendor/composer/ClassLoader.php`)。这在本计划的第一个提交之前就存在(已在 `68947d67` 上复现)。验收标准是:**没有新的 FAIL,也没有新的 warning**,而不是退出码为 0。

- [ ] **Step 6: 提交**

```bash
git add src/Controllers/WebAPI/NodeController.php tests/Unit/Controllers/NodeXrayrCompatTest.php
git commit -F - <<'EOF'
feat(webapi): sort=12 时向 XrayR 注入 enable_vless/enable_reality

XrayR 不读面板 sort,VLESS 入站靠 custom_config.enable_vless=="1"
开启,REALITY 靠 enable_reality。由面板按 sort/security 推导注入,
admin 只需在 custom_config 写一遍 security,不必重复声明。

顺带修正 json_decode 的参数错位:原先把 JSON_UNESCAPED_SLASHES
当作 $depth 传入,$flags 位留空。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01J5wYZFANWzAFfoQ3q8s8QN
EOF
```

---

### Task 4: Clash(mihomo)订阅生成

**Files:**
- Modify: `src/Services/Subscribe/Clash.php`(`case 11` 的 `break;` 之后、`case 15:` 之前插入 `case 12`;`buildVlessNode()` 加在 `getContent()` 方法之后、类的收尾 `}` 之前)
- Test: `tests/Unit/Services/Subscribe/ClashVlessTest.php`(新建)

**Interfaces:**
- Consumes: `App\Utils\Tools::genRealityPublicKey(string): string`(任务 1);`sort = 12`(任务 2)
- Produces: `App\Services\Subscribe\Clash::buildVlessNode($user, $node_raw, array $custom): array` —— private 方法,返回单个 mihomo proxy 数组;无法生成可用配置时返回空数组 `[]`(调用方的 `if ($node === []) { continue; }` 会跳过该节点)。

`use App\Utils\Tools;` 在 `Clash.php:8` 已存在,无需新增导入。

- [ ] **Step 1: 写失败的测试**

新建 `tests/Unit/Services/Subscribe/ClashVlessTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Subscribe\Clash;

function buildClashVlessNode(array $custom): array
{
    $clash = (new ReflectionClass(Clash::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(Clash::class, 'buildVlessNode');

    $user = new stdClass();
    $user->uuid = 'a1b2c3d4-0000-4000-8000-000000000000';

    $node_raw = new stdClass();
    $node_raw->name = 'HK-01';
    $node_raw->server = 'a.example.com';

    return $method->invoke($clash, $user, $node_raw, $custom);
}

function clashRealityCustom(array $overrides = [], array $reality_overrides = []): array
{
    return array_merge([
        'offset_port_node' => '443',
        'network' => 'tcp',
        'security' => 'reality',
        'flow' => 'xtls-rprx-vision',
        'reality-opts' => array_merge([
            'dest' => 'www.cloudflare.com:443',
            'server_names' => ['www.cloudflare.com'],
            'private_key' => 'dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo',
            'short_ids' => ['0123abcd'],
        ], $reality_overrides),
    ], $overrides);
}

describe('Clash buildVlessNode', function () {
    it('builds a full REALITY + Vision node', function () {
        $node = buildClashVlessNode(clashRealityCustom());

        expect($node)->toMatchArray([
            'name' => 'HK-01',
            'type' => 'vless',
            'server' => 'a.example.com',
            'port' => 443,
            'uuid' => 'a1b2c3d4-0000-4000-8000-000000000000',
            'udp' => true,
            'tls' => true,
            'skip-cert-verify' => false,
            'servername' => 'www.cloudflare.com',
            'network' => 'tcp',
            'client-fingerprint' => 'chrome',
            'flow' => 'xtls-rprx-vision',
        ]);

        expect($node['reality-opts'])->toBe([
            'public-key' => 'hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo',
            'short-id' => '0123abcd',
        ]);
    });

    it('drops flow when the transport is not raw tcp', function () {
        $node = buildClashVlessNode(clashRealityCustom(['network' => 'ws']));

        expect($node)
            ->not->toHaveKey('flow')
            ->and($node['network'])->toBe('ws')
            ->and($node['reality-opts']['public-key'])
                ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('maps httpupgrade to ws and drops flow along with it', function () {
        $node = buildClashVlessNode(clashRealityCustom(['network' => 'httpupgrade']));

        expect($node['network'])->toBe('ws');
        expect($node)->not->toHaveKey('flow');
    });

    it('casts a numeric short_id to a string', function () {
        $node = buildClashVlessNode(clashRealityCustom([], ['short_ids' => [1234]]));

        expect($node['reality-opts']['short-id'])->toBe('1234');
    });

    it('takes servername from the first server_names entry', function () {
        $node = buildClashVlessNode(clashRealityCustom(
            ['host' => 'ignored.example.com'],
            ['server_names' => ['first.example.com', 'second.example.com']],
        ));

        expect($node['servername'])->toBe('first.example.com');
    });

    it('falls back to host for servername when server_names is missing', function () {
        $custom = clashRealityCustom(['host' => 'fallback.example.com']);
        unset($custom['reality-opts']['server_names']);

        $node = buildClashVlessNode($custom);

        expect($node['servername'])->toBe('fallback.example.com');
    });

    it('takes short-id from the first short_ids entry', function () {
        $node = buildClashVlessNode(clashRealityCustom([], ['short_ids' => ['aaaa', 'bbbb']]));

        expect($node['reality-opts']['short-id'])->toBe('aaaa');
    });

    it('emits an empty short-id when short_ids is absent', function () {
        $custom = clashRealityCustom();
        unset($custom['reality-opts']['short_ids']);

        $node = buildClashVlessNode($custom);

        expect($node['reality-opts']['short-id'])->toBe('');
    });

    it('skips the node when the reality private key is missing', function () {
        $custom = clashRealityCustom();
        unset($custom['reality-opts']['private_key']);

        expect(buildClashVlessNode($custom))->toBe([]);
    });

    it('skips the node when the reality private key is malformed', function () {
        $node = buildClashVlessNode(clashRealityCustom([], ['private_key' => 'not!!base64']));

        expect($node)->toBe([]);
    });

    it('falls back to an explicit public_key when the private key cannot be derived', function () {
        $node = buildClashVlessNode(clashRealityCustom([], [
            'private_key' => '',
            'public_key' => 'gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0',
        ]));

        expect($node['reality-opts']['public-key'])
            ->toBe('gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0');
    });

    it('prefers the derived key over an explicit public_key', function () {
        $node = buildClashVlessNode(clashRealityCustom([], [
            'public_key' => 'staleStaleStaleStaleStaleStaleStaleStaleSta',
        ]));

        expect($node['reality-opts']['public-key'])
            ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('omits reality-opts and uses host when security is tls', function () {
        $node = buildClashVlessNode([
            'offset_port_node' => '443',
            'network' => 'ws',
            'security' => 'tls',
            'host' => 'cdn.example.com',
        ]);

        expect($node)
            ->not->toHaveKey('reality-opts')
            ->and($node['tls'])->toBeTrue()
            ->and($node['servername'])->toBe('cdn.example.com');
    });

    it('sets tls false when security is none', function () {
        $node = buildClashVlessNode([
            'offset_port_node' => '443',
            'network' => 'tcp',
            'security' => 'none',
        ]);

        expect($node['tls'])->toBeFalse();
    });

    it('sets tls true when security is xtls', function () {
        $node = buildClashVlessNode(['security' => 'xtls']);

        expect($node['tls'])->toBeTrue();
    });

    it('prefers offset_port_user over offset_port_node', function () {
        $node = buildClashVlessNode(clashRealityCustom([
            'offset_port_user' => 8443,
            'offset_port_node' => 9443,
        ]));

        expect($node['port'])->toBe(8443);
    });

    it('defaults the port to 443 when neither offset is set', function () {
        $custom = clashRealityCustom();
        unset($custom['offset_port_node']);

        expect(buildClashVlessNode($custom)['port'])->toBe(443);
    });

    it('defaults client-fingerprint to chrome and honors an override', function () {
        expect(buildClashVlessNode(clashRealityCustom())['client-fingerprint'])->toBe('chrome');
        expect(buildClashVlessNode(clashRealityCustom(['fingerprint' => 'safari']))['client-fingerprint'])
            ->toBe('safari');
    });

    it('honors allow_insecure', function () {
        $node = buildClashVlessNode(clashRealityCustom(['allow_insecure' => true]));

        expect($node['skip-cert-verify'])->toBeTrue();
    });

    it('passes through ws-opts', function () {
        $node = buildClashVlessNode([
            'network' => 'ws',
            'security' => 'tls',
            'ws-opts' => ['path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']],
        ]);

        expect($node['ws-opts'])->toBe(['path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']]);
    });

    it('defaults network to tcp when unset', function () {
        expect(buildClashVlessNode(['security' => 'none'])['network'])->toBe('tcp');
    });
});
```

- [ ] **Step 2: 运行测试确认失败**

Run: `./vendor/bin/pest --testsuite=Unit --filter='Clash buildVlessNode'`
Expected: FAIL,报 `ReflectionException: Method App\Services\Subscribe\Clash::buildVlessNode() does not exist`

- [ ] **Step 3: 实现 buildVlessNode**

在 `src/Services/Subscribe/Clash.php` 的 `getContent()` 方法结束(`return yaml_emit(...)` 后的 `}`)之后、类收尾的 `}` 之前插入:

```php
    /**
     * VLESS(sort=12)节点转 mihomo / Clash.Meta proxy 配置
     *
     * REALITY 的公钥由 reality-opts.private_key 推导;后端只需私钥,
     * 客户端只需公钥,故面板不要求 admin 两处各写一遍。
     * 无法得到公钥时返回空数组,由调用方跳过该节点 —— 缺 pbk 的
     * REALITY 配置无法握手,不存在可降级的目标。
     */
    private function buildVlessNode($user, $node_raw, array $custom): array
    {
        $vless_port = $custom['offset_port_user'] ?? ($custom['offset_port_node'] ?? 443);
        $security = $custom['security'] ?? 'none';
        $network = $custom['network'] ?? 'tcp';
        $host = $custom['header']['request']['headers']['Host'][0] ?? $custom['host'] ?? '';
        $allow_insecure = $custom['allow_insecure'] ?? false;
        $flow = (string) ($custom['flow'] ?? '');
        $fingerprint = $custom['fingerprint'] ?? 'chrome';
        $reality = $custom['reality-opts'] ?? $custom['reality_opts'] ?? [];
        // Clash 特定配置
        $udp = $custom['udp'] ?? true;
        $ws_opts = $custom['ws-opts'] ?? $custom['ws_opts'] ?? null;
        $h2_opts = $custom['h2-opts'] ?? $custom['h2_opts'] ?? null;
        $grpc_opts = $custom['grpc-opts'] ?? $custom['grpc_opts'] ?? null;
        // HTTPUpgrade 在 Clash.Meta 内核中属于 ws 类型
        if ($network === 'httpupgrade') {
            $network = 'ws';
        }

        $is_reality = $security === 'reality';

        // XTLS Vision 仅支持裸 TCP，其余传输层丢弃 flow
        if ($network !== 'tcp') {
            $flow = '';
        }

        $node = [
            'name' => $node_raw->name,
            'type' => 'vless',
            'server' => $node_raw->server,
            'port' => (int) $vless_port,
            'uuid' => $user->uuid,
            'udp' => (bool) $udp,
            'tls' => $is_reality || $security === 'tls' || $security === 'xtls',
            'skip-cert-verify' => (bool) $allow_insecure,
            'servername' => $host,
            'network' => $network,
            'client-fingerprint' => $fingerprint,
            'ws-opts' => $ws_opts,
            'h2-opts' => $h2_opts,
            'grpc-opts' => $grpc_opts,
        ];

        if ($is_reality) {
            $public_key = Tools::genRealityPublicKey((string) ($reality['private_key'] ?? ''));

            if ($public_key === '') {
                $public_key = (string) ($reality['public_key'] ?? '');
            }

            if ($public_key === '') {
                return [];
            }

            $node['servername'] = $reality['server_names'][0] ?? $host;
            $node['reality-opts'] = [
                'public-key' => $public_key,
                'short-id' => (string) ($reality['short_ids'][0] ?? ''),
            ];
        }

        if ($flow !== '') {
            $node['flow'] = $flow;
        }

        return $node;
    }
```

- [ ] **Step 4: 接入 switch**

在 `src/Services/Subscribe/Clash.php` 的 `case 11:` 分支的 `break;` 之后、`case 15:` 之前插入:

```php
                case 12:
                    $node = $this->buildVlessNode($user, $node_raw, $node_custom_config ?? []);

                    break;
```

`?? []` 是必需的:第 25 行的 `json_decode()` 在 `custom_config` 为非法 JSON 时返回 `null`,而 `buildVlessNode()` 的参数类型为 `array`。

- [ ] **Step 5: 运行测试确认通过**

Run: `./vendor/bin/pest --testsuite=Unit --filter='Clash buildVlessNode'`
Expected: PASS,21 个测试全绿

- [ ] **Step 6: 跑全量 Unit 确认无回归**

Run: `./vendor/bin/pest --testsuite=Unit`
Expected: 0 个 FAIL。**注意:全量套件的退出码本来就是 1**,因为 `phpunit.xml` 设了 `failOnWarning="true"`,而 `tests/Unit/Utils/CookieTest.php` 有 2 个既有 warning(`Cannot modify header information - headers already sent`,源自 `vendor/composer/ClassLoader.php`)。这在本计划的第一个提交之前就存在(已在 `68947d67` 上复现)。验收标准是:**没有新的 FAIL,也没有新的 warning**,而不是退出码为 0。

- [ ] **Step 7: 提交**

```bash
git add src/Services/Subscribe/Clash.php tests/Unit/Services/Subscribe/ClashVlessTest.php
git commit -F - <<'EOF'
feat(subscribe): Clash 订阅下发 VLESS 节点(sort=12)

新增 buildVlessNode() 生成 mihomo/Clash.Meta 的 vless proxy:
- reality-opts.public-key 由 private_key 经 X25519 推导
- server_names/short_ids 数组取首项映射为单值 servername/short-id
- flow 仅在 network=tcp 时输出，Vision 配非裸 TCP 时静默丢弃
- 拿不到 REALITY 公钥则返回空数组，由调用方跳过该节点

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01J5wYZFANWzAFfoQ3q8s8QN
EOF
```

---

### Task 5: Stash 订阅生成

**Files:**
- Modify: `src/Services/Subscribe/Stash.php`(`case 11` 的 `break;` 之后、`case 15:` 之前插入 `case 12`;`buildVlessNode()` 加在 `getContent()` 方法之后、类的收尾 `}` 之前)
- Test: `tests/Unit/Services/Subscribe/StashVlessTest.php`(新建)

**Interfaces:**
- Consumes: `App\Utils\Tools::genRealityPublicKey(string): string`(任务 1);`sort = 12`(任务 2)
- Produces: `App\Services\Subscribe\Stash::buildVlessNode($user, $node_raw, array $custom): array` —— 语义与 Clash 版本完全一致。

Stash 的配置格式与 Clash 兼容(两文件现有的 vmess 分支逐字段相同),故输出结构一致;各自实现以匹配所在文件的既有风格。`use App\Utils\Tools;` 在 `Stash.php:8` 已存在。

- [ ] **Step 1: 写失败的测试**

新建 `tests/Unit/Services/Subscribe/StashVlessTest.php`。内容与 `ClashVlessTest.php` 同构,但有四处必须改:类名 `Clash` → `Stash`、辅助函数 `buildClashVlessNode` → `buildStashVlessNode`、`clashRealityCustom` → `stashRealityCustom`、`describe()` 标题 `'Clash buildVlessNode'` → `'Stash buildVlessNode'`。Pest 在同一进程加载全部测试文件,全局辅助函数名重复会导致 `Cannot redeclare function` 致命错误。

```php
<?php

declare(strict_types=1);

use App\Services\Subscribe\Stash;

function buildStashVlessNode(array $custom): array
{
    $stash = (new ReflectionClass(Stash::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(Stash::class, 'buildVlessNode');

    $user = new stdClass();
    $user->uuid = 'a1b2c3d4-0000-4000-8000-000000000000';

    $node_raw = new stdClass();
    $node_raw->name = 'HK-01';
    $node_raw->server = 'a.example.com';

    return $method->invoke($stash, $user, $node_raw, $custom);
}

function stashRealityCustom(array $overrides = [], array $reality_overrides = []): array
{
    return array_merge([
        'offset_port_node' => '443',
        'network' => 'tcp',
        'security' => 'reality',
        'flow' => 'xtls-rprx-vision',
        'reality-opts' => array_merge([
            'dest' => 'www.cloudflare.com:443',
            'server_names' => ['www.cloudflare.com'],
            'private_key' => 'dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo',
            'short_ids' => ['0123abcd'],
        ], $reality_overrides),
    ], $overrides);
}

describe('Stash buildVlessNode', function () {
    it('builds a full REALITY + Vision node', function () {
        $node = buildStashVlessNode(stashRealityCustom());

        expect($node)->toMatchArray([
            'name' => 'HK-01',
            'type' => 'vless',
            'server' => 'a.example.com',
            'port' => 443,
            'uuid' => 'a1b2c3d4-0000-4000-8000-000000000000',
            'udp' => true,
            'tls' => true,
            'skip-cert-verify' => false,
            'servername' => 'www.cloudflare.com',
            'network' => 'tcp',
            'client-fingerprint' => 'chrome',
            'flow' => 'xtls-rprx-vision',
        ]);

        expect($node['reality-opts'])->toBe([
            'public-key' => 'hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo',
            'short-id' => '0123abcd',
        ]);
    });

    it('drops flow when the transport is not raw tcp', function () {
        $node = buildStashVlessNode(stashRealityCustom(['network' => 'ws']));

        expect($node)
            ->not->toHaveKey('flow')
            ->and($node['network'])->toBe('ws')
            ->and($node['reality-opts']['public-key'])
                ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('maps httpupgrade to ws and drops flow along with it', function () {
        $node = buildStashVlessNode(stashRealityCustom(['network' => 'httpupgrade']));

        expect($node['network'])->toBe('ws');
        expect($node)->not->toHaveKey('flow');
    });

    it('casts a numeric short_id to a string', function () {
        $node = buildStashVlessNode(stashRealityCustom([], ['short_ids' => [1234]]));

        expect($node['reality-opts']['short-id'])->toBe('1234');
    });

    it('takes servername from the first server_names entry', function () {
        $node = buildStashVlessNode(stashRealityCustom(
            ['host' => 'ignored.example.com'],
            ['server_names' => ['first.example.com', 'second.example.com']],
        ));

        expect($node['servername'])->toBe('first.example.com');
    });

    it('falls back to host for servername when server_names is missing', function () {
        $custom = stashRealityCustom(['host' => 'fallback.example.com']);
        unset($custom['reality-opts']['server_names']);

        $node = buildStashVlessNode($custom);

        expect($node['servername'])->toBe('fallback.example.com');
    });

    it('takes short-id from the first short_ids entry', function () {
        $node = buildStashVlessNode(stashRealityCustom([], ['short_ids' => ['aaaa', 'bbbb']]));

        expect($node['reality-opts']['short-id'])->toBe('aaaa');
    });

    it('emits an empty short-id when short_ids is absent', function () {
        $custom = stashRealityCustom();
        unset($custom['reality-opts']['short_ids']);

        $node = buildStashVlessNode($custom);

        expect($node['reality-opts']['short-id'])->toBe('');
    });

    it('skips the node when the reality private key is missing', function () {
        $custom = stashRealityCustom();
        unset($custom['reality-opts']['private_key']);

        expect(buildStashVlessNode($custom))->toBe([]);
    });

    it('skips the node when the reality private key is malformed', function () {
        $node = buildStashVlessNode(stashRealityCustom([], ['private_key' => 'not!!base64']));

        expect($node)->toBe([]);
    });

    it('falls back to an explicit public_key when the private key cannot be derived', function () {
        $node = buildStashVlessNode(stashRealityCustom([], [
            'private_key' => '',
            'public_key' => 'gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0',
        ]));

        expect($node['reality-opts']['public-key'])
            ->toBe('gNJVDckF8AzNDP9bD0BQUJAneaGBeZmXpud1M8PrIn0');
    });

    it('prefers the derived key over an explicit public_key', function () {
        $node = buildStashVlessNode(stashRealityCustom([], [
            'public_key' => 'staleStaleStaleStaleStaleStaleStaleStaleSta',
        ]));

        expect($node['reality-opts']['public-key'])
            ->toBe('hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo');
    });

    it('omits reality-opts and uses host when security is tls', function () {
        $node = buildStashVlessNode([
            'offset_port_node' => '443',
            'network' => 'ws',
            'security' => 'tls',
            'host' => 'cdn.example.com',
        ]);

        expect($node)
            ->not->toHaveKey('reality-opts')
            ->and($node['tls'])->toBeTrue()
            ->and($node['servername'])->toBe('cdn.example.com');
    });

    it('sets tls false when security is none', function () {
        $node = buildStashVlessNode([
            'offset_port_node' => '443',
            'network' => 'tcp',
            'security' => 'none',
        ]);

        expect($node['tls'])->toBeFalse();
    });

    it('sets tls true when security is xtls', function () {
        $node = buildStashVlessNode(['security' => 'xtls']);

        expect($node['tls'])->toBeTrue();
    });

    it('prefers offset_port_user over offset_port_node', function () {
        $node = buildStashVlessNode(stashRealityCustom([
            'offset_port_user' => 8443,
            'offset_port_node' => 9443,
        ]));

        expect($node['port'])->toBe(8443);
    });

    it('defaults the port to 443 when neither offset is set', function () {
        $custom = stashRealityCustom();
        unset($custom['offset_port_node']);

        expect(buildStashVlessNode($custom)['port'])->toBe(443);
    });

    it('defaults client-fingerprint to chrome and honors an override', function () {
        expect(buildStashVlessNode(stashRealityCustom())['client-fingerprint'])->toBe('chrome');
        expect(buildStashVlessNode(stashRealityCustom(['fingerprint' => 'safari']))['client-fingerprint'])
            ->toBe('safari');
    });

    it('honors allow_insecure', function () {
        $node = buildStashVlessNode(stashRealityCustom(['allow_insecure' => true]));

        expect($node['skip-cert-verify'])->toBeTrue();
    });

    it('passes through ws-opts', function () {
        $node = buildStashVlessNode([
            'network' => 'ws',
            'security' => 'tls',
            'ws-opts' => ['path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']],
        ]);

        expect($node['ws-opts'])->toBe(['path' => '/ws', 'headers' => ['Host' => 'cdn.example.com']]);
    });

    it('defaults network to tcp when unset', function () {
        expect(buildStashVlessNode(['security' => 'none'])['network'])->toBe('tcp');
    });
});
```

- [ ] **Step 2: 运行测试确认失败**

Run: `./vendor/bin/pest --testsuite=Unit --filter='Stash buildVlessNode'`
Expected: FAIL,报 `ReflectionException: Method App\Services\Subscribe\Stash::buildVlessNode() does not exist`

- [ ] **Step 3: 实现 buildVlessNode**

在 `src/Services/Subscribe/Stash.php` 的 `getContent()` 方法结束之后、类收尾的 `}` 之前插入(与 Clash 版本逐行相同,仅注释里的内核名改为 Stash):

```php
    /**
     * VLESS(sort=12)节点转 Stash proxy 配置
     *
     * REALITY 的公钥由 reality-opts.private_key 推导;后端只需私钥,
     * 客户端只需公钥,故面板不要求 admin 两处各写一遍。
     * 无法得到公钥时返回空数组,由调用方跳过该节点 —— 缺 pbk 的
     * REALITY 配置无法握手,不存在可降级的目标。
     */
    private function buildVlessNode($user, $node_raw, array $custom): array
    {
        $vless_port = $custom['offset_port_user'] ?? ($custom['offset_port_node'] ?? 443);
        $security = $custom['security'] ?? 'none';
        $network = $custom['network'] ?? 'tcp';
        $host = $custom['header']['request']['headers']['Host'][0] ?? $custom['host'] ?? '';
        $allow_insecure = $custom['allow_insecure'] ?? false;
        $flow = (string) ($custom['flow'] ?? '');
        $fingerprint = $custom['fingerprint'] ?? 'chrome';
        $reality = $custom['reality-opts'] ?? $custom['reality_opts'] ?? [];
        // Stash 特定配置
        $udp = $custom['udp'] ?? true;
        $ws_opts = $custom['ws-opts'] ?? $custom['ws_opts'] ?? null;
        $h2_opts = $custom['h2-opts'] ?? $custom['h2_opts'] ?? null;
        $grpc_opts = $custom['grpc-opts'] ?? $custom['grpc_opts'] ?? null;
        // HTTPUpgrade 在 Stash 中属于 ws 类型
        if ($network === 'httpupgrade') {
            $network = 'ws';
        }

        $is_reality = $security === 'reality';

        // XTLS Vision 仅支持裸 TCP，其余传输层丢弃 flow
        if ($network !== 'tcp') {
            $flow = '';
        }

        $node = [
            'name' => $node_raw->name,
            'type' => 'vless',
            'server' => $node_raw->server,
            'port' => (int) $vless_port,
            'uuid' => $user->uuid,
            'udp' => (bool) $udp,
            'tls' => $is_reality || $security === 'tls' || $security === 'xtls',
            'skip-cert-verify' => (bool) $allow_insecure,
            'servername' => $host,
            'network' => $network,
            'client-fingerprint' => $fingerprint,
            'ws-opts' => $ws_opts,
            'h2-opts' => $h2_opts,
            'grpc-opts' => $grpc_opts,
        ];

        if ($is_reality) {
            $public_key = Tools::genRealityPublicKey((string) ($reality['private_key'] ?? ''));

            if ($public_key === '') {
                $public_key = (string) ($reality['public_key'] ?? '');
            }

            if ($public_key === '') {
                return [];
            }

            $node['servername'] = $reality['server_names'][0] ?? $host;
            $node['reality-opts'] = [
                'public-key' => $public_key,
                'short-id' => (string) ($reality['short_ids'][0] ?? ''),
            ];
        }

        if ($flow !== '') {
            $node['flow'] = $flow;
        }

        return $node;
    }
```

- [ ] **Step 4: 接入 switch**

在 `src/Services/Subscribe/Stash.php` 的 `case 11:` 分支的 `break;` 之后、`case 15:` 之前插入:

```php
                case 12:
                    $node = $this->buildVlessNode($user, $node_raw, $node_custom_config ?? []);

                    break;
```

- [ ] **Step 5: 运行测试确认通过**

Run: `./vendor/bin/pest --testsuite=Unit --filter='Stash buildVlessNode'`
Expected: PASS,21 个测试全绿

- [ ] **Step 6: 跑全量 Unit 确认无回归**

Run: `./vendor/bin/pest --testsuite=Unit`
Expected: 0 个 FAIL。**注意:全量套件的退出码本来就是 1**,因为 `phpunit.xml` 设了 `failOnWarning="true"`,而 `tests/Unit/Utils/CookieTest.php` 有 2 个既有 warning(`Cannot modify header information - headers already sent`,源自 `vendor/composer/ClassLoader.php`)。这在本计划的第一个提交之前就存在(已在 `68947d67` 上复现)。验收标准是:**没有新的 FAIL,也没有新的 warning**,而不是退出码为 0。

- [ ] **Step 7: 提交**

```bash
git add src/Services/Subscribe/Stash.php tests/Unit/Services/Subscribe/StashVlessTest.php
git commit -F - <<'EOF'
feat(subscribe): Stash 订阅下发 VLESS 节点(sort=12)

与 Clash 侧同语义:reality-opts.public-key 由 private_key 推导,
server_names/short_ids 取首项,flow 仅在 network=tcp 时输出,
拿不到公钥则跳过该节点。

Surge 至今不支持 VLESS(官方协议清单无此项),sort=12 落入其
现有 default 分支,不做改动。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01J5wYZFANWzAFfoQ3q8s8QN
EOF
```

---

## 完工验收

全部 5 个任务完成后:

- [ ] `./vendor/bin/pest --testsuite=Unit` 无 FAIL,且 warning 数仍为 2(仅 `CookieTest` 的既有两条)。退出码为 1 属正常 —— 见下方说明。
- [ ] `git log --oneline -5` 应看到 5 次提交,均带 Co-Authored-By trailer
- [ ] `rg -n "vless|VLESS" --glob '!vendor' src/ resources/ tests/ | wc -l` 应为非零,且不出现在 `Surge.php`

### 部署侧检查项(不在本计划的代码改动范围内,交付时需告知运维)

1. XrayR 的 `config.yml` 必须设 `NodeType: V2ray` 与 `DisableLocalREALITYConfig: true` —— 后者不设时,XrayR 会用自身本地 REALITY 配置覆盖面板下发的内容(`service/controller/inboundbuilder.go:213`)
2. `custom_config.offset_port_node` 必须写成 JSON 字符串(`"443"` 而非 `443`)—— XrayR 的 Go 结构体该字段类型为 `string`,写成数字会导致 `custom_config format error`
3. `reality-opts.private_key` 由 `xray x25519` 生成,面板只需私钥,公钥自动推导
4. Surge 用户看不到 VLESS 节点,属预期行为
