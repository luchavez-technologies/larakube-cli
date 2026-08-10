# Component Plan: `design:init` — Penpot Prototyping & Design Suite

**Status:** 🟢 READY (Target CLI v1.2.0) — revised 2026-08-09: this plan was written against the OLD `--instance=<name>` flag, which has since been eradicated cluster-CLI-wide. Rewritten below to `--domain=` + host-as-identity, and the `vpnMiddlewareTarget()`/`dbSecretRef()` snippets corrected to their real current signatures. Nothing implemented yet — see [ADR 0012](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/docs/decisions/0012-cluster-tool-registry-redesign.md) for the pattern this must follow.  
**Created:** 2026-08-09  
**Related Specs:** [`startup-tools-expansion.md`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/plans/active/startup-tools-expansion.md)

> **Before writing any code**, read ADR 0012. The short version: there is no
> `--instance=` flag anywhere in this CLI anymore. `--domain=` is both the
> instance's host AND its identity; the Kubernetes-resource-naming slug is
> derived automatically via `ClusterTool::instanceSlugFromHost()`, never
> operator-supplied. If `design:init` supports multiple instances, it is only
> the **third** tool to build real multi-instance *creation* flows (after
> `data:init` and `notes:init`) — every other multi-instance-capable tool
> only gets `--domain=` on `:remove`/`:show` via the shared base classes, with
> no `:init`-side instance selection at all. That's real, non-trivial scope
> DATA/NOTES needed to get right (see `DataInitCommand::tearDownOtherEngineForInstance()`,
> and `NotesInitCommand`'s `serviceName` instance-suffixing) — confirm with
> the user whether v1 actually needs it, or whether shipping `design:init`
> single-instance-only (like Analytics/Chat/CRM/Drive/... — 15 of 17
> multi-instance-*capable* tools have no creation-side instance flow at all)
> is the right scope for a brand-new tool.

---

## Executive Summary

Deploy **Penpot** — the leading open-source design, prototyping, and whiteboarding platform — as a core component of the LaraKube Startup OS suite at `design.{domain}` inside `larakube-shared`.

---

## 🏗 Architectural Overview & Topology

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                           larakube-shared namespace                             │
│                                                                                 │
│  ┌──────────────────────────────────────────────────────────────────────────┐   │
│  │                Traefik IngressRoute (design.{domain})                    │   │
│  │   / → penpot-frontend (SPA)                                              │   │
│  │   /api/* → penpot-backend (Clojure JVM)                                  │   │
│  └──────────────────────────────────────────────────────────────────────────┘   │
│                                                                                 │
│  ┌────────────────────────┐   ┌──────────────────────────────────────────────┐  │
│  │    penpot-frontend     │   │                penpot-backend                │  │
│  │      (Nginx SPA)       │   │             (Clojure JVM ~400MB)             │  │
│  │        ~20MB RAM       │   │          Native OIDC SSO (Zitadel)           │  │
│  └────────────────────────┘   └──────────┬─────────────┬─────────────┬───────┘  │
│                                          │             │             │          │
│             ┌────────────────────────────┴┐     ┌──────┴───────┐   ┌─┴───────┐  │
│             │    Plex Commons Postgres    │     │Plex Commons  │   │Plex S3  │  │
│             │ Database: penpot (User: same)│     │ Valkey/Redis │   │Bucket:  │  │
│             └─────────────────────────────┘     └──────────────┘   │`design- │  │
│                                                                    │assets`  │  │
│                                                                    └─────────┘  │
└─────────────────────────────────────────────────────────────────────────────────┘
```

---

## 🔌 LaraKube Wiring Suite Integration

### 1. 📧 Email Wiring (`mail:wire`)
Implements `smtpEnv()` in `ClusterTool::DESIGN`:
```php
self::DESIGN => [
    'deployment' => 'design-penpot-backend',
    'namespace' => $this->namespace(),
    'secret' => 'design-penpot-smtp',
    'vars' => [
        'host' => 'PENPOT_SMTP_DEFAULT_FROM',
        'port' => 'PENPOT_SMTP_SERVER_PORT',
        'user' => 'PENPOT_SMTP_USERNAME',
        'password' => 'PENPOT_SMTP_PASSWORD',
        'from' => 'PENPOT_SMTP_DEFAULT_FROM',
    ],
],
```
Executing `larakube mail:wire production --tool=design` connects Penpot to Stalwart Mail Server.

### 2. 🪪 Identity & SSO Wiring (`sso:wire`)
Implements `oidcEnv()` in `ClusterTool::DESIGN`:
```php
self::DESIGN => [
    'deployment' => 'design-penpot-backend',
    'namespace' => $this->namespace(),
    'secret' => 'design-penpot-oidc',
    'redirect_path' => '/api/auth/oauth/zitadel/callback',
    'static' => [
        'PENPOT_FLAGS' => 'enable-login-with-oidc',
        'PENPOT_OIDC_NAME' => 'Zitadel SSO',
    ],
    'vars' => [
        'client_id' => 'PENPOT_OIDC_CLIENT_ID',
        'client_secret' => 'PENPOT_OIDC_CLIENT_SECRET',
        'auth_url' => 'PENPOT_OIDC_AUTH_URI',
        'token_url' => 'PENPOT_OIDC_TOKEN_URI',
        'userinfo_url' => 'PENPOT_OIDC_USERINFO_URI',
        'issuer' => 'PENPOT_OIDC_BASE_URI',
    ],
],
```
Executing `larakube sso:wire production --tool=design` registers a dedicated Zitadel OIDC client app (`sso-app-design`) and enables OIDC login.

### 3. 🔐 OpenBao Secrets Rotation (`secrets:wire`)
Implements `dbSecretRef()` in `ClusterTool::DESIGN` — real signature takes
`$instance`/`$engine` and the base class auto-suffixes `secret` by instance,
so the match arm only ever declares the **main** shape (same pattern as
`DATA`/`SIGN`/`RECORD`/`SSO`/`LINK` today):
```php
public function dbSecretRef(?string $instance = null, ?string $engine = null): ?array
{
    $ref = match ($this) {
        self::DESIGN => ['namespace' => $this->namespace(), 'secret' => 'design-penpot-db', 'key' => 'password'],
        // ...existing arms...
        default => null,
    };

    if ($ref === null || $instance === null || $instance === '' || $instance === 'main') {
        return $ref;
    }

    $ref['secret'] = "{$ref['secret']}-{$instance}";

    return $ref;
}
```
Executing `larakube secrets:wire production --tool=design` registers the `penpot` static database role in OpenBao (`plex-postgres`), enabling automated 7-day password rotation.

### 4. 🔑 Zero-Trust VPN Restriction (`vpn:wire`)
Implements `vpnMiddlewareTarget()` in `ClusterTool::DESIGN` — real return
type is `?array` (`{name, namespace}` of the Traefik Middleware CRD), not a
bare string:
```php
public function vpnMiddlewareTarget(): ?array
{
    return match ($this) {
        self::DESIGN => ['name' => 'design-vpn-only', 'namespace' => $this->namespace()],
        // ...existing arms...
    };
}
```
Executing `larakube vpn:wire production --tool=design` restricts `design.{domain}` to NetBird VPN peers via the `design-vpn-only` Traefik Middleware (created by `ensureVpnMiddleware()` — see `DeploysClusterTool`).

---

## 🌐 Multi-Domain & Multi-Instance Architecture

* **Multi-Domain**: Ingress host is resolved dynamically via `SharedClusterService::DESIGN->hostFor($domain)` (default: `design.{domain}`).
* **Multi-Instance** (see the scope note at the top of this document before building this): `ClusterTool::DESIGN->supportsMultipleInstances() => true`.
  There is no `--instance=<name>` flag. `--domain=` IS the instance's
  identity — `design:init` with no `--domain=` targets/updates the default
  instance (`'main'`); any other host given via `--domain=` deploys or
  updates a DIFFERENT instance, keyed by that host:
  ```
  design:init  --domain=team2.example.com     # deploys/updates the instance AT that host
  design:remove --domain=team2.example.com    # targets the same instance, by the same host
  design:show   --domain=team2.example.com    # ditto; --domain=all lists every instance
  ```
  The Kubernetes-resource-naming slug is derived automatically via
  `ClusterTool::DESIGN->instanceSlugFromHost($host)` — `'main'` for the
  bare-prefix host (`design.example.com`), otherwise the **full host**
  dashed (`team2.example.com` → `team2-example-com`), never just the
  leftmost label (that was the exact bug ADR 0012 fixes — two different
  hosts sharing a leftmost label must never collide on the same Kubernetes
  Service name). Every resource this tool creates — Deployment, Service,
  *and* Ingress, not just Deployment — must be suffixed by that slug:
  * Ingress: `design-{slug}.{domain}` where `{slug}` ≠ `'main'` (e.g. `design-team2-example-com.dev.test`)
  * Deployments: `design-penpot-backend-{slug}`, `design-penpot-frontend-{slug}`
  * Service (frontend): must NOT default to a bare `'design'` name the way `notes:init`'s did before ADR 0012 fixed it — a second instance's `kubectl apply` would silently steal the first instance's Service selector and Ingress host rule. Pass an explicit, instance-suffixed `serviceName` to the manifest, always.
  * PostgreSQL DB: `penpot_{slug}` (`commonsDatabases($instance)` already suffixes with `_`)
  * S3 Bucket: `design-assets-{slug}` (`commonsBuckets($instance)` already suffixes with `-`)

---

## 📊 RAM & Resource Usage Breakdown

| Service / Workload | Runtime Component | RAM Usage | Notes |
|--------------------|-------------------|-----------|-------|
| `penpot-backend` | Clojure JVM (Java 21) | ~350 MB – 450 MB | Core API & WebSockets sync |
| `penpot-frontend` | Nginx SPA Container | ~15 MB – 25 MB | Web UI static server |
| `penpot-exporter` (optional) | Node.js + Playwright | ~150 MB – 250 MB | PDF/PNG export worker (optional via `--with-exporter` or KEDA) |
| **Total Workload Footprint** | | **~365 MB – 725 MB** | **Base: ~400 MB** (without exporter) |
| **Plex Commons Infrastructure** | Postgres, Redis, SeaweedFS | 0 MB additional | Shared instance reuse |

---

## 🐳 Docker Images

| Service | Image | Tag |
|---------|-------|-----|
| Penpot Backend | `penpotapp/backend` | `2.17` |
| Penpot Frontend | `penpotapp/frontend` | `2.17` |
| Penpot Exporter | `penpotapp/exporter` | `2.17` |

---

## Implementation Checklist

- [ ] Confirm with the user whether `design:init` ships with real multi-instance support in v1, or single-instance-only like 15 of the 17 other multi-instance-*capable* tools (see the scope note at the top of this document)
- [ ] Add `ClusterTool::DESIGN = 'design'` and `SharedClusterService::DESIGN = 'design'`
- [ ] Add `commonsDatabases()` entry `penpot` and `commonsS3Buckets()` entry `design-assets`
- [ ] Implement `app/Traits/InteractsWithDesign.php`
- [ ] Implement `app/Commands/Design/DesignInitCommand.php` (`larakube design:init`) — use `ResolvesToolHost::sanitizeDomainInput()` + `ClusterTool::instanceSlugFromHost()` if multi-instance, `resolveToolHost()` if not
- [ ] `DesignInitCommand` MUST call `$this->registerDeployedTool(ClusterTool::DESIGN, $kubectl, $host, ...)` on success — the one thing `git:init` shipped without (see ADR 0012); without it the tool is invisible to `tool:list`/`tool:show`/`--domain=` targeting even though it's deployed
- [ ] `DesignRemoveCommand`/`DesignShowCommand` need no custom instance handling if built on `AbstractToolRemoveCommand`/`AbstractToolShowCommand` — `--domain=` is already wired generically there
- [ ] Create Blade templates `k8s.design.backend`, `k8s.design.frontend`, `k8s.design.ingress`, `k8s.design.exporter` — pass an explicit, instance-suffixed `serviceName`/`deploymentName` to every one of them if multi-instance (see the Service/Ingress note above)
- [ ] Create Pest test `tests/Feature/DesignInitCommandTest.php`
- [ ] Run `./php vendor/bin/pint` and `./php vendor/bin/phpstan`
