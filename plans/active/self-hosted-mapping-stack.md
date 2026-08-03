# Self-Hosted Mapping Stack (`map:*`) Implementation Plan

**Status:** Draft / Proposed
**Created:** 2026-08-03
**Updated:** 2026-08-03 (post grill-me gap analysis)
**Target Version:** LaraKube CLI v1.2.0

---

## Executive Summary

Deploy a fully self-hosted Google Maps alternative as a LaraKube companion service.
Three independently selectable layers — **Tiles**, **Geocoding**, and **Routing** — are
orchestrated from a single `larakube map:init` command with interactive multi-select
checkboxes. A Geofabrik-powered region picker geofences the OSM data so the stack
runs comfortably on a 4GB VPS (country-level) or even a 1GB pod (city-level).

A unified API gateway at `map.{domain}/api/v1/*` gives every project in the cluster
a single URL with API-key auth, while the admin dashboard is SSO-gated via Zitadel
ForwardAuth.

---

## Architecture Decisions (Resolved)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| **Tile Server** | PMTiles + Martin (Rust) | ~150Mi RAM, serves static PMTiles AND dynamic PostGIS overlays |
| **Geocoding** | Photon (Elasticsearch) | ~1-4GB RAM regional, native autocomplete, built for OSM |
| **Routing** | Valhalla (C++) | ~0.5-4GB RAM regional, single graph serves all transport modes |
| **Geofencing** | Geofabrik interactive region picker | Country-level extracts reduce planet (70GB) to ~500MB |
| **Data Pipeline** | Kubernetes Job (one-shot) | Downloads PBF → osmium trim → builds all 3 formats |
| **API Pattern** | Traefik IngressRoute + Middleware | Single URL `map.{domain}/api/v1/{tiles,geocode,route}/*` — reuses existing cluster Traefik, no extra gateway pod |
| **Naming & Enum** | `ClusterTool::MAP` & `SharedClusterService::MAP` | Clean integration into LaraKube's companion tool framework, including `commonsBuckets() => ['larakube-map']` |
| **SSO Decoupling** | Decoupled by default; wired via `sso:wire <env> --tool=map` | `map:init` runs standalone without Zitadel (matching `RecordInitCommand` / `ClusterTool::RECORD` precedence); `usesForwardAuth() => true` lets `sso:wire` attach Zitadel ForwardAuth on demand |
| **CLI UX** | Multi-select checkboxes | Users pick exactly which layers they need |
| **CORS** | Traefik CORS Middleware on `/api/v1/*` | Required for browser-based MapLibre tile fetching across subdomains |
| **Local/Cloud** | Both OrbStack/k3s local AND cloud clusters | Same Blade templates, swap TLS config per environment |
| **Data Freshness** | `map:region:add --refresh` (manual) | Re-downloads latest Geofabrik extract and rebuilds indexes on demand |
| **Status Command** | `map:show` | Consistent with LaraKube `*:show` pattern |
| **Job Retry** | `backoffLimit: 3` with exponential backoff | K8s native retry; `map:show` surfaces failures |
| **Tile Caching** | `Cache-Control: public, max-age=86400` via Traefik | 24h browser/CDN cache; tiles are static until `--refresh` |
| **S3 Backend** | Auto-detect active `StorageDriver` (`SEAWEEDFS`/`MINIO`/`GARAGE`) | Reuses `InteractsWithPlex::ensureCommons([$s3Service])` to demand-bootstrap S3 via `plex:init` if missing, plus `allocateStorageBucket()` for bucket setup |
| **Map Style** | LaraKube-branded dark style.json | Custom dark theme, users can override |
| **Photon ES** | Embedded Elasticsearch (bundled in Photon image) | Isolated, no cluster-wide ES dependency |
| **External Access** | Standard `cloud:deploy` flow with Let's Encrypt | Mobile apps, SPAs, partners authenticate via `X-API-Key` |
| **Rate Limiting** | Per-key: 100 req/s tiles, 10 req/s geocoding, 5 req/s routing | Configurable via ConfigMap; prevents runaway projects |

---

## Resource Footprint by Region Scope

| Scope | PMTiles (MinIO) | Photon RAM | Valhalla RAM | Total RAM (all 3) |
|-------|-----------------|------------|--------------|-------------------|
| **City** (e.g. Manila) | ~50MB | ~256MB | ~128MB | **~512MB** |
| **Country** (e.g. Philippines) | ~500MB | ~1GB | ~500MB | **~2GB** |
| **Continent** (e.g. Asia) | ~15GB | ~6GB | ~4GB | **~10GB** |
| **Planet** | ~80GB | ~16GB | ~8GB | **~24GB+** |

> [!TIP]
> A single-country deployment fits comfortably on a $24/month 4GB VPS.
> City-level deployments can share a pod with 512MB limits.

---

## Service Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    larakube-map namespace                    │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │     Traefik IngressRoute (map.{domain}) — shared     │   │
│  │  ┌────────────┬────────────────┬──────────────────┐  │   │
│  │  │ /tiles/*   │ /geocode/*     │ /route/*         │  │   │
│  │  │ → Martin   │ → Photon       │ → Valhalla       │  │   │
│  │  └────────────┴────────────────┴──────────────────┘  │   │
│  │  Middleware: api-key-auth (ForwardAuth sidecar)      │   │
│  │  Middleware: sso-forwardauth (Zitadel, dashboard)    │   │
│  │  Middleware: rate-limit (Traefik built-in)            │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌──────────┐   ┌──────────┐   ┌────────────┐              │
│  │  Martin  │   │  Photon  │   │  Valhalla  │              │
│  │ (Tiles)  │   │(Geocode) │   │ (Routing)  │              │
│  │ ~150Mi   │   │ ~1-4Gi   │   │ ~0.5-4Gi   │              │
│  └────┬─────┘   └────┬─────┘   └─────┬──────┘              │
│       │              │               │                      │
│   ┌───▼───┐    ┌─────▼─────┐   ┌─────▼──────┐              │
│   │ MinIO │    │    PVC    │   │    PVC     │              │
│   │(S3)   │    │ photon-   │   │ valhalla-  │              │
│   │.pmtiles│   │ data      │   │ data       │              │
│   └───────┘    └───────────┘   └────────────┘              │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │    API Key Auth Sidecar (tiny Go/PHP auth check)     │   │
│  │    Validates X-API-Key against K8s Secret store       │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │         MapLibre GL JS Frontend (Static)             │   │
│  │   Default style.json + demo page at /demo            │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘

         ┌──────────────────────────────┐
         │  Kubernetes Job (one-shot)   │
         │  1. Download PBF (Geofabrik) │
         │  2. osmium trim              │
         │  3. Build PMTiles → MinIO    │
         │  4. Import Photon index      │
         │  5. Build Valhalla graph     │
         └──────────────────────────────┘
```

---

## 🛠️ Admin & Debug Explorer Dashboard (`map.{domain}`)

Accessible at `https://map.{domain}` (SSO-gated via Zitadel ForwardAuth):

1. **Visual Map Explorer (MapLibre GL JS)**:
   - Interactive full-screen map rendered with the LaraKube-branded dark theme.
   - Vector tile inspection tool (hover/click on roads, buildings, or polygons to inspect raw OSM tags and layer properties).
2. **Geocode Tester**:
   - Address search bar (`q=...`) to test forward geocoding results live in the browser.
   - Click anywhere on the map to trigger reverse geocoding (`lat`, `lon`) and display raw JSON responses alongside human-readable addresses.
3. **Route & Isochrone Tester**:
   - Click point A (origin) and point B (destination) to visualize turn-by-turn routes calculated by Valhalla.
   - Isochrone radius visualizer (e.g. 5m / 10m / 15m driving or walking polygons rendered on the map).
4. **Service Health & Region Inspector**:
   - Live pod status indicators for Martin, Photon, and Valhalla.
   - Shows loaded Geofabrik region extracts, data build timestamps, S3 bucket storage usage, and active project API keys.

---

## CLI Commands

### `larakube map:init`

Interactive multi-select checkbox flow:

```
🗺️  Self-Hosted Mapping Stack

Which mapping layers do you need?

  ☑ Tiles      — Visual map rendering (PMTiles + Martin)
  ☑ Geocoding  — Address ↔ Coordinates (Photon)
  ☐ Routing    — Turn-by-turn directions (Valhalla)

Select your region:

  ❯ Asia
    ❯ Philippines
      ❯ Philippines (whole country)  — ~500MB
        Luzon                        — ~200MB
        Visayas                      — ~80MB
        Mindanao                     — ~120MB

⏳ Deploying map stack...
   ✓ Created namespace larakube-map
   ✓ Deployed Martin tile server
   ✓ Deployed Photon geocoding engine
   ✓ Spawned data-build Job (streaming logs...)
     → Downloading philippines-latest.osm.pbf (497MB)...
     → Building PMTiles archive...
     → Importing Photon index...
   ✓ Deployed Traefik IngressRoute + API key middleware
   ✓ Created route: map.dev.test

🟢 Mapping stack ready!
   Dashboard:  https://map.dev.test
   Tiles API:  https://map.dev.test/api/v1/tiles/{z}/{x}/{y}.pbf
   Geocode:    https://map.dev.test/api/v1/geocode?q=Manila
```

### `larakube map:wire`

Wires a project to the mapping API:

```bash
# Run from inside your Laravel/app project directory
larakube map:wire
# → Generates per-project API key
# → Injects into project .env:
#   MAP_API_URL=https://map.dev.test/api/v1
#   MAP_API_KEY=lk_map_a1b2c3d4e5f6...
```

**PHP / Laravel Usage Example:**

```php
// Address geocoding
$response = Http::withHeaders(['X-API-Key' => config('services.map.key')])
    ->get(config('services.map.url') . '/geocode', ['q' => 'Davao City']);

// Reverse geocoding
$response = Http::withHeaders(['X-API-Key' => config('services.map.key')])
    ->get(config('services.map.url') . '/geocode/reverse', ['lat' => 7.07, 'lon' => 125.61]);
```

### `larakube map:region:add`

Add additional regions without redeploying:

```bash
larakube map:region:add
# → Interactive region picker (same as map:init)
# → Spawns incremental data-build Job
# → Merges new region into existing PMTiles/Photon/Valhalla data
```

### `larakube map:region:list`

Show loaded regions:

```
┌──────────────────────┬──────────┬──────────────┬─────────────┐
│ Region               │ Size     │ Last Updated │ Layers      │
├──────────────────────┼──────────┼──────────────┼─────────────┤
│ Philippines/Mindanao │ 120MB    │ 2026-08-03   │ Tiles, Geo  │
└──────────────────────┴──────────┴──────────────┴─────────────┘
```

### `larakube map:show`

Health check and status overview:

```
🗺️  Mapping Stack Status

┌─────────────┬──────────┬──────────┬─────────────┐
│ Layer       │ Status   │ RAM      │ Pod          │
├─────────────┼──────────┼──────────┼─────────────┤
│ Tiles       │ 🟢 Ready │ 148Mi    │ martin-0     │
│ Geocoding   │ 🟢 Ready │ 487Mi    │ photon-0     │
│ Routing     │ ⚪ N/A   │ —        │ —            │
└─────────────┴──────────┴──────────┴─────────────┘

┌──────────────────────┬──────────┬──────────────┐
│ Region               │ Size     │ Last Updated │
├──────────────────────┼──────────┼──────────────┤
│ Philippines/Mindanao │ 120MB    │ 2026-08-03   │
└──────────────────────┴──────────┴──────────────┘

S3 Backend:  SeaweedFS (seaweedfs-s3.larakube-seaweedfs.svc)
API URL:     https://map.dev.test/api/v1
Wired Apps:  2 (my-delivery-app, my-store-locator)
```

### `larakube map:remove`

Teardown with confirmation:

```bash
larakube map:remove
# → Confirms deletion of all map data (PVCs, S3 buckets)
# → Removes namespace larakube-map
# → Cleans up MAP_API_URL/MAP_API_KEY from wired projects
```

---

## Common Deploy Configurations & Regional Use Cases

### 🏪 Store Locator / Branch Finder (`☑ Tiles, ☑ Geocoding`)
> *"Show our branches on a map and let users search by address."*
- **RAM:** ~662MB (Martin: 150MB, Photon: 512MB)
- **Storage:** ~350MB (PMTiles: 50MB, Photon: 300MB)

### 🛵 Delivery & Logistics (`☑ Tiles, ☑ Geocoding, ☑ Routing`)
> *"Route our riders from warehouse to customer with turn-by-turn directions."*
- **RAM:** ~918MB (Martin: 150MB, Photon: 512MB, Valhalla: 256MB)
- **Storage:** ~550MB (PMTiles: 50MB, Photon: 300MB, Valhalla: 200MB)

### 📋 Address Autocomplete Only (`☑ Geocoding only`)
> *"I just need an address search field in my form. No map needed."*
- **RAM:** ~512MB (Photon: 512MB)
- **Storage:** ~300MB (Photon: 300MB)

### 🗺️ Fleet Tracking Dashboard (`☑ Tiles, ☑ Routing`)
> *"Show live vehicle positions on a map with route playback."*
- **RAM:** ~406MB (Martin: 150MB, Valhalla: 256MB)
- **Storage:** ~250MB (PMTiles: 50MB, Valhalla: 200MB)

---

## Mindanao Resource Breakdown Summary

| Configuration | RAM | Storage | Host Fit |
|---------------|-----|---------|----------|
| **Geocoding only** | ~512MB | ~300MB | Existing node shareable |
| **Tiles + Geocoding** | ~662MB | ~350MB | Existing node shareable |
| **Tiles + Routing** | ~406MB | ~250MB | Existing node shareable |
| **All 3 layers (Full)** | **~918MB** | **~550MB** | Existing node shareable |

> [!TIP]
> Mindanao data is lightweight (~120MB PBF extract). The entire 3-layer stack runs in under **1GB RAM** and **550MB storage**, allowing it to share an existing node without provisioning dedicated VPS infrastructure.

---

## API Gateway Endpoints

All endpoints require `X-API-Key` header.

### Tiles (`/api/v1/tiles/`)

| Endpoint | Description |
|----------|-------------|
| `GET /api/v1/tiles/{z}/{x}/{y}.pbf` | Vector tile (Protobuf) |
| `GET /api/v1/tiles/style.json` | MapLibre GL style document |
| `GET /api/v1/tiles/metadata.json` | TileJSON metadata |

### Geocoding (`/api/v1/geocode/`)

| Endpoint | Description |
|----------|-------------|
| `GET /api/v1/geocode?q={query}` | Forward geocode (address → coordinates) |
| `GET /api/v1/geocode/reverse?lat={lat}&lon={lon}` | Reverse geocode (coordinates → address) |
| `GET /api/v1/geocode/autocomplete?q={partial}` | Address autocomplete |

### Routing (`/api/v1/route/`)

| Endpoint | Description |
|----------|-------------|
| `POST /api/v1/route/directions` | Turn-by-turn directions |
| `POST /api/v1/route/matrix` | Distance/time matrix |
| `POST /api/v1/route/isochrone` | Reachability polygon |
| `GET  /api/v1/route/health` | Valhalla health check |

---

## Implementation Tasks

### Phase 1: Core Infrastructure

- [ ] Add `SharedClusterService::MAP` enum case with template, namespace, host prefix
- [ ] Auto-detect active `StorageDriver` (`SEAWEEDFS` | `MINIO` | `GARAGE`) and provision `larakube-map` bucket via `$driver->commonsBucketCreateCommand('larakube-map')`
- [ ] Create `app/Commands/Map/MapInitCommand.php`
  - Multi-select checkboxes for layers (Tiles, Geocoding, Routing)
  - Interactive Geofabrik region picker (continent → country → sub-region)
  - Deploy selected service pods + API gateway
  - Spawn data-build Kubernetes Job
- [ ] Create Blade templates:
  - `resources/views/k8s/map/martin.blade.php` — Martin Deployment + Service
  - `resources/views/k8s/map/photon.blade.php` — Photon Deployment + Service + PVC
  - `resources/views/k8s/map/valhalla.blade.php` — Valhalla Deployment + Service + PVC
  - `resources/views/k8s/map/ingress.blade.php` — Traefik IngressRoute with path-based routing to Martin/Photon/Valhalla
  - `resources/views/k8s/map/middleware.blade.php` — Traefik Middleware CRDs (API key ForwardAuth, rate-limit, SSO ForwardAuth on dashboard routes)
  - `resources/views/k8s/map/auth-sidecar.blade.php` — Tiny auth microservice Deployment (validates X-API-Key against K8s Secret)
  - `resources/views/k8s/map/data-job.blade.php` — One-shot build Job (download + process + import)
  - `resources/views/k8s/map/style.blade.php` — MapLibre GL style.json ConfigMap

### Phase 2: Wiring & Multi-Project Access

- [ ] Create `app/Commands/Map/MapWireCommand.php`
  - Generate per-project API key (random, stored as K8s Secret)
  - Inject `MAP_API_URL` + `MAP_API_KEY` into project `.env`
  - Push API key to OpenBao if available
- [ ] Create `app/Commands/Map/MapRemoveCommand.php`
  - Teardown with confirmation
  - Clean up wired project env vars

### Phase 3: Region Management

- [ ] Create `app/Commands/Map/MapRegionAddCommand.php`
  - Add regions incrementally
  - Merge into existing data stores
- [ ] Create `app/Commands/Map/MapRegionListCommand.php`
  - Show currently loaded regions with size and last-updated info
- [ ] Implement Geofabrik region catalog as embedded JSON
  - Hierarchical: continent → country → sub-region
  - Includes download URLs and approximate sizes

### Phase 4: Frontend & Demo

- [ ] Bundle MapLibre GL JS default style.json as ConfigMap
  - Pre-configured to point at `map.{domain}/api/v1/tiles/` source
  - Dark mode and light mode variants
- [ ] Static demo page at `map.{domain}/demo`
  - Interactive map with geocoding search bar and routing
  - Serves as verification after `map:init`

### Phase 5: Tests & Documentation

- [ ] Pest test suite:
  - `tests/Feature/MapInitCommandTest.php`
  - `tests/Feature/MapWireCommandTest.php`
  - `tests/Unit/MapManifestYamlTest.php`
- [ ] Documentation: `docs/docs/commands/map.md`
- [ ] Update `docs/docs/commands/management.md` with map service reference

---

## Docker Images

| Service | Image | Tag | Verified Notes |
|---------|-------|-----|---------------|
| Martin | `ghcr.io/maplibre/martin` | `v0.14.0` | Rust tile server (serves PMTiles via S3 range requests) |
| Photon | `komoot/photon` | `v1.2.1` | Geocoder with embedded Elasticsearch |
| Valhalla | `ghcr.io/valhalla/valhalla` | `v3.8.3` | C++ routing engine with dynamic costing |
| Auth Sidecar | Alpine + Go binary | `latest` | Validates X-API-Key header against K8s Secret |
| Data Builder | Custom Job image | `latest` | `osmium` + `tilemaker` + import scripts |

> [!IMPORTANT]
> Verify latest stable image tags before implementation. Run `search_web` against
> each project's GitHub releases page per the **Live Tool Capability Verification Standard**.

---

## OpenBao Integration

Per the **OpenBao Secrets Prioritization Standard**, when OpenBao is bootstrapped:

1. API keys generated by `map:wire` are pushed to OpenBao via `pushClusterSecret()`
2. Synced to project namespaces via `syncClusterSecretToNamespace()`
3. Photon/Martin/Valhalla admin credentials stored in OpenBao
4. `map:init` reads existing credentials from OpenBao before generating new ones (idempotency)

---

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Data-build Job OOM on large regions | Job crashes | Set Job memory limits based on region size; warn user if region > available node RAM. `backoffLimit: 3` for auto-retry. |
| PMTiles file too large for S3 | Storage costs | Geofencing ensures reasonable file sizes; warn at >10GB |
| Photon Elasticsearch competes with existing ES | Resource contention | Deploy Photon with its own embedded ES, not the cluster-wide one |
| Stale map data | Outdated roads/buildings | `map:region:add --refresh` rebuilds from latest Geofabrik extract |
| Martin PMTiles S3 latency | Slow tile loading | Use in-cluster S3 (SeaweedFS/MinIO/Garage); Traefik caches tiles for 24h |
| CORS blocking browser tile fetches | MapLibre GL JS fails silently | Traefik CORS Middleware on all `/api/v1/*` routes |
| No S3 service deployed | PMTiles storage unavailable | Auto-detect existing S3; fall back to PVC mount if none found |
| Job failure not visible | User thinks stack is healthy | `map:show` surfaces Job status with log link; `backoffLimit: 3` |
