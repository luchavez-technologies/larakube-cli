# Plan: Cloud config → per-environment

**Status:** ✅ BUILT — verified 2026-08-08: cloud config lives in `EnvironmentData` per environment, exactly as proposed. See ADR 0007 for the committed-blueprint vs gitignored-local split.

## Motivation

Today cloud connection config lives in a top-level `ConfigData::$cloud` map,
detached from the environments it actually describes. Maintaining it outside
`environments` means the two can drift (an env with no matching cloud entry, or
a cloud entry for a deleted env). Move it *into* the environment it belongs to,
the same way `strategy`, `ingress`, `hosts`, and `managed` already live on
`EnvironmentData`.

## Current state (the mess)

`ConfigData::$cloud` is a single top-level map mixing two unrelated concerns:

```jsonc
"cloud": {
  "production": { "ip": "1.2.3.4", "user": "larakube", "port": 22, "key": "..." },
  "staging":    { "ip": "...", ... },
  "users": [ { "username": "...", "authorized_keys": [...] }, ... ]  // cross-env teammate keys
}
```

- `cloud[<env>]` → per-env SSH connection. Read by `getCloudConfig/Ip/User/Port/Key`,
  consumed by `cloud:configure` and `gha:configure`.
- `cloud['users']` → teammate SSH-key descriptors, synced to a server in
  `cloud:configure users`.
- The `CloudData` class (ip/user/port/key) **exists but is never used** — the map
  holds raw arrays.
- `ConfigData::setCloudConfig()` is **called twice in `CloudConfigureCommand` but is
  not defined anywhere** → `cloud:configure base` and `users` currently fatal. Fix
  as part of this work.

## Target shape

Per-env connection + teammates both live inside each environment's `CloudData`
(decision: teammates are per-env, not a shared top-level list — keeps everything
cloud-related attached to its environment):

```jsonc
"environments": {
  "production": {
    "hosts": { "web": "app.example.com" },
    "cloud": {
      "ip": "1.2.3.4", "user": "larakube", "port": 22, "key": "~/.ssh/id_rsa",
      "teammates": [ { "username": "...", "authorized_keys": [...] } ]
    }
  },
  "staging": { "cloud": { ... } }
}
```

## Changes

### `CloudData`
- Add `public array $teammates = []` (teammate SSH-key descriptors for this env).
- Keep ip/user/port/key + the `~/.ssh/id_rsa` key default.

### `EnvironmentData`
- Add `public ?CloudData $cloud = null`. Spatie Data v4 auto-casts a nested Data
  object from a JSON array (unlike the `array<string, EnvironmentData>` map, which
  needed the manual promotion loop), so no special handling needed.

### `ConfigData`
- **Keep** `public array $cloud = []` as a *legacy intake only*, `@deprecated`.
- Constructor: after the environments-promotion loop, migrate any legacy top-level
  `cloud` into `environments[env].cloud` (folding the shared `users` list into each
  configured env's `teammates`), then clear `$this->cloud`. Idempotent and only
  fills when `env.cloud` is still null, so it never clobbers new-shape data.
- Getters delegate to the env:
  - `getCloud($env): ?CloudData`
  - `getCloudConfig($env): array` → `getCloud($env)?->toArray() ?? []` (array form
    kept for the command's `$cloud['ip']` access + emptiness checks)
  - `getCloudIp/User/Port/Key($env)` → read CloudData fields
  - `getTeammates($env): array` → `getCloud($env)?->teammates ?? []`
- Setters (fix the latent bug):
  - `setCloud($env, CloudData|array): self` — the real method the command needs
  - `addTeammate($env, array $teammate): self`
- `saveToFile`: drop the legacy top-level `cloud` key (always empty at rest now),
  alongside the existing `isScaffolding`/`path` drops.

### `CloudConfigureCommand`
- `configureBase`: `setCloudConfig(...)` → `setCloud(...)`.
- `configureUsers`: drop the `toArray()`/`fromArray()` hack; use
  `getTeammates($env)` + `addTeammate($env, ...)`; sync loop reads `getTeammates`.

### `GhaConfigureCommand`
- No change — `getCloudIp($env)` still resolves through the new getter.

## Out of scope / unaffected
- Manifest generation: cloud is deploy-connection info, never rendered into k8s
  manifests → **no snapshot regeneration**.

## Tests (new `tests/Feature/CloudConfigTest.php`)
1. `setCloud` writes into `environments[env].cloud`; `getCloudIp/User/Port/Key`
   read it back.
2. Legacy migration: `ConfigData::from(['cloud' => ['production' => {...},
   'users' => [...]]])` → `environments['production'].cloud` populated, teammates
   carried, top-level `cloud` emptied.
3. `addTeammate` appends to the env's `cloud.teammates`.
4. Round-trip: build with legacy cloud → `saveToFile` → reload → new shape, no
   top-level `cloud` key on disk.
5. `getCloudConfig` returns the array form (command compatibility).

## Done when
- New + full suite green, name-scan clean, committed. (Build/tag left to user.)
