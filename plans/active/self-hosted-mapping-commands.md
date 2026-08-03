# 🗺️ LaraKube Map Stack — Command Guide & Mindanao Estimates

---

## Commands at a Glance

| Command | Purpose | When to Use |
|---------|---------|-------------|
| `map:init` | Deploy the mapping stack | Once per cluster — sets up the services |
| `map:wire` | Connect a project to the map API | Once per project that needs maps |
| `map:region:add` | Add more geographic regions | When you expand coverage |
| `map:region:list` | Show loaded regions | Check what's currently available |
| `map:remove` | Tear down the entire stack | When you no longer need maps |

---

## Command Details

### 1. `larakube map:init`

**What it does:** Deploys the mapping infrastructure to your cluster. Presents a multi-select checkbox to pick which layers you need, then an interactive region picker to choose your geographic coverage.

```
🗺️  Self-Hosted Mapping Stack

Which mapping layers do you need?
  ☑ Tiles      — Visual map rendering (PMTiles + Martin)
  ☑ Geocoding  — Address ↔ Coordinates (Photon)
  ☐ Routing    — Turn-by-turn directions (Valhalla)

Select your region:
  ❯ Asia → Philippines → Mindanao (~120MB)
```

**Run it once.** It creates the `larakube-map` namespace, deploys the selected services, downloads the OSM data for your region, and builds all the indexes via a Kubernetes Job.

---

### 2. `larakube map:wire`

**What it does:** Generates a per-project API key and injects the connection details into your project's `.env`.

```bash
# Run from inside your Laravel/app project directory
larakube map:wire
```

**Result in your `.env`:**
```env
MAP_API_URL=https://map.dev.test/api/v1
MAP_API_KEY=lk_map_a1b2c3d4e5f6...
```

Your app then calls the API like:
```php
// Forward geocode
Http::withHeaders(['X-API-Key' => config('services.map.key')])
    ->get(config('services.map.url') . '/geocode', ['q' => 'Davao City']);

// Reverse geocode
Http::withHeaders(['X-API-Key' => config('services.map.key')])
    ->get(config('services.map.url') . '/geocode/reverse', ['lat' => 7.07, 'lon' => 125.61]);
```

---

### 3. `larakube map:region:add`

**What it does:** Adds another geographic region to your existing stack without redeploying.

```bash
larakube map:region:add
# → Picker appears: Asia → Philippines → Visayas (~80MB)
# → Spawns a Job to download, process, and merge into existing data
```

Use this when your business expands to new areas. Each new region adds to the existing data — it doesn't replace it.

---

### 4. `larakube map:region:list`

**What it does:** Shows what regions are currently loaded.

```
┌──────────────────────┬──────────┬──────────────┬─────────────┐
│ Region               │ Size     │ Last Updated │ Layers      │
├──────────────────────┼──────────┼──────────────┼─────────────┤
│ Philippines/Mindanao │ 120MB    │ 2026-08-03   │ Tiles, Geo  │
└──────────────────────┴──────────┴──────────────┴─────────────┘
```

---

### 5. `larakube map:remove`

**What it does:** Tears down everything — services, PVCs, MinIO data, and cleans `MAP_API_URL`/`MAP_API_KEY` from wired projects.

---

## Common Combinations & Use Cases

### 🏪 Use Case 1: Store Locator / Branch Finder
> *"Show our branches on a map and let users search by address."*

```bash
larakube map:init        # Select: ☑ Tiles, ☑ Geocoding — Region: Mindanao
larakube map:wire        # From your Laravel project
```

**Layers needed:** Tiles + Geocoding
**What you get:**
- Interactive map showing branch pins
- Address search bar ("Find nearest branch to...")
- Reverse geocode ("What barangay is this pin in?")

| Resource | Estimate |
|----------|----------|
| Martin (Tiles) | **~150MB RAM** |
| Photon (Geocoding) | **~512MB RAM** |
| **Total RAM** | **~662MB** |
| PMTiles on MinIO | ~50MB storage |
| Photon index PVC | ~300MB storage |
| **Total Storage** | **~350MB** |

---

### 🛵 Use Case 2: Delivery / Logistics App
> *"Route our riders from warehouse to customer with turn-by-turn directions."*

```bash
larakube map:init        # Select: ☑ Tiles, ☑ Geocoding, ☑ Routing — Region: Mindanao
larakube map:wire        # From your delivery app
```

**Layers needed:** All 3 (Tiles + Geocoding + Routing)
**What you get:**
- Visual map with delivery zones
- Address autocomplete for order entry
- Optimal route calculation (A → B → C)
- Distance/time matrix ("Which rider is closest?")
- Isochrones ("Show 15-minute delivery radius from warehouse")

| Resource | Estimate |
|----------|----------|
| Martin (Tiles) | **~150MB RAM** |
| Photon (Geocoding) | **~512MB RAM** |
| Valhalla (Routing) | **~256MB RAM** |
| **Total RAM** | **~918MB** |
| PMTiles on MinIO | ~50MB storage |
| Photon index PVC | ~300MB storage |
| Valhalla graph PVC | ~200MB storage |
| **Total Storage** | **~550MB** |

---

### 📋 Use Case 3: Address Autocomplete Only
> *"I just need an address search field in my form. No map needed."*

```bash
larakube map:init        # Select: ☑ Geocoding only — Region: Mindanao
larakube map:wire        # From your app
```

**Layers needed:** Geocoding only
**What you get:**
- Fast address autocomplete (`/geocode/autocomplete?q=Dav...`)
- Forward/reverse geocoding API
- No visual map, no routing — lightest possible

| Resource | Estimate |
|----------|----------|
| Photon (Geocoding) | **~512MB RAM** |
| **Total RAM** | **~512MB** |
| Photon index PVC | ~300MB storage |
| **Total Storage** | **~300MB** |

---

### 🗺️ Use Case 4: Fleet Tracking Dashboard
> *"Show live vehicle positions on a map with route playback."*

```bash
larakube map:init        # Select: ☑ Tiles, ☑ Routing — Region: Mindanao
larakube map:wire        # From your fleet management app
```

**Layers needed:** Tiles + Routing
**What you get:**
- Live map with vehicle markers (your app pushes positions via WebSocket)
- Route calculation for dispatch
- Martin's PostGIS dynamic tile support for heatmaps/overlays later

| Resource | Estimate |
|----------|----------|
| Martin (Tiles) | **~150MB RAM** |
| Valhalla (Routing) | **~256MB RAM** |
| **Total RAM** | **~406MB** |
| PMTiles on MinIO | ~50MB storage |
| Valhalla graph PVC | ~200MB storage |
| **Total Storage** | **~250MB** |

---

## Mindanao Resource Summary

| Configuration | RAM | Storage | Monthly VPS Cost |
|---------------|-----|---------|-----------------|
| **Geocoding only** | ~512MB | ~300MB | Fits on existing node |
| **Tiles + Geocoding** | ~662MB | ~350MB | Fits on existing node |
| **Tiles + Routing** | ~406MB | ~250MB | Fits on existing node |
| **All 3 layers** | ~918MB | ~550MB | Fits on existing node |

> [!TIP]
> **Mindanao is tiny.** All configurations fit comfortably under 1GB RAM and 1GB storage.
> Even the full stack (all 3 layers) uses less than 1GB RAM — this can easily share
> a node with your existing LaraKube services without needing a dedicated VPS.

### For comparison — if you scaled up later:

| Region | All 3 Layers RAM | Storage |
|--------|-----------------|---------|
| **Mindanao** | **~918MB** | **~550MB** |
| **Philippines (whole)** | ~2GB | ~1.5GB |
| **Southeast Asia** | ~6GB | ~8GB |
| **Asia (continent)** | ~10GB | ~15GB |
| **Planet (everything)** | ~24GB+ | ~80GB+ |
