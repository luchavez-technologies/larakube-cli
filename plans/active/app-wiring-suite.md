# User Application Wiring & Multi-Cluster Secrets Suite Plan (`secrets:*`, `mail:wire`, `sso:wire`, `vpn:wire`)

**Status:** Draft / Proposed
**Created:** 2026-07-29
**Updated:** 2026-07-29
**Target Version:** LaraKube CLI v1.2.0

---

## Executive Summary

When operating across multiple Kubernetes clusters (e.g. `doks-prod-sgp1`, `doks-staging-sfo3`, `k3d-local`), secret management requires explicit scoping across environments (`local`, `staging`, `production`), clusters (`--context`), and applications (`--app`).

This plan defines the standardized **`secrets:create`**, **`secrets:update`**, **`secrets:delete`**, and **`secrets:list`** verb suite — leveraging **External Secrets Operator (ESO)** for 100% automated background Kubernetes `Secret` sync and zero-downtime pod rollouts.

---

## 1. Multi-Cluster Secrets Management Suite (`secrets:*`)

### CLI Command Family:

```bash
# 1. List all active secrets in an environment on a target cluster
larakube secrets:list [environment] --app=my-laravel-app --context=doks-prod-sgp1

# 2. Create a new secret key-value in OpenBao for a specific environment
larakube secrets:create STRIPE_API_KEY=sk_live_... [environment] --app=my-laravel-app --context=doks-prod-sgp1

# 3. Update an existing secret in OpenBao (ESO automatically syncs & triggers rollout)
larakube secrets:update STRIPE_API_KEY=sk_live_new... [environment] --app=my-laravel-app --context=doks-prod-sgp1

# 4. Optional: Force immediate instant rollout instead of waiting for ESO's 60s poll
larakube secrets:update STRIPE_API_KEY=sk_live_new... [environment] --now

# 5. Delete a secret key from OpenBao
larakube secrets:delete STRIPE_API_KEY [environment] --app=my-laravel-app --context=doks-prod-sgp1
```

---

## 2. Multi-Cluster OpenBao Path Scoping

Secrets are stored deterministically in OpenBao's KV v2 engine:

```
  OpenBao KV v2 Mount: secret/
   ├── data/
   │   ├── production/
   │   │   ├── my-laravel-app/
   │   │   │   ├── STRIPE_API_KEY
   │   │   │   └── DB_PASSWORD
   │   │   └── my-wordpress-blog/
   │   │       └── WORDPRESS_DB_PASSWORD
   │   └── staging/
   │       └── my-laravel-app/
   │           └── STRIPE_API_KEY (sk_test_...)
```

---

## 3. Automated ESO Background Rollout Pipeline

Because External Secrets Operator (ESO) continuously monitors OpenBao, **LaraKube CLI does NOT need to execute manual rollout commands**:

```
  [ Developer ] ──> larakube secrets:update STRIPE_API_KEY=... production
                         │
                         ▼
             1. Update OpenBao KV v2
             (secret/data/production/my-laravel-app)
                         │
                         ▼
             2. Automated ESO Background Sync
             (ESO detects OpenBao change -> updates K8s Secret `app-secrets`)
                         │
                         ▼
             3. Automated Reloader Pod Restart
             (Reloader detects K8s Secret change -> triggers zero-downtime rolling update)
                         │
                         ▼
             4. Health Probe Verification
             New pod boots -> passes /up -> old pod terminates cleanly (0 dropped requests!)
```

*(Note: If a developer wants to bypass ESO's 60-second polling interval and trigger an immediate rollout right away, passing `--now` executes an immediate `rollout restart`).*

---

## 4. Outbound Email Wiring (`mail:wire`)

When executed for a user application, `mail:wire` generates verified Stalwart SMTP credentials, pushes them to OpenBao (`secret/data/<environment>/<app>`), and injects them into native Kubernetes `Secret` resources.

```bash
# Wire current app to Stalwart Mail on target context
larakube mail:wire [environment] --context=doks-prod-sgp1

# Wire a specific named app
larakube mail:wire [environment] --app=my-laravel-app --context=doks-prod-sgp1
```

---

## 5. Single Sign-On Wiring (`sso:wire`)

`sso:wire` registers the application as an OIDC Client in Zitadel (`zitadelCreateOidcApp`), writes the Client ID and Client Secret to OpenBao, and configures the OIDC authentication environment.

```bash
# Register current app as an OIDC client in Zitadel and wire credentials
larakube sso:wire [environment] --context=doks-prod-sgp1

# Wire with optional SSO-only enforcement
larakube sso:wire [environment] --sso-only --context=doks-prod-sgp1
```

---

## 6. VPN Gatekeeper Wiring (`vpn:wire`)

`vpn:wire` restricts network access so that an entire application environment (e.g. `staging.myapp.com`) or sensitive administrative paths are **only accessible when connected to NetBird VPN**.

```bash
# Gate current application ingress to NetBird VPN IP range on target cluster
larakube vpn:wire [environment] --context=doks-prod-sgp1

# Gate specific path (e.g., /admin, /horizon, /telescope) to VPN
larakube vpn:wire [environment] --path=/admin --context=doks-prod-sgp1
```

---

## 7. Technical File Architecture Map

| File Path | Purpose / Description |
| :--- | :--- |
| `app/Commands/Secrets/SecretsListCommand.php` | `secrets:list [env]` listing OpenBao keys per cluster context. |
| `app/Commands/Secrets/SecretsCreateCommand.php` | `secrets:create KEY=VAL [env]` adding key to OpenBao. |
| `app/Commands/Secrets/SecretsUpdateCommand.php` | `secrets:update KEY=VAL [env]` updating OpenBao (ESO handles rollout). |
| `app/Commands/Secrets/SecretsDeleteCommand.php` | `secrets:delete KEY [env]` removing key from OpenBao. |
| `app/Traits/WiresAppMail.php` | Trait for generating Stalwart SMTP env vars per framework. |
| `app/Traits/WiresAppSso.php` | Trait for registering OIDC clients in Zitadel and injecting credentials per framework. |
| `app/Traits/WiresAppVpn.php` | Trait for attaching Traefik VPN middleware to application ingresses. |
