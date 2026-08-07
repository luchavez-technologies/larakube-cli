# Plan: `vpn:wire` — the Traefik middleware `--vpn-only` has always been missing

**Status:** ✅ BUILT — verified 2026-08-08: `vpn:wire` and `vpn:unwire` both ship.

## 🎯 Objective

`vpn:wire <tool>` creates the Traefik `Middleware` CRD that every `--vpn-only`
flag's ingress annotation already references — **and which nothing in the
codebase currently creates.** `vpn:wire <tool> --remove` reverses it. Not a
nice-to-have: this closes a real, currently-shipping bug.

## 🔍 The actual severity (verified, not assumed)

```
$ grep -rln "vpn-only" resources/views/k8s/ app/Commands/
13 files — every *:init with a --vpn-only flag (Chat, Desk, Errors, Flow,
Git, Insights, Mail, Monitor, Passwords, Secrets, Sheet, Uptime)

$ grep -rn "kind: Middleware" resources/views/k8s/
(zero results)
```

Every one of those 13 ingress templates does:
```yaml
@if($vpnOnly ?? false)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-desk-vpn-only@kubernetescrd
@endif
```
— a reference to a Traefik `Middleware` resource named `desk-vpn-only` in
`larakube-shared`. **That resource is never created anywhere.** Traefik
cannot resolve a middleware chain that references a nonexistent CRD, and its
documented behavior for that is to fail the whole router — meaning
`desk:init --vpn-only` today doesn't restrict access to VPN peers, it likely
**breaks the ingress for every visitor**, VPN-connected or not. This is
worse than "not implemented" — it's actively destructive, and it's been
shipping in 13 commands' `--vpn-only` flag the whole time.

## 🧱 Design

- **`ClusterTool::vpnMiddlewareTarget(): array{name: string, namespace: string}`**
  — the enum-owns-the-schema pattern already used for `smtpEnv()`/`oidcEnv()`.
  `name` = `"{tool}-vpn-only"` (matches what the ingress templates already
  reference — don't rename anything, just start creating what they expect).
  `namespace` = wherever that tool's ingress actually lives (`larakube-shared`
  for most; `larakube-vault` for Passwords, `larakube-secrets` for Secrets,
  `larakube-sso` for the planned SSO tool once it exists).
- **The Middleware itself**: Traefik's `ipAllowList` (v3 naming — verify
  against the actual bundled Traefik CRD version at implementation time; v2
  used `ipWhiteList`, a straightforward rename if wrong) with
  `sourceRange: ["100.64.0.0/10"]` — NetBird's default peer overlay network.
  Confirmed no override in `k8s/vpn/management-config.blade.php`, so this is
  what LaraKube's own NetBird deployment actually assigns to connected peers,
  not a guess.
- **`vpn:wire <tool>`**: apply/update that Middleware CRD. Idempotent — safe
  to re-run (e.g. after `vpn:init` regenerates something, or if the peer CIDR
  is ever made configurable later).
- **`vpn:wire <tool> --remove`**: delete the Middleware CRD. **Real wrinkle**:
  if the tool was deployed with `--vpn-only`, its ingress *annotation* still
  references the now-deleted middleware — deleting the CRD without also
  clearing the annotation reintroduces the exact same "dangling reference
  breaks the whole ingress" failure this plan exists to fix. `--remove` must
  either (a) re-render that tool's ingress partial with `vpnOnly: false` and
  re-apply it, or (b) refuse and tell the user to re-run `{tool}:init`
  without `--vpn-only` first. Leaning (a) — it's one `kubectl apply` of the
  same ingress template every `*:init` already renders, no new mechanism
  needed, just calling it from `vpn:wire --remove` too.

## 🛠 Commands

```bash
larakube vpn:wire desk              # create the IPAllowList Middleware desk-vpn-only in larakube-shared
larakube vpn:wire desk --remove     # delete it + re-apply desk's ingress without the middleware annotation
```

## ♻️ Reuse

- `ClusterTool` enum pattern (`smtpEnv()`/`oidcEnv()`) for `vpnMiddlewareTarget()`.
- `DeploysClusterTool::resolveToolContext()` / `removeResources()` — same
  context-resolution and failure-checking discipline as every tool fixed
  this week. No excuse for a brand-new command to skip it.
- Each tool's own ingress blade partial (`k8s.desk.ingress`, etc.) — `--remove`
  re-renders and re-applies the SAME template other commands already use,
  just with `vpnOnly: false`.

## 🚦 Phases

1. [x] `ClusterTool::vpnMiddlewareTarget()` + `vpn:wire <tool>` (create the
   Middleware, then re-apply the target's ingress WITH `--vpn-only` by
   delegating to its own `{tool}:init` — no parallel render logic). Exact
   per-tool middleware name/namespace confirmed by reading every ingress
   template directly (several don't follow `$tool->value}-vpn-only` —
   `errors`→`glitchtip-web`, `git`→`gitea`, `sheets`→`sheet`,
   `uptime`→`uptime-kuma` — a generic derivation would've shipped wrong).
   **Still unverified**: the exact Traefik CRD field name (`ipAllowList` vs
   `ipWhiteList`) — used `traefik.io/v1alpha1` + `ipAllowList` (Traefik v3
   naming) as the best-confidence choice; no live cluster to confirm against
   this session. Unit-tested via `VpnWireCommandTest`.
2. [x] `vpn:wire <tool> --remove` — re-applies the ingress WITHOUT the
   annotation FIRST, then deletes the Middleware (wrong order would leave a
   dangling reference mid-operation). Unit-tested.
3. [x] **`*:init --vpn-only` now creates the Middleware itself** — the open
   question below ("should `*:init --vpn-only` call this automatically") is
   resolved: yes. Added `DeploysClusterTool::ensureVpnMiddleware(ClusterTool,
   kubectl)` (renders the same `k8s.vpn.ip-allow-list-middleware` partial,
   applies it via `applyResource()`, no-op for tools without a target) and
   wired a guard into all 13 `*:init` deploy paths — `if ($vpnOnly && !
   $this->ensureVpnMiddleware(...)) return 1;` placed BEFORE the manifest
   apply, so the annotated ingress is never applied against a nonexistent
   Middleware. `VpnWireCommand::wire()` now delegates to the same shared
   method (single source of truth, the two paths can't drift). This means a
   plain `desk:init --vpn-only` is now self-contained and correct;
   `vpn:wire <tool>` remains for re-applying/rotating on an already-deployed
   tool. Regression-tested via `ChatInitCommandTest` (creates the Middleware
   before the manifest; aborts when that apply fails).
   Still communication-only: anyone who ran `--vpn-only` on a real cluster
   *before* this fix is serving a broken ingress until they re-run
   `{tool}:init --vpn-only` (or `vpn:wire <tool>`) once — a real-world
   regression to call out in release notes, not just a docs line.

## ✅ Verification

- `desk:init local --vpn-only` (no `vpn:wire` yet) → confirm the ingress is
  currently broken (reproduces the bug before claiming the fix works).
- `vpn:wire desk` → same ingress now serves correctly from a NetBird-connected
  peer, and returns e.g. 403 (not 500) from outside the VPN.
- `vpn:wire desk --remove` → ingress serves normally from anywhere again, no
  dangling middleware reference.

## ⚠️ Risks / open questions

- **Traefik CRD field name unverified** (`ipAllowList` vs `ipWhiteList`) —
  don't ship without checking the actual installed Traefik/CRD version.
- **This retroactively affects every already-shipped `--vpn-only` install.**
  Framing this purely as "a new wiring command" undersells it — it's a fix
  for something already broken in production-shape code.
- ~~**Should `*:init --vpn-only` call `vpn:wire` automatically**~~ **RESOLVED
  (Phase 3): yes.** Every `*:init --vpn-only` now creates the Middleware
  itself via the shared `DeploysClusterTool::ensureVpnMiddleware()` before
  applying its ingress. It does NOT call `vpn:wire` as a subcommand (that
  would re-invoke the whole `{tool}:init` a second time via `vpn:wire`'s own
  re-apply step — circular); instead both call the same underlying trait
  method. `vpn:wire` standalone stays useful for re-applying/rotating on an
  already-deployed tool.

## 🔁 The general pattern this establishes: `*:wire --remove`

Not unique to VPN — the same "wiring commands need an undo" gap applies to
`sso:wire` (planned) and eventually `mail:wire` (shipped without `--remove`
today). The convention going forward: **every `*:wire <tool>` command gets a
`--remove` that undoes exactly what wiring it did** — SMTP env vars unset for
`mail:wire`, the OIDC client deregistered + env vars unset for `sso:wire`,
the Middleware deleted + ingress annotation cleared for `vpn:wire`. Retrofit
`mail:wire --remove` is a small, separate follow-up, not bundled into this
plan.
