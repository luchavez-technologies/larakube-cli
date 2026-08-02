# Plan: SSO gate hardening — repair `--vpn-only`, add group-based authorization

**Status:** Not started. Two independent items; #1 is urgent, #2 is a feature.
**Context:** ForwardAuth SSO shipped (docs/decisions/0006). Deploying it uncovered
that LaraKube's Traefik never installed its CRDs, which had *also* silently
broken `--vpn-only` on every existing cluster.

---

## 1. Repair `--vpn-only` on already-provisioned clusters (URGENT)

### Problem
`--vpn-only` renders an ip-allow-list `Middleware` and annotates the tool's
Ingress with `larakube-shared-<tool>-vpn-only@kubernetescrd`. Until the Traefik
CRD fix, **`kubectl apply` of that Middleware failed** ("the server doesn't have
a resource type middleware"), so:

- the annotation points at a middleware that does not exist;
- Traefik's behaviour for a **missing** middleware reference is to fail the
  router (503) rather than serve it unprotected — but this **must be verified
  per cluster**, because a Traefik that never loaded the CRD provider may have
  ignored the annotation entirely and served the tool **wide open**;
- either way every `--vpn-only` tool is in a broken/unverified state.

`traefik:setup {env}` now installs the CRDs + `--providers.kubernetescrd`, so the
mechanism works — but existing Middlewares still have to be (re-)created.

### Steps
1. **Audit command — `vpn:audit {environment}`** (read-only, ship first):
   - list every Ingress in the cluster whose
     `traefik.ingress.kubernetes.io/router.middlewares` annotation references a
     `*-vpn-only@kubernetescrd` middleware;
   - for each, check whether that `Middleware` object actually exists
     (`kubectl get middleware <name> -n <ns>`);
   - print a table: tool · ingress · middleware · **present/MISSING** · host;
   - for each MISSING row, probe the public host from outside the cluster and
     report the status code, so the operator learns whether it was **exposed**
     (200) or **broken** (503). This is the security-relevant answer.
2. **Repair path:** for each MISSING, re-create the middleware. Cheapest correct
   fix is re-running the tool's `:init` with `--vpn-only` (it calls
   `ensureVpnMiddleware`). Consider a `vpn:repair {environment}` that iterates
   the audit's MISSING rows and calls `ensureVpnMiddleware(tool, kubectl)`
   directly — no full redeploy, no downtime.
3. **Prevent recurrence:** `ensureVpnMiddleware()` must **fail loudly** when the
   Middleware CRD is absent, exactly like `sso:wire`'s pre-flight
   (`traefikMiddlewareCrdExists()` in `SsoWireCommand` — reuse it, or promote it
   to `InteractsWithTraefik`). Today it applies and returns as if it worked.
4. **Test:** feature test asserting `{tool}:init --vpn-only` aborts with the
   "re-provision Traefik" guidance when the CRD is missing.

### Done when
`vpn:audit production` reports zero MISSING, and a tool wired `--vpn-only` really
does return 403 to a non-VPN IP.

---

## 2. Authorization for the SSO gate (`--allowed-groups`)

### Problem
The shared OAuth2-Proxy runs with `--email-domain=*`: **any** Zitadel user in the
instance can pass the gate. That is authentication, not authorization. Fine for
one trusted operator, wrong the moment the Zitadel instance has more users than
the tool should admit.

### Design
Zitadel asserts project roles in tokens under
`urn:zitadel:iam:org:project:roles` (an object keyed by role name), which is
**not** a flat list, so oauth2-proxy's `--oidc-groups-claim` may not read it
directly. Verify first; two viable routes:

- **A. Zitadel Action / custom claim** — map project roles into a flat `groups`
  claim, then `--oidc-groups-claim=groups` + `--allowed-groups=<role>`.
- **B. `--allowed-groups` against the raw claim** if oauth2-proxy can traverse it
  (test with a real token — do not assume).

Also required: the roles must actually be asserted into the token —
`zitadelCreateOidcApp` currently sets `idTokenUserinfoAssertion=true` but **not**
`idTokenRoleAssertion` / `userInfoRoleAssertion`. Those need enabling for the
proxy app, and a project role (e.g. `larakube-user`) must exist and be granted.

### Steps
1. Add a project role + grant flow to `sso:init` (or a `sso:role` command).
2. Set `idTokenRoleAssertion` on the proxy's OIDC app.
3. Add `--allowed-groups=` to the proxy manifest, driven by a new
   `sso:wire --allowed-group=` option (default: unset = current behaviour, so
   this is not a breaking change).
4. Decode a real id_token from the live proxy to confirm the claim shape
   **before** wiring the flag — this class of bug (Vaultwarden audience,
   Documenso well_known) has bitten twice already.
5. Document in ADR 0006 (its "Non-goals" §3 explicitly defers this).

### Done when
A Zitadel user *without* the role gets denied at the gate, and one with it passes.

---

## Notes
- Do **not** gate a tool that has native OIDC — see ADR 0006 §Non-goals.
- `--trusted-proxy-ip` and `--code-challenge-method=S256` are already fixed in the
  proxy manifest; they are not part of this plan.
