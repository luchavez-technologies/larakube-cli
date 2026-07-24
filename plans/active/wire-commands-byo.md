# Plan: `*:wire` commands should work with EXTERNAL backends, not only the bundled ones

## 🎯 Objective

`mail:wire`, `vpn:wire`, and `sso:wire` each assume the LaraKube-deployed
backend is present (Stalwart / NetBird / Zitadel) and hard-error otherwise. But
a team may already use **Brevo/SES/Gmail** for SMTP, **Tailscale/WireGuard/a
corporate VPN** for the overlay, or **Authentik/Keycloak/Auth0/Okta/Google
Workspace** as their IdP. The wiring these commands do — pointing a tool's env
at an SMTP server / restricting an ingress to a peer CIDR / patching a tool's
OIDC config — is valuable regardless of *whose* backend it is. Give each a
**bring-your-own (BYO) mode**: when the local backend isn't installed (or the
user passes explicit connection details), wire the tool to the external one
instead of refusing.

## 🧩 The shared shape

Each command already has the "apply to the tool" half (the `smtpEnv()` /
Middleware / `oidcEnv()` patching). What's backend-specific is the "where do the
connection details come from" half. Today that's implicitly "the bundled tool."
The redesign splits it into two source modes per command:

- **Managed** (today's behavior): the bundled backend is installed → auto-derive
  everything (Stalwart host + app-password; NetBird's peer CIDR; Zitadel app
  registration via its API).
- **BYO / external**: no bundled backend (or explicit flags supplied) → take the
  connection details from flags/prompts and wire the tool to them. No local
  backend required.

Resolution rule (uniform across all three): **explicit BYO flags win →
otherwise, if the bundled backend is installed use managed mode → otherwise
prompt for BYO details** (instead of the current hard error).

## 🔧 Per-command design

### `mail:wire` (SMTP consumer)
- Managed: derives `mail.<host>:465` + a validated Stalwart app-password
  (SMTP-AUTH check inside the pod).
- **BYO**: `--smtp-host --smtp-port --smtp-user --smtp-password --smtp-from`
  (or a provider preset — `--provider=brevo|ses|mailgun|gmail` filling host/port
  defaults, reusing `RelayProvider` from `mail:relay`). Wire the SAME `smtpEnv()`
  schema with these values. Skip the in-pod SMTP-AUTH probe (no Stalwart pod);
  optionally do an external SMTP-AUTH check from a throwaway job, or just warn.
- Relationship to `mail:relay`: `mail:relay` points **Stalwart's own outbound**
  at a relay; `mail:wire --provider=…` points **a tool** directly at an external
  SMTP. Both can reuse `RelayProvider`'s host/port/preset knowledge — extract it
  to a shared `SmtpEndpoint` value object.

### `vpn:wire` (ingress IP-allowlist)
- Managed: uses NetBird's default peer CIDR `100.64.0.0/10`.
- **BYO**: `--cidr=` (repeatable) — the allowed source range(s) of whatever VPN
  the team runs. Tailscale happens to also use `100.64.0.0/10` (CGNAT), so the
  default already fits it; WireGuard/corporate use custom ranges. The Middleware
  + ingress mechanics are unchanged — only the `sourceRange` becomes a
  parameter. This is the **smallest** change of the three: `vpn:wire` doesn't
  actually need NetBird at all, just Traefik + a CIDR.
- `ClusterTool::vpnMiddlewareTarget()` stays; the CIDR moves from a hardcoded
  template value to a passed-in one (default NetBird's).

### `sso:wire` (OIDC consumer)
- Managed: registers an OIDC app in Zitadel via its Management API + machine PAT,
  then patches the tool's `oidcEnv()`.
- **BYO**: `--issuer --client-id --client-secret` — skip the Zitadel-specific
  app registration (every IdP's registration API differs, and most are done in
  their own console), and just patch the tool's `oidcEnv()` with the supplied
  OIDC client. The `oidcEnv()` schema is already provider-agnostic (issuer +
  client id/secret + the tool's env var names), so BYO wiring is a straight
  reuse minus the registration step. Print the redirect URI the operator must
  register in *their* IdP (we already compute it).
- The peer-trust note (`oidcPeerTrustNote()`) generalizes: for BYO it names the
  operator's IdP generically instead of Zitadel.

## ♻️ Reuse / new seams

- Extract `SmtpEndpoint` (host/port/tls/from) from `RelayProvider` + `mail:wire`
  so managed and BYO share one representation.
- `ClusterTool::smtpEnv()` / `oidcEnv()` / `vpnMiddlewareTarget()` are already
  the tool-side schemas — unchanged; only the *source* half gains a BYO branch.
- `DeploysClusterTool` context/failure discipline throughout.

## 🚦 Phases

1. [ ] `vpn:wire --cidr` (smallest, self-contained — proves the "managed default,
   BYO override" pattern with no external API).
2. [ ] `mail:wire` BYO (`--smtp-*` / `--provider=`), shared `SmtpEndpoint`.
3. [ ] `sso:wire` BYO (`--issuer/--client-id/--client-secret`, skip registration).
4. [ ] Docs: a "using an external provider" section per tool + the resolution
   rule.

## ⚠️ Risks / open questions

- **Validation gap in BYO mode.** Managed mode validates (SMTP AUTH against the
  pod, Zitadel confirms the app). BYO can't reach into someone else's backend
  the same way — decide per command: external probe (extra infra) vs. wire-and-
  warn. Lean warn + a `*:check`-style follow-up.
- **Scope creep vs. `tool:add` auto-offer.** `offerMailWiring()`/`offerSsoWiring()`
  fire when the bundled backend is present; BYO is opt-in via flags, so the
  auto-offer stays managed-only. Keep it that way — don't prompt for BYO details
  unbidden during `tool:add`.
- **Is BYO worth it for all three, or just mail?** SMTP-BYO is clearly common
  (lots of teams use Brevo/SES). VPN-BYO is trivial (just a CIDR) so cheap to
  add. SSO-BYO is the most speculative — most self-hosters who want SSO will run
  the bundled Zitadel. Could ship 1+2 and defer 3 until asked.
