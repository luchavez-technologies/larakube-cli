# 0006 — Centralized OAuth2-Proxy (ForwardAuth) for tools with no free native OIDC

**Status:** Accepted (2026-07-26)

## Context

Some tools have **no free native OIDC login**, so `sso:wire`'s env-var mechanism
(docs/decisions/0004 neighbourhood) has nothing to configure:

- **SendRec (`record`)** — no OIDC in the OSS edition.
- **Mattermost** (was previously considered) — OpenID Connect required a **paid**
  plan (Professional/Enterprise). Mattermost is now removed from LaraKube in
  favour of Matrix/Synapse, which has free native OIDC.

The options for gating these behind Zitadel were:

| | Deployment | Licensing (verified from source) | Fit |
|---|---|---|---|
| **Pomerium Ingress Controller** | 2nd ingress controller | Apache 2.0 (core *and* ingress-controller) — genuinely free self-hosted | Rejected: Traefik already owns :80/:443; needs a 2nd LoadBalancer (DOKS cost) or Traefik→Pomerium chaining, plus migrating ingresses to `ingressClassName: pomerium`. Embedded Envoy ⇒ ~100 MB. |
| **Per-app OAuth2-Proxy** (first implementation) | 1 pod per wired tool | MIT | Rejected: ~20 MB *per tool*, one Zitadel app per tool, and it routed the ingress backend at the proxy (proxy mode). |
| **Centralized OAuth2-Proxy + Traefik ForwardAuth** | 1 shared pod | MIT | **Chosen.** |

Licensing was checked because prior assumptions in this repo were wrong (see
[[project_vaultwarden_sso_mainline]]). **Nothing here is paywalled.** Note
**Zitadel itself is AGPL v3** (not Apache 2.0) — unlimited self-hosting with no
user caps or gated features, but if LaraKube Cloud ever ships a *modified*
Zitadel over a network, AGPL §13 requires publishing those modifications.

## Decision

Deploy **one** OAuth2-Proxy (`sso-proxy`, in `larakube-shared`) and attach it to
individual tools with a **Traefik ForwardAuth Middleware**, opt-in per Ingress.

- **One Zitadel OIDC app** ("LaraKube SSO Proxy") with a **single, permanent
  redirect URI** `https://auth.<apex>/oauth2/callback`. Adding a gated tool never
  touches Zitadel again.
- `--cookie-domain=.<apex>` + `--whitelist-domain=.<apex>` ⇒ one session shared
  across every gated subdomain (real SSO between gated tools).
- `--upstream=static://202` — the proxy authenticates only; it is not in the data
  path for application traffic.
- Middleware lives in the *tool's* namespace and addresses the proxy by FQDN, so
  Traefik's `allowCrossNamespace` is **not** required.

### Non-goals / explicit limits

1. **A proxy is a gate, not a login.** OAuth2-Proxy proves *who* you are at the
   edge; the app still has its own account system unless it reads trusted headers
   (`X-Auth-Request-Email`). Grafana (`auth.proxy`) and Gitea (`REVERSE_PROXY`)
    can; **Mattermost could not**, so gating it meant Zitadel login *plus* a
    Mattermost login and likely broken mobile/desktop clients. **Mattermost was
    therefore NOT a ForwardAuth candidate — use the Matrix/Synapse engine, which
    has free native OIDC.**
2. **Never gate a tool that already has native OIDC** (Grafana, Vaultwarden,
   Documenso, Outline) — that is two logins. `usesForwardAuth()` is the switch and
   must stay false for them.
3. **Authentication, not authorization.** `--email-domain=*` admits *any* Zitadel
   user. For real authz, add `--allowed-groups` fed by Zitadel project roles.
4. **Requires a multi-label apex domain.** Cookies cannot be scoped to a
   single-label TLD (e.g. `.test`), so cross-host sessions do not work on a local
   `*.test` cluster; the wire command warns and proceeds.

## Consequences

- ✅ Zero impact on what already works: gating is a per-Ingress annotation.
  Native-OIDC tools and deployed Laravel apps are untouched.
- ✅ One ~20–30 MB pod and one Zitadel app regardless of how many tools are gated.
- ⚠️ **Requires Traefik's `Middleware` CRD, which LaraKube was never installing.**
  LaraKube ships its own Traefik (not k3s' bundled one) and its manifest had
  `--providers.kubernetesingress` but **not** `--providers.kubernetescrd`, and no
  CRDs — so every Middleware apply failed with *"the server doesn't have a
  resource type middleware"*. This had already broken **`--vpn-only`** cluster-wide;
  ForwardAuth just surfaced it. Fixed in `k8s/traefik-crds.blade.php` (all ten
  traefik.io CRDs, permissive schemas) + the provider flag + an RBAC typo
  (`middlewaress`). `sso:wire` now pre-flights the CRD and says how to fix it
  rather than half-wiring. Re-provision Traefik before gating anything.
- ❌ Traefik-specific. A non-Traefik cluster (nginx `auth-url`, EKS/AKS) needs a
  different attachment; there is no "standard" Ingress annotation for external
  auth.
- Pin: `quay.io/oauth2-proxy/oauth2-proxy` — keep current (v7.6.0 shipped with
  since-patched CVEs; see the release notes when bumping).
