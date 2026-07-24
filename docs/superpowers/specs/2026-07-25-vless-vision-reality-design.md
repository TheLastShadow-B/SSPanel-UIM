# VLESS + Vision + REALITY 节点支持

**日期**:2026-07-25
**后端**:[TheLastShadow-B/XrayR](https://github.com/TheLastShadow-B/XrayR)(master,v1.0.3)
**状态**:设计已确认,待实现

## 背景

面板当前的节点类型枚举(`src/Models/Node.php:81`)为 `0 Shadowsocks` / `1 Shadowsocks2022` / `2 TUIC` / `3 WireGuard` / `11 Vmess` / `14 Trojan` / `15 Hysteria2`,不含 VLESS。全仓库(排除 `vendor`)搜索 `vless`、`reality`、`xtls`、`flow`、`pbk`、`short_id`、`fingerprint` 均无结果。

本设计新增 `sort = 12` 表示 VLESS 节点,覆盖 VLESS 的全部形态(传输层 tcp/ws/grpc/h2/httpupgrade,安全层 reality/tls/none,`flow` 可选),后端对接 XrayR。

## XrayR 契约(已验证)

读自 `api/sspanel/model.go` 与 `api/sspanel/sspanel.go`。

**关键事实:XrayR 完全不读面板下发的 `sort`。** `nodeInfoResponse.Sort` 在 `sspanel.go` 中一次都没有被引用(`grep -n '\.Sort'` 无匹配)。协议由 XrayR 自身 `config.yml` 的 `NodeType` 决定;VLESS 节点需配置 `NodeType: V2ray`。

`ParseSSPanelNodeInfo()` 中与本设计相关的分支:

```go
case "V2ray":
    transportProtocol = nodeConfig.Network

    tlsType := nodeConfig.Security
    if tlsType == "tls" || tlsType == "xtls" {
        enableTLS = true
    }

    if nodeConfig.EnableVless == "1" {     // 字符串 "1",不是 "true"
        enableVless = true
    }
```

```go
nodeInfo := &api.NodeInfo{
    EnableVless:   enableVless,
    VlessFlow:     nodeConfig.Flow,        // → 每用户 vless.Account.Flow
    EnableREALITY: nodeConfig.EnableREALITY,
    REALITYConfig: realityConfig,
    ...
}
```

`custom_config` 相关字段(`CustomConfig` / `REALITYConfig` 结构体):

| JSON key | Go 类型 | 备注 |
|---|---|---|
| `offset_port_node` | `string` | **必须是 JSON 字符串**,写成数字会导致 `custom_config format error` |
| `enable_vless` | `string` | 判等 `"1"` |
| `flow` | `string` | 透传给 xray-core |
| `network` | `string` | → `TransportProtocol` |
| `security` | `string` | `tls`/`xtls` 时置 `enableTLS` |
| `enable_reality` | `bool` | 真布尔值 |
| `reality-opts` | `*REALITYConfig` | **key 带连字符** |
| `reality-opts.dest` | `string` | |
| `reality-opts.server_names` | `[]string` | |
| `reality-opts.private_key` | `string` | **无 `public_key` 字段** |
| `reality-opts.short_ids` | `[]string` | |
| `reality-opts.proxy_protocol_ver` | `uint64` | |
| `reality-opts.min_client_ver` / `max_client_ver` | `string` | |
| `reality-opts.max_time_diff` | `uint64` | |

### 运维前提

面板下发的 REALITY 配置只在 XrayR 的 `config.yml` 设置 `DisableLocalREALITYConfig: true` 时生效(`service/controller/inboundbuilder.go:213`);否则 XrayR 使用自身的本地 `REALITYConfigs`。此项须写入部署文档。

## 决策记录

| 决策 | 选择 | 理由 |
|---|---|---|
| VLESS 的表示 | 新增 `sort = 12` | 管理端下拉框显式可见;`enable_vless` 由面板注入,消除 admin 两处声明的不一致风险 |
| REALITY 密钥对 | admin 只填 `private_key`,面板推导公钥 | 单一真相来源;`pbk` 与 `private_key` 不可能对不上 |
| 支持范围 | VLESS 全形态 | 传输层与安全层均可配,不锁死在 Vision+REALITY |
| 非法组合 | 生成器静默降级 | 节点仍可用,不因单字段写错而从所有人订阅中消失 |
| `vless://` 原始链接 | 不做 | `SubController.php:33` 的 `$subtype_list` 仅含 `clash`/`stash`/`surge`,原始链接生成器均不可达 |
| Surge | 不改动 | Surge 至今不支持 VLESS;`sort=12` 落入现有 `default` 分支 |

### 关于原始链接订阅

`SubController::index()` 硬编码:

```php
$subtype_list = ['clash', 'stash', 'surge'];
```

因此 `src/Services/Subscribe/` 下的 `V2Ray.php`、`SS.php`、`SIP002.php`、`SIP008.php`、`Trojan.php`、`Json.php` 均为不可达的死代码,`/sub/{token}/v2ray` 返回「订阅链接无效」。`config/client_display.json` 亦只登记 Clash Verge Rev、CMFA(clash)、Stash(stash)、Surge 5(surge)。本设计不触碰这些文件。

### 关于 Surge

[Surge 官方文档](https://manual.nssurge.com/policy/proxy.html)列出的全部协议为:`http`、`https`、`h2-connect`、`socks5`、`socks5-tls`、`ssh`、`wireguard`、`tailscale`、`snell`、`ss`、`vmess`、`trojan`、`tuic`、`hysteria2`、`anytls`、`trust-tunnel`。无 VLESS。

Surge 的 vmess 参数集为 `username`、`ws`、`ws-path`、`ws-headers`、`encrypt-method`、`vmess-aead` 及 TLS 通用参数(`sni`、`skip-cert-verify`、`alpn`、`server-cert-fingerprint-sha256`),无 `flow` / `reality` / `public-key` / `short-id`,无法借道。Surge 在该方向提供的是 Shadow TLS v3,与 REALITY 不互通。

`sort=12` 将落入 `Surge.php` 现有的 `default` 分支返回 `null`,节点不出现在 Surge 订阅中。本次不改 `Surge.php`(包括其 `:109` 处「TUIC/WireGuard not supported by Surge」的失准注释——Surge 实际支持二者,只是面板未实现分支;属既有问题,不在本次范围)。

## 架构

```
                      node.custom_config (admin 手写,一份)
                                  │
        ┌─────────────────────────┴─────────────────────────┐
        ▼                                                   ▼
 WebAPI\NodeController                          Subscribe\{Clash,Stash}
 注入 enable_vless="1"                           派生 pbk / sid / servername
 注入 enable_reality=true (当 security=reality)   降级非法 flow
        │                                                   │
        ▼                                                   ▼
     XrayR                                          mihomo / Stash
  NodeType: V2ray                                    type: vless
  DisableLocalREALITYConfig: true
```

admin 在 `custom_config` 写一份,面板负责翻译成 XrayR 与客户端各自的方言。

### admin 输入示例(sort=12,Vision+REALITY)

```json
{
  "offset_port_node": "443",
  "network": "tcp",
  "security": "reality",
  "flow": "xtls-rprx-vision",
  "host": "",
  "fingerprint": "chrome",
  "reality-opts": {
    "dest": "www.cloudflare.com:443",
    "server_names": ["www.cloudflare.com"],
    "private_key": "dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo",
    "short_ids": ["0123abcd"]
  }
}
```

面板下发给 XrayR 时在此基础上追加 `"enable_vless": "1"` 与 `"enable_reality": true`。

## 变更清单

| 文件 | 改动 |
|---|---|
| `src/Models/Node.php` | `sort()` 枚举加 `12 => 'VLESS'` |
| `resources/views/cafe/admin/node/create.tpl` | 下拉框加 `<option value="12">VLESS</option>` |
| `resources/views/cafe/admin/node/edit.tpl` | 同上,带 `{if $node->sort === 12}selected{/if}` |
| `src/Controllers/WebAPI/NodeController.php` | 注入 XrayR 开关;顺带修 `json_decode` 参数错位 |
| `src/Utils/Tools.php` | 新增 `genRealityPublicKey()` |
| `src/Services/Subscribe/Clash.php` | 抽出 `buildVlessNode()` 私有方法 + `case 12` |
| `src/Services/Subscribe/Stash.php` | 同上 |
| `composer.json` | `require` 补 `ext-sodium` |
| `tests/Unit/Utils/RealityKeyTest.php` | 新增 |
| `tests/Unit/Services/Subscribe/ClashVlessTest.php` | 新增 |
| `tests/Unit/Services/Subscribe/StashVlessTest.php` | 新增 |

无数据库迁移:`nodes.sort` 已是 int 列。

### Clash.php / Stash.php 的结构处理

两文件均为 231 行的内联 `switch`,已含 6 个 case。仅将 VLESS 一支抽为私有方法 `buildVlessNode($user, $node_raw, array $custom): array`,其余 case 保持原样。目的是让新逻辑可像 `Surge.php` 的 `build*Line()` 一样用反射单测,同时不把 diff 摊成大重构。

## 字段翻译表

| custom_config | → XrayR | → mihomo / Stash |
|---|---|---|
| *(由 sort=12 推导)* | `enable_vless: "1"` | `type: vless` |
| `offset_port_node` / `offset_port_user` | `offset_port_node` | `port` |
| `network` | `network` → `TransportProtocol` | `network`(`httpupgrade` → `ws`) |
| `security` | `security`;`= "reality"` 时推导 `enable_reality: true` | `tls: true`(reality/tls/xtls 均 true) |
| `flow` | `flow` → 每用户 `vless.Account.Flow` | `flow`(仅 `network = tcp` 时输出) |
| `host` | `host` | `servername`(非 reality 时) |
| `fingerprint` | 忽略(uTLS 为纯客户端概念) | `client-fingerprint`,缺省 `chrome` |
| `reality-opts.private_key` | 原样 | 推导出 `reality-opts.public-key` |
| `reality-opts.server_names[]` | 原样(数组) | `servername` = `[0]` |
| `reality-opts.short_ids[]` | 原样(数组) | `reality-opts.short-id` = `[0]`,缺省 `""` |
| `reality-opts.{dest,proxy_protocol_ver,min_client_ver,max_client_ver,max_time_diff}` | 原样 | 不下发(纯服务端) |
| `allow_insecure` | `allow_insecure` | `skip-cert-verify` |
| `udp` / `ws-opts` / `grpc-opts` / `h2-opts` | 按现状 | 按现状,与 vmess 分支一致 |

`server_names` 与 `short_ids` 在后端是数组、在客户端是单值,取 `[0]` 是唯一合理映射——客户端单次连接也只能用一个 SNI 与一个 short-id。

客户端键名依据:[mihomo VLESS 文档](https://wiki.metacubex.one/config/proxies/vless/)、[Stash Wiki](https://stash.wiki/en/proxy-protocols/proxy-types)。Stash 自 2025 年中版本起支持 VLESS XTLS-Vision 与 REALITY,键名与 mihomo 一致。

## 组件设计

### `Tools::genRealityPublicKey(string $private_key): string`

REALITY 使用 X25519。`xray x25519` 的公钥推导即 X25519 基点标量乘,等价于 `sodium_crypto_scalarmult_base()`。

```php
public static function genRealityPublicKey(string $private_key): string
{
    // 容忍 base64url / 标准 base64 / 有无 padding
    $raw = base64_decode(strtr(trim($private_key), '-_', '+/'), true);

    if ($raw === false || strlen($raw) !== 32) {
        return '';
    }

    return rtrim(strtr(base64_encode(sodium_crypto_scalarmult_base($raw)), '+/', '-_'), '=');
}
```

失败一律返回空串,由调用方决定后果。已实测三种输入形态(43 字符无 padding base64url、带 padding、标准 base64)均解出 32 字节,非法字符在 strict 模式下返回 `false`。

`composer.json` 的 `require` 需补 `"ext-sodium": "*"`(PHP 7.2 起 libsodium 已入核心,绝大多数发行版默认启用,但当前 `composer.json` 未声明)。

### `Clash::buildVlessNode()` / `Stash::buildVlessNode()`

两者输出结构相同(Stash 与 Clash 配置格式兼容,现有 vmess 分支即逐字段一致),各自实现以匹配所在文件的既有风格。

读取:

```php
$port        = $custom['offset_port_user'] ?? ($custom['offset_port_node'] ?? 443);
$security    = $custom['security'] ?? 'none';
$network     = $custom['network'] ?? 'tcp';
$host        = $custom['header']['request']['headers']['Host'][0] ?? $custom['host'] ?? '';
$flow        = $custom['flow'] ?? '';
$fingerprint = $custom['fingerprint'] ?? 'chrome';
$udp         = $custom['udp'] ?? true;
$allow_insecure = $custom['allow_insecure'] ?? false;
$reality     = $custom['reality-opts'] ?? $custom['reality_opts'] ?? [];
```

规则:

1. `network === 'httpupgrade'` → `'ws'`(与现有 vmess/trojan 分支一致)
2. `$is_reality = $security === 'reality'`;`$tls = $is_reality || $security === 'tls' || $security === 'xtls'`
3. `$flow !== '' && $network !== 'tcp'` → `$flow = ''`(Vision 仅支持裸 TCP)
4. `$is_reality` 为真时:
   - `$pbk = Tools::genRealityPublicKey($reality['private_key'] ?? '')`
   - 为空则回落到 `$reality['public_key'] ?? ''`
   - 仍为空 → `return []`(节点跳过)
   - `servername` = `$reality['server_names'][0] ?? $host`
   - `reality-opts` = `['public-key' => $pbk, 'short-id' => (string) ($reality['short_ids'][0] ?? '')]`
5. `$is_reality` 为假时:`servername` = `$host`,不输出 `reality-opts`
6. `$flow !== ''` 时才输出 `flow` 键
7. `ws-opts` / `grpc-opts` / `h2-opts` 的读取与输出沿用现有 vmess 分支写法(即便为 `null` 也输出该键,保持与同文件其他 case 一致)

输出形状(Vision + REALITY):

```yaml
- name: HK-01
  type: vless
  server: a.example.com
  port: 443
  uuid: <user->uuid>
  udp: true
  tls: true
  skip-cert-verify: false
  servername: www.cloudflare.com
  network: tcp
  client-fingerprint: chrome
  flow: xtls-rprx-vision
  reality-opts:
    public-key: hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo
    short-id: 0123abcd
```

### `WebAPI\NodeController::info()`

现状(`src/Controllers/WebAPI/NodeController.php:39`):

```php
'custom_config' => json_decode($node->custom_config, true, JSON_UNESCAPED_SLASHES),
```

此处 `JSON_UNESCAPED_SLASHES`(值 64)被当作 `$depth` 传入,`$flags` 位留空。实际无害(64 层深度足够)但语义错误,借本次改动一并修正。

改为:

```php
$custom_config = json_decode((string) $node->custom_config, true) ?? [];

if ((int) $node->sort === 12) {
    $custom_config['enable_vless'] = '1';

    if (($custom_config['security'] ?? '') === 'reality') {
        $custom_config['enable_reality'] = true;
    }
}
```

注入仅对 `sort = 12` 生效,其余节点类型的下发内容不变。

## 错误处理

| 情形 | 行为 |
|---|---|
| `flow` 非空但 `network ≠ tcp` | 丢弃 `flow`,节点照常下发 |
| `security ≠ reality` 但写了 `reality-opts` | 忽略 `reality-opts`,`servername` 取 `host` |
| `security = reality` 但 `private_key` 缺失/非 32 字节 | 回落 `public_key`;仍无则**跳过该节点**(返回 `[]`) |
| `short_ids` 为空数组或缺失 | `short-id: ""`(Xray 语义为「任意」) |
| `custom_config` 整体非法 JSON | 现有行为:`json_decode` 返回 `null`,`?? []` 兜底 |
| Surge 订阅遇到 `sort = 12` | 落入 `default` 分支,节点不出现 |

「跳过节点」是对「静默降级」原则的唯一例外:缺 `pbk` 的 REALITY 客户端配置无法握手,不存在可降级的目标。

## 测试

全部为纯单测(反射调用私有方法,不依赖数据库),沿用 `tests/Unit/Services/Subscribe/SurgeHysteria2Test.php` 的写法。运行:`./vendor/bin/pest --testsuite=Unit`。

### `tests/Unit/Utils/RealityKeyTest.php`

定值向量取自 RFC 7748 §6.1(已实测 `sodium_crypto_scalarmult_base` 输出与标准向量逐字节相同):

- 私钥 `dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo` → 公钥 `hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo`
- 带 `=` padding 的同一私钥 → 相同公钥
- 标准 base64(`+/` 字母表)的同一私钥 → 相同公钥
- 解码后长度 ≠ 32 字节 → `''`
- 含非 base64 字符 → `''`
- 空串 → `''`

### `tests/Unit/Services/Subscribe/ClashVlessTest.php` 与 `StashVlessTest.php`

- 完整 REALITY + Vision 节点的全字段断言
- `network = ws` 时 `flow` 被丢弃,其余字段完好
- `servername` 取 `server_names[0]`
- 非 reality 时 `servername` 回落到 `host`,且不含 `reality-opts` 键
- `short-id` 取 `short_ids[0]`;数组缺失时为 `''`
- `private_key` 缺失且无 `public_key` → 返回 `[]`
- `private_key` 非法但有 `public_key` → 使用 `public_key`
- `tls` 在 `reality`/`tls`/`xtls` 下为 `true`,`none` 下为 `false`
- `network = httpupgrade` → `ws`
- `offset_port_user` 优先于 `offset_port_node`
- `fingerprint` 缺省为 `chrome`

## 范围外

- 不新增 `Admin\NodeController` 的 `custom_config` schema 校验
- 不做 REALITY 密钥的管理端生成 UI
- 不强制将 `offset_port_node` 转为字符串输出(会影响存量所有协议节点);作为配置注意事项写入部署文档
- 不为 Surge 补 TUIC / WireGuard 分支
- 不触碰不可达的原始链接生成器

## 部署文档要点

1. XrayR `config.yml` 需设 `NodeType: V2ray` 与 `DisableLocalREALITYConfig: true`
2. `custom_config.offset_port_node` 必须写成 JSON 字符串(`"443"` 而非 `443`)
3. `private_key` 由 `xray x25519` 生成,只填私钥,公钥由面板推导
4. Surge 用户看不到 VLESS 节点,属预期行为
