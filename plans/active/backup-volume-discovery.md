# Plan: discover backup volumes instead of listing them

**Status:** ⛔ proposed, not started. Written 2026-08-09, out of the question "how does this fare
with multi-instance cluster tools?" Answer: databases cope, volumes do not.

## The asymmetry

The backup inventory has two halves that behave differently, and only one of them is right.

**Databases discover.** `backupDatabases()` asks Commons for every database it holds. A second
instance of a tool gets its own database and is picked up automatically — nobody edits
anything, nothing is forgotten.

**Volumes are declared.** `backupVolumeTargets()` (`app/Traits/InteractsWithBackup.php`) is a
hardcoded allow-list of 7 entries keyed to *bare* deployment names — `drive-ocis`, `forgejo`,
`vaultwarden`. But `ClusterTool::deploymentName()` suffixes instances, so a second Drive is
`drive-ocis-team2`. That matches nothing in the list, and **nothing reports the miss**,
because a list cannot notice an entry it never had.

The failure mode is the one that matters most here: silent, and only discovered during a
restore.

## Measured exposure (2026-08-09, `larakube-159.89.205.239`)

Every PVC on the cluster against the allow-list:

| PVC | Size | Covered? |
|---|---|---|
| `seaweedfs-data` | 49.3M used | ✅ |
| `drive-ocis-storage` | 23.1M used | ✅ |
| `openbao-data` | 2.5M used | ✅ |
| `forgejo-data` | 2.0M used | ✅ |
| `vaultwarden-storage` | 84K used | ✅ |
| `stalwart-data` | 4.0K used | ✅ |
| `chat-synapse-data` | signing key only | ✅ (single file) |
| `postgres-data` | 10Gi claim | ✅ *logically* — dumped per database, which is better |
| `prometheus-storage` | **1.2G used** | ⛔ excluded by design |
| `data-pocketbase-pvc` | 2Gi claim, Bound | ❌ **not backed up** |
| `webmail-storage` | 36K used | ❌ **not backed up** |

Two gaps today, neither caused by instances:

- **`data-pocketbase-pvc`** is the sharp one. PocketBase is embedded SQLite, so that volume IS
  the entire database — there is no Commons copy to fall back on. DATA is also multi-instance,
  so this would be missing per instance as well. Currently orphaned (Bound, no Deployment — a
  `tool:remove` that left its claim behind), so nothing is at risk *today*.
- **`webmail-storage`** is live and in use by `webmail-bulwark`. 36K, low stakes, but undeclared
  either way.

Latent instance exposure: of the tools that *have* volumes here, only DRIVE and PASSWORDS
return `supportsMultipleInstances() === true`. CHAT, GIT and MAIL are `false` (hostPort and
fixed-port LoadBalancer collisions), so they cannot have a second instance to miss. The two
tools with `--instance` actually wired — DATA and NOTES — keep their data in Commons Postgres
and SeaweedFS, both covered. So no live data is missing because of instances *yet*.

## The change: invert the list

Enumerate PVCs, resolve each to its owning workload, and exclude deliberately.

```
for each PVC in the larakube-* namespaces
    find the Deployment mounting it   (resolveVolumeClaim already does the reverse)
    skip if excluded
    → target { name, namespace, deployment, container, path }
```

An allow-list means a new tool is unprotected until someone remembers it, and nothing tells
them. A deny-list means a new tool and every new instance are protected by default, and
dropping one is a deliberate, reviewable act.

### The exclusions, and why each is safe

| PVC pattern | Why |
|---|---|
| `prometheus-storage` | **1.2G against ~77M for everything valuable — 16×.** Metrics history, rebuilt by waiting. The existing docblock says "~4x"; it is worse than that, so this exclusion is load-bearing, not cosmetic. |
| `postgres-data` / the Commons engine's own claim | Already captured, better, as per-database logical dumps. Archiving the data directory too would double the size and add a copy that is harder to restore. |

Everything else is included. Anything newly excluded needs a line here explaining why, in the
same table.

### A size guard, not a size assumption

The allow-list was defending against size. Do that explicitly instead: refuse (or warn and
skip) any volume above a threshold — say 500M — naming it in the output. That catches the next
Prometheus-shaped thing on the day it appears, rather than the day someone reads the list.

## Scope

| File | Change |
|---|---|
| `app/Traits/InteractsWithBackup.php` | `backupVolumeTargets()` becomes discovery + deny-list; needs `$kubectl`, so every caller changes |
| `app/Commands/Backup/BackupRunCommand.php` | pass `$kubectl` through |
| `app/Commands/Backup/BackupScheduleCommand.php` | same, and the rendered CronJob gets the discovered set |
| `resources/views/k8s/backup/cronjob.blade.php` | `@foreach($volumes …)` already loops — it just receives more entries |
| `app/Commands/Backup/BackupRestoreCommand.php` | `restoreVolume()` looks targets up by name; must resolve against the *discovered* set |

`backupVolumeTargets()` is currently pure and callable without a cluster — three tests rely on
that. Discovery needs `kubectl`, so either the signature takes it and tests fake the PVC
listing, or discovery is a separate method and the pure one stays for the deny-list. **Prefer
the latter**: keep `backupVolumeExclusions()` pure and testable, make `discoverVolumeTargets($kubectl)`
the impure part.

### The synapse-identity special case

The current inventory backs up a single *file* — `/data/chat.luchtech.dev.signing.key` — not
the whole `chat-synapse-data` volume, because the rest is regenerable media cache and the key
is 59 bytes whose loss permanently breaks federation. Discovery would naively grab the whole
volume. Keep a per-PVC path override so this stays a file, or accept the size. Do not lose the
key by accident while making the inventory more automatic.

## Tests

- a PVC with no matching allow-list entry is now discovered (the `data-pocketbase-pvc` case)
- an instance-suffixed deployment (`drive-ocis-team2`) is discovered, where the allow-list missed it
- `prometheus-storage` is still excluded, and the exclusion is asserted by name
- the Commons engine's own data directory is excluded — it is captured as logical dumps
- a volume over the size guard is skipped loudly, not silently
- `synapse-identity` still resolves to the signing-key path, not the whole volume
- discovery and the CronJob template agree: one source of truth, as the existing test asserts

## Verification

1. Gate, then **you** run `./build`.
2. `larakube backup:run production` → the manifest now names `webmail` and, if PocketBase is
   redeployed, `data-pocketbase`.
3. `larakube backup:restore production --deep` → every discovered item verifies.
4. Deploy a second instance of a multi-instance tool with a volume, re-run, and confirm it
   appears without anyone editing the inventory. That is the whole point.
5. Confirm `prometheus` is still absent from the manifest.

## Open questions

- **The orphaned `data-pocketbase-pvc`.** Backing up an orphan is waste; deleting it is
  destructive. Discovery should probably skip claims with no owning workload and *say so*,
  rather than silently including or excluding them. Either way `tool:remove` leaving a Bound
  PVC behind is its own bug, worth a separate look.
- **Grafana's PVC is not on this cluster at all** (Grafana appears to keep state elsewhere) —
  the earlier note that "Grafana's dashboards are unbacked" needs re-checking against the
  actual deployment before acting on it.
