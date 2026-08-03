# Plan: Web UI for Stalwart (`webmail:init` → Bulwark) — the mail counterpart to `chat:init`

## 🎯 Objective

Give workmates a browser-based mailbox — the same way `chat:init` gives them
team chat — instead of only IMAP/SMTP in Apple Mail / Thunderbird. LaraKube's
Stalwart deploys deliberately WITHOUT webmail (self-contained, no bundled UI);
this adds one on top, reusing the mailbox each `mail:create` already makes.

Deploy **Bulwark** — a modern, JMAP-native webmail client purpose-built for
Stalwart — as a first-class tool in `larakube-shared`, next to the Stalwart it
talks to.

## 🔍 Why Bulwark (over Roundcube / SnappyMail) — researched, not assumed

Stalwart is now feature-complete and speaks JMAP, IMAP, SMTP, CalDAV, CardDAV,
WebDAV. The webmail question is really "IMAP client vs JMAP client":

| | Protocol to Stalwart | License | Storage it needs | Fit for LaraKube's Stalwart |
|---|---|---|---|---|
| **Bulwark** ✅ | **JMAP** (Stalwart's native, modern protocol) — talks to the SAME HTTP endpoint the CLI already uses | AGPL-3.0 (same posture as Zitadel — we run the unmodified image, copyleft only bites on modified-source redistribution, which LaraKube never does) | A small PVC for its own admin/settings config — **no database, no Commons** | Built *specifically* for Stalwart. Headless deploy via `JMAP_SERVER_URL`. OAuth2/OIDC support → drops straight into the `sso:wire` work. |
| Roundcube | IMAP + SMTP | GPL | **Needs a DB** (SQLite/Postgres/MySQL) for prefs/contacts | Would connect over `stalwart-mail:993` (IMAPS) — TLS-cert-name mismatch on the internal service DNS, so needs `verify_peer=false` gymnastics. Mature but heavier. |
| SnappyMail | IMAP + SMTP | AGPL | Filesystem prefs (no DB) | Lightweight, but IMAP-only — same internal-TLS mismatch, and it ignores everything JMAP-native Stalwart does best. |

Bulwark wins on **native protocol** (one HTTP endpoint, no IMAPS-cert
workaround), **zero extra datastore**, and **first-class OIDC** that plugs into
the Zitadel `sso:wire` mechanism already built. Roundcube is the more
battle-tested project in absolute terms, but every advantage it has is about
IMAP servers generically — against a JMAP-native Stalwart it's the wrong shape.

Verified facts (July 2026): image `ghcr.io/bulwarkmail/webmail:latest`, Next.js,
listens on **:3000**, AGPL-3.0, active (releases ~every 2 days). Headless config
is a first-class supported mode.

## 🧱 Design

### Shape: a first-class `webmail` tool, hard-coupled to mail

Mirrors the `chat:init` framing: `webmail:init` is its own tool
(like `chat:init`/`desk:init`), gets `tool:add` discoverability, `vpn:wire`,
and `sso:wire` for free — but it **refuses to deploy unless Stalwart is
installed** (`isMailInstalled()`), because a webmail with no mail server is
meaningless. Lives in `larakube-shared` beside Stalwart (low sensitivity — it
stores no mail, only its own config; same namespace as chat/desk).

*(Alternative considered: a `mail:web` subcommand under the mail family. Rejected
— it would lose the ClusterTool/SharedClusterService machinery, `vpn:wire`, and
the `sso:wire` auto-offer, all of which webmail genuinely wants.)*

- **`SharedClusterService::WEBMAIL`** (`value: 'webmail'`, host prefix
  `webmail`) + **`ClusterTool::WEBMAIL`** — the dual-registration every shared
  tool has. Host: `webmail.{tld}` local, prompted+persisted `webmail.{domain}`
  cloud.
- **`InteractsWithBulwark`** trait (mirrors `InteractsWithChat`/`InteractsWithDesk`):
  `bulwarkNamespace()`, `bulwarkKubectl()`, `isBulwarkInstalled()`,
  `resolveBulwarkHostReadOnly()`, `bulwarkAccess()`.
- **`DeploysClusterTool`** from day one — `resolveToolContext()` +
  `removeResources()`, and `ensureVpnMiddleware()` for `--vpn-only` (free now
  that the VPN-middleware guard is shared).

### The manifest (`k8s/webmail/bulwark.blade.php`)

- Deployment `webmail-bulwark`, image `ghcr.io/bulwarkmail/webmail:latest`,
  containerPort 3000.
- **Headless config via env** (skips the interactive setup wizard):
  - `JMAP_SERVER_URL = https://{mailHost}` — **the PUBLIC Stalwart host, NOT
    the internal `stalwart.larakube-shared.svc` DNS.** This is a correctness
    point, not a preference: the browser connects to JMAP *directly* (see CORS
    below), so the URL must be one the browser can resolve. Server-side SSR
    calls hairpin out through the ingress and back — acceptable.
  - `SESSION_SECRET_FILE` → mounted from a generated `webmail-secrets` Secret
    (stable across re-runs, like every other tool's secret handling).
  - `ADMIN_CONFIG_DIR` / `SETTINGS_DATA_DIR` → a small PVC (1Gi) so the admin
    config + per-user settings-sync survive restarts. `ADMIN_CONFIG_READONLY=true`
    after first boot for immutable-infra behaviour (optional, phase 2).
  - Branding: `APP_NAME`, `LOGIN_COMPANY_NAME` — **must honour the
    "LaraKube CLI" naming rule and never leak a company name**; default to a
    neutral "Webmail" unless the operator overrides via a flag.
- Service `webmail-bulwark` (ClusterIP :3000) + ingress at `webmail.{host}`
  (TLS terminated at Traefik, same as every other tool). `--vpn-only` supported.

### The CORS integration (the one real gotcha)

Because the browser talks to JMAP directly, **Stalwart must CORS-allow the
webmail origin** — this is the single most common Bulwark↔Stalwart setup
failure (there's an open Bulwark discussion and a Stalwart support thread about
exactly this). Stalwart's knob is **`http.permissive-cors = true`** (accept all
origins). LaraKube's Stalwart is self-contained (embedded RocksDB, no ConfigMap),
so this setting lives in Stalwart's store, set one of two ways:

1. **Via Stalwart's settings API** during `webmail:init` — the CLI already
   authenticates to Stalwart's HTTP API (`InteractsWithStalwartApi`); add a
   `stalwartSetConfig('http.permissive-cors', 'true')` helper. *(Verify the
   exact settings-write endpoint against a live pod — same rigor as the JMAP
   round-trip already done this session; the read side is known, the write
   side needs confirming.)*
2. Fallback: print the one-line manual instruction (Settings › Network › HTTP
   › Security → enable permissive CORS) if the API write isn't available.

**Security note to surface, not bury:** `permissive-cors` is all-origins (`*`),
not a scoped allowlist (Stalwart doesn't expose per-origin allowlisting as of
this research). This is acceptable *specifically because* JMAP auth is
Bearer/Basic, not cookie-based — `*` CORS doesn't open a CSRF-style hole the
way it would for a cookie-authed app. Document it plainly; if Stalwart adds
scoped origins later, prefer `https://{webmailHost}` only.

### Auth: basic-against-Stalwart first, OIDC second

- **Phase 1 — basic auth**: a workmate logs into Bulwark with the exact
  mailbox email + password `mail:create` handed them; Bulwark passes it through
  to JMAP. Zero extra moving parts, works the instant Stalwart + Bulwark are up.
- **Phase 2 — OIDC via `sso:wire`**: add a **`ClusterTool::WEBMAIL` arm to
  `oidcEnv()`** (`OAUTH_ENABLED`/`OAUTH_CLIENT_ID`/`OAUTH_CLIENT_SECRET`/
  `OAUTH_ISSUER_URL`) so `sso:wire webmail` registers Bulwark in Zitadel and
  patches its env — making it the **third** env-var-OIDC tool after Grafana and
  Vaultwarden. **Caveat that makes this its own phase:** OIDC login is only
  end-to-end if *Stalwart* also trusts the same Zitadel (JMAP has to accept the
  OIDC identity, not just Bulwark's login screen). That's Stalwart-side OIDC
  config on top of Bulwark-side — real work, not a one-liner, hence deferred.

## 🛠 Commands

```bash
larakube webmail:init [environment] [--context=] [--domain=] [--vpn-only] [--remove]
larakube tool:add webmail                 # discoverable alongside chat/desk/sso
larakube sso:wire webmail                 # (phase 2) OIDC login via Zitadel
larakube vpn:wire webmail                 # restrict to NetBird peers (free via shared guard)
```

`mail:show` / `mail:create` grow a line surfacing the webmail URL once Bulwark
is installed ("Your team can also use webmail at https://webmail.host"), the
same way `mail:create` already prints IMAP/SMTP client setup.

## ♻️ Reuse

- `DeploysClusterTool` — `resolveToolContext()`, `removeResources()`,
  `ensureVpnMiddleware()` (VPN-only for free).
- `ClusterTool::oidcEnv()` + `SsoWireCommand` + `offerSsoWiring()` — Bulwark is
  the third `oidcEnv()` tool; no new wiring mechanism.
- `SharedClusterService` reconcile treatment — ingress re-pointed on `up`/`config:tld`.
- `InteractsWithStalwartApi` — extend with the config-write helper for CORS.
- `InteractsWithChat`/`InteractsWithDesk` as the trait shape reference (no
  Commons, small PVC, `larakube-shared`).

## 🚦 Phases

1. [x] `SharedClusterService::WEBMAIL` + `ClusterTool::WEBMAIL`; `webmail:init`
   deploy (Bulwark manifest `k8s/webmail/bulwark.blade.php` — PVC + Deployment +
   Service, headless env config via `JMAP_SERVER_URL=https://{mailHost}`,
   `webmail-secrets`/`SESSION_SECRET_FILE`, ingress at `webmail.{host}`,
   `--vpn-only` via `ensureVpnMiddleware()`) and `--remove`. `InteractsWithBulwark`
   trait. Refuses unless `isMailInstalled()` AND a mail host is configured. Flips
   Stalwart `http.permissive-cors` via a new
   `InteractsWithStalwartApi::stalwartSetConfig()` — **best-effort / NON-FATAL**:
   on failure it prints the exact manual step (Settings › Network › HTTP ›
   Security → permissive CORS) and still exits 0, because the settings-write
   surface has shifted across Stalwart versions and this one call hasn't been
   round-tripped live yet (**still the one thing to verify against a real pod** —
   everything else is unit-tested). Basic auth (workmates log in with their
   `mail:create` mailbox creds). `DeploysClusterTool` from day one.
   Unit-tested (`WebmailInitCommandTest`, 7 cases incl. the refuse path, the
   CORS-failure warning, and `--vpn-only` middleware ordering); the enum's
   "every shared service renders its manifest" test render-verifies the ingress.
2. [x] `ClusterTool::WEBMAIL` arm in `oidcEnv()` (Bulwark `OAUTH_ENABLED` +
   `OAUTH_CLIENT_ID`/`OAUTH_CLIENT_SECRET`/`OAUTH_ISSUER_URL`, verified against
   Bulwark's env reference + a real Authentik integration) so `sso:wire webmail`
   registers the Zitadel app and patches Bulwark. **Deliberately did NOT
   auto-configure the Stalwart side** — the real-world config uses
   `storage.directory`, which switches Stalwart's PRIMARY auth backend and, done
   wrong, breaks every existing password/IMAP/SMTP login; and the IdP redirect
   URI is a locale-prefix regex Zitadel can't take as exact-match. Instead
   `ClusterTool::oidcPeerTrustNote()` prints the exact Stalwart OIDC-directory
   step (userinfo endpoint, field mappings, "keep password directory primary")
   + the locale-redirect caveat, surfaced by `SsoWireCommand` after wiring.
   `OAUTH_ENABLED` (not `OAUTH_ONLY`) means SSO is additive — basic-auth login
   and Apple Mail/Thunderbird keep working. `mail:show`/`mail:create` now print
   the webmail URL when Bulwark is installed. Unit-tested (`sso:wire webmail`
   registration + peer note; `mail:show`/`mail:create` webmail line).
   **Still to verify live:** the Stalwart-side OIDC directory end-to-end token
   acceptance (the finicky half, per the discussions users hit).
3. [x] Docs page — `docs/docs/tools/webmail.md` (Bulwark-vs-Roundcube table, the
   CORS/permissive-CORS security note, basic-vs-OIDC auth incl. the two-sided
   SSO warning, the AGPL sentence), sidebar entry, and mail.md's "no webmail"
   note updated to point at it.
4. [x] **Live-fix round (production droplet).** Two bugs the unit tests couldn't
   catch (they fake the process calls, so no container ever runs):
   - **CrashLoopBackOff — read-only-fs container init.** Mounting the session
     secret at `/run/secrets` collided with the kubelet's own SA-token mount at
     `/var/run/secrets/kubernetes.io` (`/var/run`→`/run` symlink), failing init
     with a read-only mkdir. Fix: pass it as the `SESSION_SECRET` **env var**
     (Bulwark supports env or `_FILE`); dropped the secret volume entirely.
   - **CORS written but not applied.** `stalwartSetConfig()` writes the setting,
     but Stalwart only loads config from its store at boot — so webmail login
     kept failing with the CORS error. `webmail:init` now **restarts Stalwart**
     after a successful flip (`--no-mail-restart` to skip), and the failure/skip
     notes point at `mail:restart`.
5. [x] **`mail:init` offer hook** (the "bundle?" decision → offer, don't bundle).
   `offerWebmail()` after a successful `mail:init` (interactive-only, default No)
   mirrors `tool:add`'s offer idiom — discoverable without forcing webmail on
   every mail install or coupling the critical mail deploy to webmail's failures.

## ✅ Verification

- `mail:init local` then `webmail:init local` → reach `https://webmail.{tld}`,
  log in with a `mail:create`d account, read/send a message.
- Confirm the CORS flip actually took: without it, the browser console shows the
  classic JMAP CORS error (reproduce the failure before claiming the fix).
- `webmail:init` BEFORE `mail:init` → clean refusal, not a broken deploy.
- `webmail:init production` (no `--context`) → resolves the env's saved cloud
  target, not the ambient kube-context (the `DeploysClusterTool` guarantee).
- Resource check on the droplet: Bulwark's Next.js pod footprint alongside
  everything else already running on the $24 box.

## ⚠️ Risks / open questions

- **Stalwart config-write endpoint unverified** — the settings *read* path is
  known (used already); the *write* path for `http.permissive-cors` needs a live
  round-trip before Phase 1 is "done." Fallback is the printed manual step.
- **Permissive CORS is all-origins (`*`).** Defensible for Bearer/Basic JMAP
  auth (not cookie-based), but state it in the docs; prefer scoped origins if
  Stalwart ever exposes them.
- **`JMAP_SERVER_URL` must be the public host, not internal DNS** — easy to get
  wrong; the browser-direct-to-JMAP model makes internal cluster DNS unusable
  for the client. SSR hairpinning through the ingress is the accepted trade.
- **OIDC is genuinely two-sided** (Bulwark login AND Stalwart JMAP trust) — don't
  let Phase 2 ship as "OIDC done" after only wiring Bulwark's login screen.
- **AGPL-3.0** — same as Zitadel; unmodified official image, so the copyleft
  trigger doesn't apply, but worth one plain sentence in the docs.
- **Branding must stay company-name-free** (hard rule) — default to neutral
  "Webmail", operator opt-in for any custom `LOGIN_COMPANY_NAME`.
