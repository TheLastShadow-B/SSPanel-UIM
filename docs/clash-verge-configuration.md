# Clash Verge / Mihomo 配置优化

适用于 `/clash` 订阅。`calsh-hako`、Stash、Surge 的配置未在此次修改中调整。

## 修改及原因

- DNS 改为 `nameserver-policy`：`geosite:cn` 使用国内 DoH 并显式 `#DIRECT`；其他域名默认使用 Google/Cloudflare DoT，经 `#Global` 出站。移除旧 `fallback` / `fallback-filter`，其中 `fallback-filter.geosite` 已被官方标记废弃。
- 开启 `respect-rules`，保留独立的 `proxy-server-nameserver` 和 `prefer-h3: false`。引导 DNS 与节点 DNS 不依赖代理。现有节点 DNS 仍包含明文 UDP，这是保留的可用性取舍。
- 补充 `direct-nameserver` 与 `direct-nameserver-follow-policy: true`。`.lan`、`.local` 使用系统 DNS；`.lan` 同时增加直连路由。系统 DNS 必须能解析相应的内网域名。
- 保留 Fake-IP、IPv6、`mixed` 栈和当前 TUN 开关；移除无效的 `auto-redir`，而不是自动启用仅 Linux 使用的 `auto-redirect`。
- `endpoint-independent-nat` 设为 false，避免无明确需求时增加开销。如果特定游戏或 P2P 应用需要此 NAT 行为，可在客户端重新开启。
- 保留 HTTP/TLS/QUIC 嗅探及纯 IP 嗅探，但显式设置全局 `override-destination: false`，减少 TLS/QUIC 嗅探改写目的地的副作用；HTTP 保留其协议级 true 覆盖。
- 保留 `unified-delay`，开启 `tcp-concurrent`，日志级别改为 warning。并发建连旨在减少多 IP 建连等待，不会叠加下载带宽。
- 地区组设置 `empty-fallback: REJECT`；没有节点时阻断，不回退到兼容直连出口。`Global` 增加 `include-all-proxies`，让其他地区的节点也能选择。
- 示例模板将游戏下载规则放在娱乐分类之前，并加入本地实际配置中已有的 Steam/CDN 细分规则。保留 GitHub 规则优先于 Microsoft，以及 `api.github.com` 优先走 AI Services。
- 完整 Loyalsoldier 广告规则继续保留，未缩减广告覆盖范围。桌面端也继续使用原有 geosite / GeoIP 数据资源。

这些是针对当前模板的取舍，并非一套适合所有网络的官方固定配置。

## 现有面板如何同步

`config/appprofile.php` 被 Git 忽略，因此更新仓库不会自动更新线上实际配置。不要用整个示例文件覆盖它，否则可能丢失 Stash、Surge 和证券等自定义配置。

此次提供的补丁针对本次审查前的实际 Clash 配置，只涉及 Clash 部分。在服务器的仓库目录备份、检查后应用：

```sh
cp config/appprofile.php config/appprofile.php.before-clash-verge-optimization

git apply --check docs/clash-verge-appprofile.patch
git apply docs/clash-verge-appprofile.patch
php -l config/appprofile.php
```

如果检查失败，说明线上配置与本次基线不同；按补丁逐项合并，不要强制应用。不要重复应用补丁。示例模板的游戏下载顺序修正没有包含在这个补丁中，因为本地实际配置原本已有该修正。

此前的 GitHub 修正需要实际配置中已有 `GEOSITE,github,Default Proxy`，位置在 Microsoft 规则前；`api.github.com` 的 AI Services 精确规则继续放在它前面。

更新面板配置后刷新 Clash Verge 订阅，并检查客户端最终运行配置。全局扩展、订阅扩展、DNS 覆写和客户端设置可能覆盖订阅内容。首次使用应在 Global 中选一个可用地区或节点；境外 DNS 依赖此出口，不能承诺代理断开时仍能完成境外解析。

## 仍需客户端确认的项目

- TUN 是否开启由使用场景决定；本次没有开启系统代理或 TUN，也没有验证 Windows/macOS 实际路由接管。
- 全局 IPv6、DNS AAAA 和实际网络 IPv6 可达性需要联合检查，不能只靠一个开关保证效果。
- 模板中的控制器绑定为本机地址，共享固定 secret 不适合用作对外 API 凭据；如果启用远程控制，应在客户端配置自己的随机密钥。
- 节点证书、`skip-cert-verify`、Hysteria 带宽参数来自各节点配置，不能在没有核实证书和链路的情况下统一改写。

## 验证结果

- 两份 PHP 配置通过语法检查，规则目标和顺序检查通过。
- 实际配置与示例模板生成的配置均通过 Mihomo v1.19.31 的配置校验。
- 隔离启动实际模板，验证空地区组返回 REJECT、Global 可选其他地区节点，以及完整广告规则加载（189,002 条）。使用测试节点，没有连接用户代理节点或修改系统路由。
- 订阅相关单元测试 91 项通过，共 550 个断言。
- 增量补丁在修改前的配置副本上应用成功，结果与本地优化后的实际配置逐字一致。
- 未进行 Windows/macOS Clash Verge GUI、真实链路时延、TUN 接管或内存占用对比测试。

## 官方依据

- [DNS 配置](https://wiki.metacubex.one/config/dns/)
- [TUN 配置](https://wiki.metacubex.one/config/inbound/tun/)
- [域名嗅探](https://wiki.metacubex.one/config/sniff/)
- [代理组字段](https://wiki.metacubex.one/config/proxy-groups/)
- [路由规则](https://wiki.metacubex.one/config/rules/)
- [全局配置](https://wiki.metacubex.one/config/general/)
- [Clash Verge 扩展配置](https://www.clashverge.dev/guide/extend.html)
