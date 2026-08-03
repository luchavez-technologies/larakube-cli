# Plex Commons Architecture & Multi-Tenant Guide (`plex:*`)

**Status:** Shipped / Production Ready (LaraKube CLI v1.1.0+)
**Created:** 2026-05-30
**Updated:** 2026-08-03 (reflecting shipped CLI codebase)
**Target Version:** LaraKube CLI v1.1.0+

---

## Executive Summary

The **Plex Commons** architecture enables running multiple independent LaraKube application repositories ("Tenants") on a single VPS (e.g. $12/mo DigitalOcean 2GB droplet) or across a multi-node cluster without wasting RAM on duplicate database or storage infrastructure.

Instead of each application deploying its own dedicated PostgreSQL, Redis/Valkey, and S3 containers, tenants lease isolated databases, credentials, and S3 buckets from a shared **Plex Commons** bundle running in the `larakube-shared` / `larakube-plex` namespace.

---

## Architecture & Isolation Model

```
┌─────────────────────────────────────────────────────────────┐
│                    Cluster Node / Multi-Node                │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │           Traefik Ingress Controller (Shared)        │   │
│  └──────┬───────────────────────────────┬───────────────┘   │
│         │ app-a.com                     │ app-b.com         │
│  ┌──────▼───────────────┐        ┌──────▼───────────────┐   │
│  │ namespace: app-a-prod│        │ namespace: app-b-prod│   │
│  │   [ app-a-web pod ]  │        │   [ app-b-web pod ]  │   │
│  └──────┬───────────────┘        └──────┬───────────────┘   │
│         │                               │                   │
│         └───────────────┬───────────────┘                   │
│                         │ In-Cluster Network                │
│  ┌──────────────────────▼───────────────────────────────┐   │
│  │          Plex Commons (larakube-shared / plex)       │   │
│  │                                                      │   │
│  │  ┌──────────────┐   ┌──────────────┐   ┌──────────┐  │   │
│  │  │ PostgreSQL   │   │ Redis/Valkey │   │ SeaweedFS│  │   │
│  │  │ db: app_a_db │   │ index: 0     │   │ bucket:  │  │   │
│  │  │ db: app_b_db │   │ index: 1     │   │ app-a    │  │   │
│  │  └──────────────┘   └──────────────┘   └──────────┘  │   │
│  │  OpenBao Static Roles: Auto-rotated credentials      │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### Security & Isolation Guarantees

- **PostgreSQL**: Scoped database per tenant (`app_a_db`, `app_b_db`) with separate role credentials (`GRANT` restricted). Tenant A cannot access Tenant B's database.
- **Redis / Valkey**: Isolated logical DB index per tenant with release tracking on teardown (`commonsRedisKeys()`).
- **S3 Object Storage**: DNS-safe S3 bucket per tenant (`app-a-storage`, `app-b-storage`) provisioned via `StorageDriver::commonsBucketCreateCommand()`.
- **Secrets & Rotation**: Database credentials are pushed to OpenBao (`registerStaticRole()`) and rotated automatically with zero pod downtime via ExternalSecret synchronization.

---

## Shipped CLI Command Suite (`plex:*`)

### 1. `larakube plex:init`
Bootstraps the Plex Commons infrastructure on target cluster.
```bash
larakube plex:init --services=postgres,redis,seaweedfs [environment]
```
- Provisions shared PostgreSQL, Redis/Valkey, and SeaweedFS/MinIO/Garage.
- Bootstraps OpenBao static database role engine if OpenBao is present.

### 2. `larakube plex:join`
Connects the current application repository to the Commons.
```bash
# Run from inside a Laravel project directory
larakube plex:join [environment]
```
- Creates a dedicated tenant database & OpenBao static role.
- Provisions a DNS-safe S3 bucket (`allocateStorageBucket()`).
- Allocates a Redis logical index.
- Sets `managed: [postgres, redis, s3]` in `.larakube.json` and updates `.env`.

### 3. `larakube plex:show`
Displays live status dashboard of all registered Tenants, loaded databases, S3 buckets, and RAM usage on the cluster.
```bash
larakube plex:show [environment]
```

### 4. `larakube plex:rotate`
Triggers immediate zero-downtime rotation of OpenBao static database passwords across all tenants or a specific tenant.
```bash
larakube plex:rotate [tenant] [environment]
```

### 5. `larakube plex:migrate`
Performs live network migration of an app's existing self-hosted database/S3 data into the Plex Commons without local file staging.
```bash
larakube plex:migrate [environment]
```

### 6. `larakube plex:leave`
Deprovisions a tenant's database, S3 bucket, and Redis index with confirmation.
```bash
larakube plex:leave [tenant] [environment]
```

### 7. `larakube plex:destroy`
Tears down the entire Plex Commons infrastructure.
```bash
larakube plex:destroy [environment] --force
```

### 8. `larakube plex:export` / `plex:resources`
Exports Commons manifest configurations and displays live CPU/memory resource utilization per tenant pod.

---

## 📈 Evolution & Graduation Path

| Stage | Data Topology | Tenant Blueprint | Graduation Action |
|-------|---------------|------------------|-------------------|
| **Local Dev** | Standalone pods in `{app}-local` | `managed` off | Standard `larakube up` |
| **Single-Node Plex ($12/mo)** | Shared Commons in `larakube-shared` | `managed: [postgres, redis, s3]` | `larakube plex:join` |
| **Multi-Node Cluster** | Shared Commons or Managed DB | `managed: [postgres, redis]` | `larakube cloud:scale` |
| **Managed DB (RDS / DO Managed)** | External Cloud DB | `managed` host -> provider URL | Update `.env` DB host & redeploy |

> [!TIP]
> **Graduation is seamless**: Moving a tenant from Plex Commons to a dedicated Managed DB (e.g. AWS RDS or DigitalOcean Managed Postgres) requires only updating the `DB_HOST` in `.env` and running `larakube up`. No manifest rewrites are necessary.

---

## Capacity Budget & Guidelines (2GB / 4GB VPS)

| Node RAM | Fit | Recommendation |
|----------|-----|----------------|
| **2GB VPS** | 2–3 modest Laravel tenants | Reuses Commons Postgres + Redis. Avoid running heavy search (Meilisearch) per tenant. |
| **4GB VPS** | 5–8 Laravel tenants | Comfortable headroom for background queues, Horizon, and companion tools. |
| **Multi-Node** | Unlimited | Scale node pools via `cloud:scale` while keeping Plex Commons active. |

---

## Key Codebase References

- `app/Enums/ClusterTool.php` — Tool registry & Commons database/bucket lookup methods (`commonsDatabases()`, `commonsBuckets()`)
- `app/Traits/InteractsWithPlex.php` — Core tenant provisioning, DB creation, S3 allocation logic
- `app/Traits/SyncsClusterSecrets.php` — OpenBao static role registration (`registerStaticRole()`, `rotateStaticRole()`)
- `app/Enums/StorageDriver.php` — Idempotent S3 bucket creation/deletion commands per driver (`commonsBucketCreateCommand()`)
