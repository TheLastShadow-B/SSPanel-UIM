# Clash (clash.md) subscription

The Apple client named **Clash** uses `/sub/{token}/calsh-hako`. The spelling
`calsh-hako` is intentional. Existing `/clash`, `/stash` and `/surge` formats are
unchanged. The new endpoint uses the existing host/token checks, node permissions,
rate limits, subscription logging, traffic/expiry headers and six-hour profile
update hint. It returns a complete YAML profile with that user's current nodes.

The user-facing client registry is `config/client_display.json`. Its Apple entry
uses `clash://install-config?url=...`; the nested subscription URL is encoded by
`ClientConfig`. Official source confirms this scheme:
https://github.com/TokenPLS/Hako-Client/blob/main/apple/HakoClient/Sources/ConfigUI/ProfileImportRouter.swift

## Profile

`config/hako.php` is a separate, credential-free profile template. Hako reuses the
Clash node serializer; user passwords, UUIDs and REALITY public keys are generated
per request, never stored in the template. It does not request another subscription
or send a user's token to a conversion service.

- Domain/IP resources use MRS, with no GEOSITE/GEOIP references or complete geodata
  downloads. Region/service routing is retained, including Steam CN and securities.
- **Ads use MetaCubeX category-ads-all, a smaller set than the former Loyalsoldier
  reject list. They are not equivalent in coverage.** Restoring full coverage
  requires publishing a genuine compiled MRS resource and changing its provider.
- China domains use domestic DNS. Other DNS queries use Google/Cloudflare DoT
  through Default Proxy. Node-domain DNS stays independent. This differs from the
  desktop fallback-filter policy; proxy failure can also affect remote DNS.
- Preserve `tun.stack: mixed`, Fake-IP and stored selections. Logging is warning;
  sniffing is disabled and DNS cache is capped at 1024 entries. These latter
  values are implementation choices for this profile, not mandated by the docs.
- Region groups contain only nodes available to the requesting user; missing
  regions select REJECT, not DIRECT. Global also lists nodes with other names.
- Server TLS settings are preserved. Any node using skip-cert-verify still needs
  its certificate/SNI checked separately before enabling certificate validation.

Sources: https://clash.md/zh/guide/config/best-practice,
https://clash.md/zh/guide/config/inbound,
https://clash.md/zh/guide/config/dns.

## Deployment / validation

Deploy `config/hako.php`, `config/client_display.json`,
`src/Services/Subscribe/Hako.php`, `src/Services/Subscribe/Clash.php`,
`src/Services/Subscribe.php`, and `src/Controllers/SubController.php` together.
No database migration or user token reset is needed. Refresh Composer's autoloader
if the production deployment uses authoritative class maps; reload PHP workers
according to the existing deployment's OPcache policy.

Run focused tests with `vendor/bin/pest tests/Unit/Services/Subscribe`. Validate a
real `/calsh-hako` response with the client's core, verify traffic/expiry headers,
and confirm the old `/clash` response still loads. Finish with an actual iOS import
and Wi-Fi/cellular connection test; desktop parsing cannot prove iOS memory use.
