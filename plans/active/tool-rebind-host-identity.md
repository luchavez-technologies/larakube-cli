# `tool:rebind` — pin Cluster Tool identity across a domain change

## Status: SHIPPED (2026-10-15)

## Why this exists

A live incident (lost access to a domain a Cluster Tool was running under)
surfaced a real gap. Per ADR 0012/0021, a tool's Kubernetes resource identity
(Deployment, Service, PVC, Secret, DB, bucket — all `{component}-{instance}`)
is keyed off an opaque `instance` slug that's supposed to be pinned once
assigned, but every `*:init` command except CRM/DATA/DESIGN/FLOW/NOTES
re-derives it fresh from whatever host it's given
(`ClusterTool::instanceSlugFromHost()`), every single run. So re-running
`{tool}:init --domain=<new>` after losing a domain doesn't recover the old
instance — it mints a different one, leaving the original (and its PVCs/DB)
orphaned.

This cascades into a confirmed, separate bug: `SsoWireCommand` resolves its
instance the same host-derived way
(`cli/app/Commands/Sso/SsoWireCommand.php:99`), so a changed host makes its
"reuse the existing Zitadel project" lookup
(`ssoAppSecretName($tool, $instance)`) miss, and `zitadelEnsureProject()`
silently creates a **second** project — stranding every existing role grant
on the orphaned original. Structural across every RBAC-gated tool, not
tool-specific.

## The fix

The host that first registers a tool stays its identity — not by
rearchitecting `instanceSlugFromHost()` (ADR 0012/0021 are untouched), but by
actually honoring the already-pinned `instance` in the registry instead of
re-deriving it, plus one explicit, operator-invoked command,
`tool:rebind`, that re-points which host currently serves an already-pinned
instance.

`ToolRegistry::register()`/`matchIndex()`
(`cli/app/Services/ToolRegistry.php:203-221,314-359`) already does the
needed "merge in place by instance" when given an explicit `$instance` —
**no changes were needed there.** `tool:rebind` just calls
`registerTool($kubectl, $tool, ['host' => $newHost], $instance)` with the
instance resolved from the OLD host (or the tool's sole entry), and every
later lookup against the new host — including `sso:wire`'s — resolves back
to that same pinned instance for free. Traced through
`zitadelEnsureProject()` (`InteractsWithZitadelApi.php:245-286`): it reuses
the project by id first, and even the OIDC-app-mismatch re-registration path
still finds the same project **by name**, since `rbacProjectName($instance)`
is unaffected by the host change. A new OIDC client is minted (expected,
since redirect URIs genuinely changed) but the project — which holds role
grants — never does.

## Ruled out: auto-chaining into `{tool}:init`

The obvious-looking alternative — "patch the registry's host, then just
re-run `{tool}:init --domain=<new>`, its own idempotent logic will pick up
the recorded host" — is **unsafe** and was deliberately not built. Every
`:init` command except CRM/DATA/DESIGN/FLOW/NOTES derives its instance
directly from whatever host it's given (confirmed in
`PasswordsInitCommand.php:51`, `MonitorInitCommand.php:51`,
`ChatInitCommand.php:63`, `MailInitCommand.php:62`, `VpnInitCommand.php`,
`InsightsInitCommand.php:83`), bypassing the registry entirely. Chaining into
one would re-derive a **different** instance from the new host and silently
reproduce the exact bug this command exists to prevent. `tool:rebind` does
its own narrow registry + Ingress mutation and nothing else — never call
`{tool}:init` from it, and don't "simplify" it into doing so later.

## The ingress-template landmine

Most `ingress.blade.php` templates already honor a passed-in `$instance`
(`notes/ingress.blade.php:4`, `git/ingress.blade.php:4` use `??=`;
`design/ingress.blade.php`, `vpn/ingress.blade.php` use an equivalent
`($instance ?? '') !== '' ? $instance : derive(...)` form) — a caller-
supplied instance wins over deriving fresh from the host.

`chat/ingress.blade.php:14` did not: `$instance =
ClusterTool::CHAT->instanceSlugFromHost($host);`, unconditional. Chat is one
of the RBAC-gated tools this fix most needs to work for, so a rebind against
it would have silently re-derived a fresh instance and defeated itself.
**Fixed** (switched to `??=`). Spot-checked Design/Penpot and VPN's
templates — both already safe. A full audit across all ~29 tools' templates
is explicitly deferred; `tool:rebind` is confirmed safe for chat, notes,
git, design, and vpn — treat any other tool as unverified until checked.

## A second landmine: view-path resolution via legacy category word

`DeploysClusterTool::reapplyToolIngress()` (shared by `tool:alias` and
`tool:rebind`) resolves its Blade view as `"k8s.{$tool->value}.ingress"`.
Blade view **folders** are still named after the legacy category word
(`k8s/mail/`, `k8s/git/`) even for a canonical case like `STALWART`/
`FORGEJO`, whose `->value` is `stalwart`/`forgejo` — a mismatch that would
silently no-op the Ingress update (view-not-found returns `false` quietly)
for anyone passing the canonical tool name. Fixed by resolving the view via
`$tool->legacyCategoryPrefix() ?? $tool->value` instead of `$tool->value`
alone — this also fixes the same latent gap in `tool:alias`, for free.

**Note for Track B (legacy category case removal):** `legacyCategoryPrefix()`
is NOT legacy cruft to delete, despite its name — it is the permanent
mechanism that maps a tool to its (never-renamed) view folder. When deleting
a legacy case from its match arms, remove only the `self::<CASE>,` reference
from the arm (e.g. `self::STALWART, self::MAIL => 'mail'` →
`self::STALWART => 'mail'`), never the mapped folder value or the method
itself.

## Command

```
tool:rebind {environment=local : Environment whose deployment to rebind}
    {--tool= : The tool to rebind (e.g. mail, chat, forgejo)}
    {--domain= : The CURRENT host identifying which registered instance to target. Omit for the tool's sole instance}
    {--to= : The NEW host this instance should be served under}
    {--context= : Target a specific kube-context}
    {--force : Skip the confirmation prompt; required together with --to when non-interactive}
```

Steps: resolve the pinned instance + current host from the registry → resolve
`--to=` → guard against a no-op (same host) and a collision (new host
already owned by a different tool/instance) → confirm (or require `--force`
non-interactively) → `registerTool()` merge-in-place → `reapplyToolIngress()`
with the pinned instance and `recordCurrentHost: true` (stamps a
`larakube.io/current-host` **annotation**, never a label — a hostname has
dots and can exceed the 63-char DNS-1123 label format — purely for
`kubectl`/operator visibility, never read back by any resolution logic) →
local cert issuance if the new host is a local TLD → print next steps
(re-run `sso:wire` if the tool is SSO-wired; note that host-baked Deployment
env vars are not refreshed; note stale mail DKIM/SPF/MX records if the tool
is Mail/Stalwart).

## Out of scope (deferred, not dropped)

- **Refreshing host-baked, non-secret Deployment env vars**: Directus
  `PUBLIC_URL` (`data/directus.blade.php:35`), Planka/Record `BASE_URL`
  (`tasks/planka.blade.php:43`, `record/shared.blade.php:43`), Grafana
  `GF_SERVER_ROOT_URL` (`monitoring/shared.blade.php:913`), Sign
  `NEXT_PUBLIC_WEBAPP_URL` (`sign/shared.blade.php:44`), Mail
  `STALWART_PUBLIC_URL` (`mail/stalwart.blade.php:110`). Needs its own
  per-tool "refresh this one host-derived value without touching instance
  derivation" mechanism — real gap, future work.
- **Full ingress-template audit** across all ~29 tools beyond chat, notes,
  git, design, vpn (checked here).
- **DKIM/SPF/MX cleanup automation** for a changed mail domain — cross-
  reference `cli/plans/active/dns-pin-command.md`.
- **The SSO duplicate-project bug for non-host-change scenarios** (tool
  reinstall, etc.) — `cli/plans/active/sso-prune-stale-projects.md` is the
  existing backstop/cleanup for pre-existing orphaned projects;
  `tool:rebind` prevents new ones from a host change going forward, it
  doesn't replace that effort.
- **`tool:alias`'s own pre-existing silent-failure gap**: its original
  `reapplyToolIngress()` call discarded the return value, so a failed
  Ingress re-apply was always reported as success. Noticed while extracting
  the shared method; not fixed here since it's unrelated to host identity —
  `tool:alias`'s call site still doesn't check the return value, only
  `tool:rebind`'s does.

## Critical files touched

- `cli/app/Commands/Tool/ToolRebindCommand.php` (new)
- `cli/app/Traits/DeploysClusterTool.php` (new shared `reapplyToolIngress()`,
  extracted and generalized from `ToolAliasCommand`)
- `cli/app/Commands/Tool/ToolAliasCommand.php` (local copy removed, now uses
  the trait's version unchanged in behavior)
- `cli/resources/views/k8s/chat/ingress.blade.php` (unconditional →
  `??=` instance derivation)
- `cli/docs/decisions/0021-cluster-tool-resource-naming.md` (amendment)
- `cli/tests/Feature/ToolRebindCommandTest.php` (new)

## Verification

`composer format && composer analyse && composer test` in `cli/`. Manual
battle-test after `./build`: install a Cluster Tool, note resource names,
`tool:rebind` to a new host, confirm via `tool:show`/`kubectl get` that
Deployment/PVC/Secret/DB names are unchanged and only the Ingress host/TLS/
`larakube.io/current-host` annotation changed, then run the printed
`sso:wire` follow-up and confirm the same Zitadel project ID is reused.
