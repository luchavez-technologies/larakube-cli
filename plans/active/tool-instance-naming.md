# Plan: `ToolInstance`, one source of truth for every Cluster Tool resource name

**Status:** Stage 0 ✅. Stage 1: part 1 ✅ (CRM, Design), Batch A ✅ (Flow, Link,
Sheets, Record, Resume, Errors, Insights, Support, Tasks: not live, code only).
Sign ✅ (fresh install on per-instance names, `230400b`); Flow ✅ per instance with
ToolInstance names throughout (`2a19413`). Batch B (live: SSO, Passwords, Chat,
Monitor, Drive) needs a per-tool production migration first. Stages 2–3 not started.

**Decision (2026-09-18):** every tool gets per-instance Commons names, and live
tools are migrated by hand on production (not declared single-instance).
`ToolInstance` also owns the ADR 0021 names for Secrets, ConfigMaps and volumes
(`secret()`, `configMap()`, `volume()`), used by Stage 2.

**Stage 0 results** (`tests/Feature/ToolNamingDriftTest.php`, harness in
`tests/Support/ToolDriftHarness.php`): of 29 shipped tools, 2 pass (Paste,
Data), 22 are on `toolNamingKnownDrift()`, 5 on `toolNamingHarnessPending()`.
- 20 tools refuse `:remove --domain` (the allow-list), which hides any other drift.
- CRM: Redis tenant `crm_twenty_<instance>` is never freed by purge.
- Design: init hand-builds `design-backend-`, `design-secrets-`, `design-oidc-`
  names that remove never deletes; its Commons tenants aren't freed.
- Real bugs found, outside naming: `desk:init` calls a `flagOrPrompt()` it
  doesn't have; `notes:init` crashes non-interactively (select() with no default).

**Stage 0 deviation:** `ToolInstance` only exposes names that already have one
source (workloads, Commons tenants, VPN middleware, DB secret). `secret()`,
`configMap()`, `volume()`, `owned()`/`shared()` arrive in Stage 2, as each tool
declares them; adding ADR-pattern names now would invent names nothing uses.
**Walkthrough:** `plans/active/tool-instance-naming-testing.md`
**Enforces:** ADR 0021 (`{category}-{component}-{instance}`), whose rule 1 ("never
hardcode a resource name in a command") has no enforcement today.

## Why

Every `*:init`, `*:remove`, `*:show` and `*:wire` builds names from its own
string templates, and nothing checks they agree. Drift found in one session:

| Tool | Drift | Effect |
|---|---|---|
| Paste | init allocated fixed `paste_yopass` / `paste-yopass`; purge freed per-instance names | instances shared one Redis index and bucket; purge freed nothing (fixed in `ce5adb4`) |
| Mail | allocates Redis tenant `stalwart`; purge frees `stalwart_<instance>` | purge never frees Mail's index |
| Link, Support, Sheets | fixed Redis tenants `link_kutt`, `support_chatwoot`, `teable` | a second instance shares the first one's Redis; purge misses |
| Link, Meet, Monitor, Webmail | teardown deletes shared resources (`link-secrets`, `meet-keys`, the Prometheus/Loki stack, `webmail-storage`) | removing one instance breaks the other |
| Chat, Git | teardown closes shared firewall ports | same |
| `hasInstanceAwareRemoval()` | hand-maintained allow-list of 5 tools | wrong both ways: blocked Paste, and can't express "safe except shared ports" |
| VPN, SSO (ADR 0021) | init tenant ≠ purge tenant | purge reported success, dropped nothing |

Scale: 120 init/remove/show/wire commands, ~125 hand-built instance names in
PHP and ~44 in 30 Blade templates.

## What exists to build on

- `ClusterTool::components($instance, $engine)` → `ClusterToolComponentData`
  (deployment + `resources` + `backupVolume`), and
  `AbstractToolRemoveCommand::teardownComponentsCommand()`, already used by
  Chat, Dashboard and Git.
- `commonsDatabases($instance)`, `commonsBuckets($instance)`,
  `commonsRedisTenants($instance)` (new in `ce5adb4`), `vpnMiddlewareTarget()`,
  `dbSecretRef()`, `instanceSlugFromHost()`.
- Vendor contracts (`HasWorkloadComponents`, `HasCommonsDatabases`, …).

## Design

### `App\Data\ToolInstance`
An immutable value object: `tool`, `instance` (host-derived slug, ADR 0012),
`host`, `engine`. Built once per command from the resolved host:
`ToolInstance::forHost(ClusterTool $tool, string $host, ?string $engine)`.

It answers every name, delegating to the vendor where a tool deviates:

| Method | Returns |
|---|---|
| `deployment(?component)`, `service()`, `ingress()` | workload names |
| `secret(SecretKind)` | `credentials`, `oidc`, `smtp`, `config`, `store` (ADR 0021 table) |
| `configMap(key)`, `volume(key)` | ConfigMaps, PVCs |
| `vpnMiddleware()` | the `--vpn-only` Middleware |
| `commonsDatabases()`, `commonsRedisTenants()`, `commonsBuckets()` | Commons tenants |
| `owned(): list<ResourceRef>` | every namespaced resource this instance owns |
| `shared(): list<ResourceRef>` | resources shared by all instances of the tool (e.g. `meet-keys`, monitoring RBAC, firewall ports) |

`owned()` vs `shared()` is the key addition: a vendor must declare what is
shared, and teardown only ever deletes `owned()` unless it's the last instance.

### Commands consume it, never build names
- `*:init` renders manifests with names from the `ToolInstance`, passed to the
  Blade view as one `$names` array (no string templates in views).
- `AbstractToolRemoveCommand` deletes `owned()`, and `shared()` only when no
  other instance of the tool is registered. Subclass `teardown()` shrinks to
  the genuinely tool-specific steps (e.g. Mail's wired SMTP secrets), or goes
  away.
- `hasInstanceAwareRemoval()` is deleted: every tool is instance-aware by
  construction, and the `--domain` guard goes with it.
- `*:show`, `*:wire`, backup discovery and `tool:list --refresh` read names from
  it too.

### The drift test (the real enforcement)
One test file across every shipped tool, with two instances each:
1. Render the tool's `:init` manifests for both instances; collect every
   resource name, plus every Commons allocation `:init` requests (faked Plex).
2. Assert `:remove --purge --domain=<instance A>`:
   - deletes exactly A's `owned()` set,
   - releases exactly A's Commons tenants,
   - touches nothing of B's, and nothing in `shared()`.
3. Assert A's and B's sets intersect only in `shared()`.

Any future hand-built name that disagrees fails CI before it can reach a
cluster.

## No renames of live resources

This is a refactor of **where names come from**, not **what they are**.
Stage 1 must render byte-identical manifests for every tool (snapshot test
per tool), except where the drift test proves an init/remove mismatch, which
is fixed on its own, one tool at a time.

Renaming a live resource to match ADR 0021 (e.g. `grafana` →
`monitor-grafana-…`) orphans the old one and, for volumes and databases, its
data. Per the no-migration-code rule, any such rename is a separate, explicit
per-tool step with a hand cleanup on the cluster, never part of this refactor.

## Wiring names belong to the instance too

`sso:wire`, `mail:wire`, `vpn:wire`, `secrets:wire` and `meet:wire` create
resources that belong to one tool instance, and today each wire command and
each vendor names them separately. Removing Sign from production left two of
them behind (its Zitadel app and `sso-app-sign`, and an old
`sign-documenso-secrets-db` generator). `ToolInstance` owns these as well:

| Method | Resource | Today (examples) |
|---|---|---|
| `secret(SecretKind::OIDC)` | tool-side OIDC client Secret | `sign-oidc`, `link-oidc`, `drive-ocis-oidc` (vendor `oidcEnv()['secret']`) |
| `secret(SecretKind::SMTP)` | tool-side SMTP Secret | `sign-smtp`, `vaultwarden-smtp`, `sheet-teable-smtp` (vendor `smtpEnv()['secret']`) |
| `ssoApp()` | `larakube-sso/sso-app-{category}-{instance}` Secret + the Zitadel project/app names | `sso-app-sign`, `sso-app-notes-{instance}` |
| `vpnMiddleware()` | `--vpn-only` Middleware | `crm-vpn-only-{instance}`, `desk-vpn-only` |
| `databaseSecret()` / `databaseSecretSync()` | DB password Secret, and `secrets:wire`'s ExternalSecret + generator (`{secret}-db`) | vendor `dbSecretRef()`: `sign-secrets`, `link-secrets` |
| `openbaoStaticRole()` | OpenBao static role | the Commons database name |
| `meetBridge()` | `meet:wire`'s per-tool bridge Secret | per tool today |

Consequences:
- Vendors stop declaring these names (`oidcEnv()`/`smtpEnv()` keep their env
  mappings, but the `secret` key comes from `ToolInstance`).
- Every `:wire`/`:unwire` asks `ToolInstance` for the name, and
  `AbstractToolRemoveCommand` removes all of them through `owned()` instead of
  the special cases added in `dd3934c` (`deregisterSsoApp()`,
  `removeDatabaseSecretSync()`).
- The drift harness gains a wiring pass: wire every supported integration on
  instance A and B, remove A, and assert A's wiring is gone and B's untouched.
- Live renames (e.g. `sso-app-sign` → per-instance) follow the Batch B rule:
  a per-tool runbook, then re-run the relevant `:wire`.

## Stages (one commit each, after the drift test is green for that stage)

0. **`ToolInstance` + drift test harness.** The class, `ResourceRef`,
   `SecretKind`, and the drift test running against all tools with a
   `KNOWN_DRIFT` list that starts full and must only shrink. No command changes.
1. **Commons tenants.** Every `:init` allocates databases, Redis tenants and
   buckets through `ToolInstance`; purge frees the same. Fixes Mail, Link,
   Support, Sheets (Redis), and SSO's database name (ADR 0021). Amend ADR 0021
   with the Redis tenant row. Live effect: Link, Support, Sheets and Mail get
   new per-instance Redis tenants on their next `:init` (sessions and caches
   reset once; no persistent data lives in their Redis).
2. **Workload resources + `shared()`.** Deployments, Services, Ingresses,
   Secrets, ConfigMaps, PVCs, Middlewares through `ToolInstance`, in batches of
   ~6 tools. Link, Meet, Monitor, Webmail and the port-closing tools (Chat, Git,
   Meet, Mail) declare their shared resources. Delete
   `hasInstanceAwareRemoval()`.
2b. **Wiring names.** The table above, tool by tool with Stage 2, plus the
   harness's wiring pass.
3. **Readers.** `*:show`, `*:wire`, backup volume discovery and
   `tool:list --refresh` read from `ToolInstance`. `KNOWN_DRIFT` is empty.

## Open questions
- Should `shared()` resources be reference-counted in the tool registry, or is
  "no other registered instance" enough? (Registry-based is simpler; it's
  already the source of truth for instances.)
- Blade: pass `$names` (array) or the `ToolInstance` itself to views? Passing
  the object keeps views honest but couples them to PHP; decide in Stage 0.
