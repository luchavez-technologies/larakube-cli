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
Traefik, Stalwart, FreeScout, Gitea, GlitchTip, Metabase, n8n,
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
  Same posture as Vaultwarden/OpenBao/NetBird.
- **Database**: Plex Commons Postgres via `ensureCommons(['postgres'])` →
  `allocateDatabase(DatabaseDriver::POSTGRESQL, 'zitadel', $dbPassword)` —
  same pattern as every other Plex-backed tool. Redis is optional/standalone-
  only per Zitadel's own docs; skip it for v1 (add only if a real performance
  need shows up — Postgres alone is Zitadel's fully-supported baseline).
- **Bootstrap**: `ZITADEL_FIRSTINSTANCE_ORG_HUMAN_*` env vars (verified
  against Zitadel's own `cmd/defaults.yaml`, not a blog post) create the
  default org + a human admin at first boot, with a LaraKube-generated known
  password — **built in Phase 1**. What's still deferred to Phase 2: the
  `Org.Machine` IAM_OWNER service-account + PAT path (needed for
  `mail:create --sso`'s API automation) — its exact PAT-output mechanism
  wasn't confirmed with the same certainty and has no live Zitadel to verify
  against this session, unlike Stalwart's live JMAP round-trip. Same shape as
  `vpn:init`'s `bootstrapVpnAuth()` for NetBird's `/api/setup`, once verified.
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

`sso:wire <tool> --remove` reverses both steps: deregister the OIDC
application in Zitadel (`zitadelDeleteOidcApp()`), then unset the client
env vars on the target tool — same `*:wire --remove` convention `vpn:wire`
(see `plans/active/vpn-wire.md`) establishes: every wiring command undoes
exactly what it patched, not just applies it one-directionally.

Five already-deployed tools support free OIDC and are the `oidcEnv()`
candidates: **Gitea, Grafana, NetBird, Vaultwarden, GlitchTip**.
n8n/Metabase/FreeScout's OAuth module all gate SSO behind a paid tier on *their* side regardless of what
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

1. [x] `SharedClusterService::SSO` + `ClusterTool::SSO`; `sso:init` deploy
   (dedicated `larakube-sso` namespace, Commons Postgres, `ghcr.io/zitadel/zitadel`
   `start-from-init --masterkeyFromEnv --tlsMode external`, `ZITADEL_FIRSTINSTANCE_ORG_HUMAN_*`
   bootstrap with a LaraKube-generated known password, manifest apply, rollout
   wait) and `--remove`. Every env var verified against Zitadel's own
   `cmd/defaults.yaml` (not inferred from blog posts) — the DB
   admin-vs-user-credential split points both at the same tenant-owner role,
   never the Commons' real postgres superuser.
   **Live-fix (prod droplet — three distinct bugs, found by iterating against
   the real cluster; unit tests can't catch these since they fake the pods):**
   1. **`permission denied to create database`.** Zitadel's init
      unconditionally runs a "verify database" step that issues `CREATE
      DATABASE` — even `init schema` does, confirmed live (it does NOT skip it,
      contrary to the docs' implication). The pre-provisioned, non-superuser
      tenant role lacks `CREATEDB`. Fix: `sso:init` grants `CREATEDB` to the
      `zitadel` role every deploy (restored `InteractsWithPlex::grantPostgresCreateDb()`;
      must run every deploy — a role recreation after `--remove` drops it).
      Verified live: with `CREATEDB`, `CREATE DATABASE` on the existing DB
      returns "already exists" (42P04), which Zitadel's restart-safe init
      tolerates, and init proceeds through the schema bootstrap.
      **(A mid-diagnosis detour wrongly concluded CREATEDB didn't help — that
      was a red herring caused by the privilege having been reset to `f` by a
      role recreation; re-granting + genuine-login test confirmed it IS the
      fix.)**
   2. **CrashLoop moved to setup: `PasswordComplexityPolicy.HasSymbol`.** The
      first-instance admin password was `Str::random(20)` (alphanumeric — no
      symbol), failing Zitadel's default policy (upper+lower+number+symbol).
      Fix: `generateZitadelAdminPassword()` guarantees one of each class;
      `isComplexEnoughForZitadel()` also **regenerates a stored non-compliant
      password** (so a pre-fix secret whose setup never completed self-heals).
      Guarded by a 100-iteration `SsoInitCommandTest` case.
   3. **Blade `@{{ }}` escape:** `admin@{{ $host }}` emitted the literal tag
      (`@{{` is Blade's verbatim escape) — now `{{ 'admin@'.$host }}`.
   4. **Console login 404 (`/ui/v2/login` → Not Found).** Zitadel v4 marks
      "Login V2" required on new instances and stops serving login itself,
      redirecting to a SEPARATE Next.js login container we don't run. Fix:
      `ZITADEL_DEFAULTINSTANCE_FEATURES_LOGINV2_REQUIRED=false` keeps the legacy
      V1 login bundled in the core container (served at `/ui/login`). It's a
      DEFAULTINSTANCE feature (instance-creation-time only), so an
      already-created instance needs `sso:init --remove` + re-init to pick it up.
   **Admin email (design fix, not a crash):** the first-instance admin was a
   synthetic hardcoded `admin@<sso-host>` — not a real mailbox, so Zitadel could
   never email it. Now `sso:init` takes `--admin-email` / prompts (default: the
   operator's global email, else `admin@<host>`), persists it in `sso-secrets`,
   and feeds `$adminEmail` to the manifest. Does NOT assume Stalwart. (Applies on
   a fresh instance only — existing installs need --remove + re-init.)
   **Automation token — FIXED (two bugs):** (a) the PAT output path env var was
   wrong — `ZITADEL_FIRSTINSTANCE_ORG_MACHINE_PATPATH` doesn't exist; the real
   key is FirstInstance-level `ZITADEL_FIRSTINSTANCE_PATPATH`, so Zitadel was
   silently writing nothing. (b) even once written, the Zitadel image is
   distroless (no shell/cat), so `captureMachinePat`'s `kubectl exec … cat`
   failed. Fix: point PATPATH at a shared `/machinekey` emptyDir (pod
   `fsGroup: 1000` so the non-root container can write it), add a tiny
   `busybox` **pat-reader sidecar** mounting it read-only, and read via
   `kubectl exec -c pat-reader -- cat`. Guarded by `SsoManifestYamlTest`
   (asserts the correct env var + the sidecar). Still needs a live redeploy to
   confirm the PAT is actually captured end-to-end.
   Also cleaned up the end-of-`sso:init` warning: removed the internal
   `plans/active/…` reference and the stale "not verified against a live
   instance" hedge — it's operator-facing output.

   **Zitadel outbound email → Stalwart (built).** Zitadel needs SMTP to send
   verification/password-reset/OTP mail. `mail:wire` can't do it: its SMTP env
   (`ZITADEL_DEFAULTINSTANCE_SMTPCONFIGURATION_*`) is a DEFAULTINSTANCE setting
   that only seeds a FRESH instance, so a `kubectl set env` on a running
   deployment is a no-op (and the env path has a known startup bug). SMTP is a
   runtime instance setting → configured via the Admin API. So `sso:init` now,
   after the PAT is captured AND if Stalwart is installed, calls
   `InteractsWithZitadelApi::zitadelConfigureSmtp()` (`POST /admin/v1/email/smtp`
   → `POST /admin/v1/email/{id}/_activate`) using the sender `mail:wire` already
   cached (the `mail-sender` secret) — no new prompts, never assumes Stalwart,
   fully non-fatal (falls back to a "set it in the console" note; the console
   has a Test button). NOT added to `smtpEnv()`/`mail:wire` (wrong shape).
   API shape verified against Zitadel docs, not a live instance — the add/
   activate paths + the `plain` auth nesting + implicit-TLS-on-465 vs STARTTLS
   are the bits to confirm on the live redeploy. Unit-tested (`SsoInitCommandTest`).

   **Valkey/Redis cache — researched, deliberately NOT added.** Zitadel v4 can
   use an external Redis/Valkey cache (`ZITADEL_CACHES_CONNECTORS_REDIS_*` +
   per-cache connector assignment), and Plex Commons has Valkey — so it's
   *possible* to reuse it. But it's an **experimental/beta** performance feature
   for multi-instance HA (with known circuit-breaker bugs), and this is a
   single-replica deploy on a $24 box. Postgres is the only required datastore
   (already Commons-backed). If a `--cache` opt-in is ever wanted, it reuses the
    Commons Valkey with an allocated logical-DB index like the Sheet
   pattern — but default stays off.
   Manifest uses an **initContainer** `init schema` + main `start-from-setup`
   (env shared via a `&zitadelEnv` anchor; image is distroless, no `sh -c`) —
   validated live through init + into setup. `SsoManifestYamlTest` renders +
   YAML-parses it, asserting the anchor resolves, the commands, and the real
   email.
2. [x] `InteractsWithZitadelApi` + `mail:create --sso` / `mail:delete` symmetry
   / `mail:show` SSO-status line. Unit-tested (`MailCommandsTest`), not yet
   live-verified.
3. [x] `ClusterTool::oidcEnv()` + `sso:wire <tool>` + `offerSsoWiring()` in
   `tool:add` — **scoped down to Grafana + Vaultwarden**, not all five
   originally listed. Both take OIDC config via plain env vars
   (`GF_AUTH_GENERIC_OAUTH_*` / `SSO_*`), so they fit the `oidcEnv()`
   "enum-owns-the-schema" shape cleanly. Gitea/NetBird/GlitchTip need
   CLI- or API-driven OIDC client registration on *their* side (not just env
   vars) and are deliberately deferred — `oidcEnv()` returns `null` for them
   for now; wiring them is a separate follow-up, not a loop over the same
   mechanism. `InteractsWithZitadelApi` gained `zitadelEnsureProject()` +
   `zitadelCreateOidcApp()` + `zitadelDeleteOidcApp()` (Zitadel's
   Management API v1 — the v2 project/app APIs weren't stable enough to
   build against confidently). `sso:wire <tool>` caches the registered
   app's client id/secret in a per-tool `sso-app-{tool}` Secret so re-runs
   reuse the existing OIDC client instead of registering a new one every
   time; `--remove` deregisters it and unsets the tool's env vars. Unit-tested
   (`SsoWireCommandTest`), not yet live-verified against a real Zitadel.
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
