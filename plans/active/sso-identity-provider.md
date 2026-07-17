# Plan: Self-hosted identity provider (`sso:init`) + `mail:create` as the onboarding entry point

## 🎯 Objective

`sso:init` — deploy **Zitadel** as a cluster-wide OIDC/SAML identity provider,
in its own `larakube-sso` namespace (matches `larakube-vault`/`larakube-secrets`
/`larakube-vpn` — the most security-sensitive tools get their own namespace,
not `larakube-shared`). Then make `mail:create` the single command that
onboards a person everywhere: create the Stalwart mailbox **and**, optionally,
the matching Zitadel identity, in one call. Off-boarding via `mail:delete`
mirrors it — one command, gone everywhere federated.

## 🔍 Why Zitadel (picked over Authentik, Keycloak, Authelia)

Researched against the actual constraint: a single modest VPS already running
Traefik, Stalwart, FreeScout, Gitea, GlitchTip, Metabase, n8n, Baserow,
NetBird, Grafana+Loki+Prometheus, Vaultwarden, and the project's own app pods.

| | License | RAM (practical) | Provisioning API |
|---|---|---|---|
| **Zitadel** ✅ | AGPLv3 (2025 switch from Apache 2.0 — copyleft, but only bites on modified-source redistribution, which LaraKube never does) | **~100MB** — single Go binary; a full test deployment runs on 512MB total | Native gRPC+REST v2 Management API, first-class user CRUD, M2M service accounts — built for exactly this kind of automation |
| Authentik | MIT core | ~1–2GB (dropped Redis in 2025.10, now Postgres-only) | SCIM source+provider, full REST API |
| Keycloak | Apache 2.0 | ~1.25GB+ at idle (JVM) | Full admin REST API |
| Authelia | Apache 2.0 | Tiny | **Not applicable** — a forward-auth proxy reading a flat user file/LDAP, not a real identity platform with a provisioning API. Wrong shape for the `mail:create` integration below. |

Keycloak ruled out on footprint alone. Authelia ruled out on shape — it can't
be the thing `mail:create` provisions into. Between Zitadel and Authentik it's
a real tradeoff (Authentik is the more battle-tested choice, referenced
directly in Grafana's/Gitea's/GlitchTip's own OIDC docs); Zitadel wins here
specifically because of the resource constraint and its API being
first-class rather than SCIM-bolted-on.

## 🧱 Design

### The provider

- **`SharedClusterService::SSO`** + **`ClusterTool::SSO`** ('sso') — same
  dual-registration every shared tool has.
- **Namespace: `larakube-sso`** (dedicated, not `larakube-shared`) — this is
  the one thing that, if compromised, compromises everything federated to it.
  Same posture as Vaultwarden/Infisical/NetBird.
- **Database**: Plex Commons Postgres via `ensureCommons(['postgres'])` →
  `allocateDatabase(DatabaseDriver::POSTGRESQL, 'zitadel', $dbPassword)` —
  same pattern as every other Plex-backed tool. Redis is optional/standalone-
  only per Zitadel's own docs; skip it for v1 (add only if a real performance
  need shows up — Postgres alone is Zitadel's fully-supported baseline).
- **Bootstrap**: Zitadel supports a headless first-instance config (org +
  admin user + a machine user with a PAT, provisioned at startup with no
  dashboard click) — same shape as `vpn:init`'s `bootstrapVpnAuth()` already
  does for NetBird's `/api/setup`. **Verify the exact config format at
  implementation time** — this is the one thing in this plan I haven't
  hand-verified against a running instance yet.
- **Context/teardown**: built on `DeploysClusterTool` from day one —
  `resolveToolContext()` + `removeResources()`, same as every tool fixed this
  week. A brand-new tool has no excuse to reintroduce that bug class.

### The `mail:create` ↔ Zitadel link

New `App\Traits\InteractsWithZitadelApi` (mirrors `InteractsWithStalwartApi`
built this session): `zitadelCreateUser()`, `zitadelDeleteUser()`,
`zitadelFindUserByEmail()` — calling Zitadel's Management API over HTTPS with
the bootstrapped machine PAT (same direct-HTTPS-from-the-CLI shape
`bootstrapVpnAuth()` already uses for NetBird, not a `kubectl exec` proxy —
Zitadel's API is meant to be reached over its public ingress).

- **`mail:create`** gains `--sso` (and an interactive prompt when SSO is
  installed): after the Stalwart mailbox is created, optionally create a
  matching Zitadel human user with the same email, and print both sets of
  credentials together. Skippable — a shared `noreply@` mailbox `mail:wire`
  uses doesn't need a login identity.
- **`mail:delete`** mirrors it: offer to also deactivate the matching Zitadel
  user, so off-boarding is symmetric — one command, not two people to
  remember to remove.
- **`mail:show <email>`** (built this session) gains a line noting whether an
  SSO identity exists for that address, once `sso:init` is installed.

### Federating the rest of the stack: `sso:wire`, the OIDC sibling of `mail:wire`

Not `mail:wire` growing a new mode — that command's whole shape is "send
outbound email," and reusing it for "configure this tool's login screen"
would be a confusing overload. Instead: `ClusterTool::smtpEnv()` already
exists and drives `mail:wire` + `tool:add`'s auto-offer (`offerMailWiring()`);
the natural sibling is a new **`ClusterTool::oidcEnv()`** driving a new
**`sso:wire <tool>`** + a matching `offerSsoWiring()` hook in `tool:add`. Same
shape, same discoverability (`tool:add gitea` → "wire it to Stalwart?" *and*
"wire it to SSO?").

**One real asymmetry vs `mail:wire`, worth designing for up front**: SMTP
wiring is symmetric — the same 5 fields (host/port/user/pass/from) map onto
any tool's env vars, no interaction with Stalwart needed beyond credentials
already cached. OIDC wiring is a **two-step** operation, because Zitadel has
to know about a client before it'll authenticate for it:

1. `sso:wire gitea` first calls `InteractsWithZitadelApi::zitadelCreateOidcApp()`
   to **register Gitea as an OIDC application inside Zitadel** — this mints a
   `client_id`/`client_secret` and needs Gitea's *own* callback URL (each
   tool has a different OIDC callback path convention — `oidcEnv()` has to
   know it, the same way `smtpEnv()` knows each tool's env var names).
2. *Then* patch that `client_id`/`client_secret`/issuer-URL into Gitea's own
   env vars via the existing `set env --from=secret` mechanism `mail:wire`
   already uses.

Five already-deployed tools support free OIDC and are the `oidcEnv()`
candidates: **Gitea, Grafana, NetBird, Vaultwarden, GlitchTip**.
Infisical/n8n/Baserow/Metabase/FreeScout's OAuth module and the planned
Mattermost all gate SSO behind a paid tier on *their* side regardless of what
IdP you point at them — Zitadel doesn't change that, it just means the five
free ones become one login instead of five.

## 🛠 Commands

```bash
larakube sso:init [environment] [--context=] [--domain=] [--vpn-only] [--remove]
larakube tool:add sso
larakube mail:create joanna@example.com --sso        # mailbox + SSO identity, one call
larakube mail:delete joanna@example.com               # offers to deactivate her SSO identity too
larakube sso:wire gitea                                # register + wire Gitea's OIDC login
```

## ♻️ Reuse

- `InteractsWithPlex` — `ensureCommons()`, `allocateDatabase()`,
  `buildDropTenantSql()`, `commonsAdminClient()`.
- `DeploysClusterTool` — `resolveToolContext()`, `removeResources()`.
- `VpnInitCommand::bootstrapVpnAuth()` as the direct-HTTPS-bootstrap
  reference implementation — same retry-until-TLS-ready shape (`waitForTls()`)
  applies here.
- `InteractsWithStalwartApi` as the shape reference for the new
  `InteractsWithZitadelApi` (kubectl-exec-vs-HTTPS decision aside, same
  "typed helper methods over a JSON API" pattern).
- `ClusterTool::smtpEnv()` + `MailWireCommand` + `ToolAddCommand::offerMailWiring()`
  as the direct pattern reference for `oidcEnv()` + `SsoWireCommand` +
  `offerSsoWiring()` — same enum-owns-the-schema shape, same auto-offer hook.

## 🚦 Phases

1. [ ] `SharedClusterService::SSO` + `ClusterTool::SSO`; `sso:init` deploy
   (dedicated namespace, Commons Postgres, headless bootstrap, manifest apply,
   rollout wait) and `--remove`.
2. [ ] `InteractsWithZitadelApi` + `mail:create --sso` / `mail:delete` symmetry
   / `mail:show` SSO-status line.
3. [ ] `ClusterTool::oidcEnv()` + `sso:wire <tool>` + `offerSsoWiring()` in
   `tool:add` — federate Gitea, Grafana, NetBird, Vaultwarden, GlitchTip
   (OIDC application registration in Zitadel + env wiring per tool).
4. [ ] Docs page (the licensing table above, the Zitadel-vs-Authentik
   tradeoff, which tools are and aren't SSO-capable for free).

## ✅ Verification

- `sso:init` (local) → reach the Zitadel console with the bootstrapped admin,
  no manual dashboard setup required.
- `mail:create test@example.com --sso` → confirm a Stalwart mailbox **and** a
  Zitadel user both exist for the same email, and both sets of credentials
  print together.
- `mail:delete test@example.com` → confirm both the mailbox and the Zitadel
  user are gone (or the Zitadel user is at least deactivated).
- `sso:init production` (no explicit `--context`) → confirm it resolves the
  environment's saved cloud target, not the ambient kube-context.
- Resource check on the actual droplet: confirm Zitadel's pod sits near the
  ~100MB figure in practice, not just on paper, alongside everything else
  already running.

## ⚠️ Risks / open questions

- **Headless bootstrap format unverified** — needs a real pass against a
  running Zitadel instance before Phase 1 is "done," same rigor as this
  session's live Stalwart JMAP verification, not assumed from docs alone.
- **AGPLv3** — a real license, stricter than MIT in principle. LaraKube only
  ever runs the official unmodified image, so the copyleft trigger
  (modify + redistribute as a service) shouldn't apply — but worth a plain
  sentence about this in the docs page rather than leaving it unstated.
- **`--sso` on `mail:create` couples two tools' lifecycles.** If Zitadel is
  down when `mail:create --sso` runs, decide: fail the whole command, or
  create the mailbox and warn that the SSO side needs a retry? Lean toward
  the latter — a mailbox is more urgently needed than a login, and the retry
  path (`mail:create` again, or a dedicated re-sync) should stay simple.
- **Phase 3 (federating Gitea/Grafana/etc.) is real per-tool work**, not a
  loop — each has its own OIDC claim-mapping quirks. Don't let "SSO is done"
  get declared after Phase 1/2; the mail:create link is necessary but not the
  whole picture.
