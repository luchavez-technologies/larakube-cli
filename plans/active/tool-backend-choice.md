# Backend choice at `{tool}:init` — replacing `--no-plex`

## Context

A Cluster Tool needs up to three backing services: a database, a cache, and
object storage. Today that is one boolean, `--no-plex`, on 9 of 34 `:init`
commands — and it is wrong in three separate ways.

### 1. The choice is never persisted

`registerDeployedTool()` records `adminEmail` and `engine`. Nothing records
which backend was chosen. So:

```
larakube git:init production --no-plex   # Forgejo on PVC storage
larakube git:init production             # ← silently switches LFS to S3
```

The second run takes the Commons branch unconditionally, allocates a bucket,
and re-renders the Deployment pointing at it. Repos survive (they live on
`forgejo-data`); LFS objects and attachments now point at an empty bucket.

**This is a live data trap, independent of anything proposed here.** Whatever
else this plan does, the choice has to become durable.

### 2. One flag, three meanings

| Tools | What `--no-plex` actually does |
|---|---|
| sso, insights, errors | bundle a **dedicated Postgres pod** |
| vpn, flow | switch to **embedded SQLite** |
| git, monitor | **PVC instead of S3 / Postgres** |
| drive, chat | both at once |

Database, cache and object storage are independent decisions. Directus on
Commons Postgres with PVC file storage is a legitimate combination that
`--no-plex` cannot express.

### 3. Twenty-five tools have no escape hatch at all

And the tools that need one most are the ones a small server can genuinely
run standalone: Forgejo (SQLite), Grafana (SQLite), Vaultwarden (SQLite),
NetBird (SQLite), PocketBase (SQLite + PVC, already the default).

### 4. The prompt a small-server user actually hits

`ensureCommons()` fires *after* the tool has already decided it needs Commons,
and offers a yes/no:

> `No Commons on this cluster yet. Create one now?` — default **yes**,
> and **no** aborts the install.

So on a 2GB node the only two paths are "spawn Postgres + Redis + SeaweedFS"
or "don't get the tool". **This prompt is the single highest-value change in
this plan and the smallest.**

## Blocking prerequisite: the Commons registry has no owner model

Any allocation command writes into `plex-registry`. That registry is currently
not fit to be written into. From the live Commons (42 rows):

```
outline                               db, redis_index    orphan
outline_main                          db, redis_index    orphan
outline_notes_luchtech_dev            db, redis_index    live
grafana_grafana_monitor_luchtech_dev  db                 the double-prefix bug, as a row
crm_twenty_crm-luchtech-dev           redis_index        hyphens and underscores mixed
```

Three defects:

- **No row records which tool, instance or namespace owns it.**
  `PlexEvictCommand::guardStillDeployed()` reads `$entry['namespace']`, which
  only `plex:join` (project apps) ever writes. For every Cluster Tool
  allocation the field is absent, the guard returns `null`, prints "could not
  verify", and proceeds. **`plex:evict` will drop a live tool's database and
  its safety check cannot fire.**
- **One tool produces up to eight rows.** `registerTenantDatabase()` keys by
  database name; `registerTenantStorage()` keys by *bucket* name. Different
  strings, separate rows, mutually unaware. Forgejo has eight.
- **Six of sixteen Redis indices are leaked.** Nine held, three in use by
  something running. `outline` (0), `forgejo` (1), `link_kutt` (3),
  `data_directus` (4), `crm_twenty_main` (6), `outline_main` (9) are dead —
  each rename allocated a new index and never released the old one. See
  `plans/active/commons-redis-index-audit.md` for the release-side bug class.

## Stages

### Stage 0 — registry owner model, and `plex:allocate`

Every registry row grows `tool`, `instance` and `namespace`. One row per
*tenant*, with database / redis index / bucket(s) as fields on it, rather than
one row per allocated thing.

`plex:evict`'s in-use guard then works for Cluster Tools, which it does not
today.

New command, the counterpart to `plex:evict`:

```
larakube plex:allocate {environment}
  --tool=notes --domain=notes.example.com
  [--database] [--redis] [--bucket=…]
```

It wraps the three primitives that already exist as trait methods
(`allocateDatabase`, `allocateCommonsRedisIndex`, `allocateStorageBucket`),
derives every name from `ToolInstance`, and records the owner. `{tool}:init`
calls the same path, so a tool's allocations and a hand-run allocation cannot
diverge.

> **Naming, open:** `plex:evict` is a verb; `plex:tenant` would be a noun and
> reads like a query. `plex:allocate` is proposed here. User's call.

The orphan rows and leaked indices are cleaned by hand, per the
no-one-time-migration-code rule.

### Stage 1 — capability declaration

A vendor declares what it can run on, per backend kind:

```php
interface HasBackendChoices
{
    /** @return array<BackendKind, list<BackendDriver>> — first is the default. */
    public function supportedBackends(): array;
}
```

`BackendKind`: `DATABASE`, `CACHE`, `STORAGE`.
`BackendDriver`: `COMMONS`, `BUNDLED`, `SQLITE`, `PVC`, `EXTERNAL`, `NONE`.

Note this is **not** the existing `DatabaseDriver` / `CacheDriver` /
`StorageDriver` enums — those describe *project app* scaffolding in
`.larakube.json`. Cluster Tools need their own, alongside
`HasCommonsDatabases` and friends.

**Verification.** A declaration is only an assertion. Cheap check: a test that
renders each declared combination and asserts the manifest references no
Commons Service for a standalone choice. Catches "we said SQLite but the
template still hardcodes a Postgres DSN", which is the realistic failure.

### Stage 2 — three flags, and a three-way Commons prompt

Replace `--no-plex` with `--database=`, `--cache=`, `--storage=`, each
validated against the tool's declaration. `--no-plex` stays as a deprecated
alias mapping to the tool's standalone combination, so nothing in flight
breaks.

Where the declaration says the tool can stand alone, `ensureCommons()` stops
being a yes/no:

```
No Commons on this cluster yet.
  › Create one (Postgres + Redis, ~400MB)
    Run Forgejo standalone (SQLite + PVC, ~80MB)
    Cancel
```

**Non-interactive behaviour — a deliberate exception.** AGENTS.md requires a
command needing a parameter to throw `MissingFlagException` rather than guess.
Backend choice is a *defaulted* decision, not a required one: without a flag it
defaults to Commons, exactly as today, so every existing script keeps working.
Recording the exception here rather than carving it silently.

### Stage 3 — durability

The resolved choice is recorded on the tool's registry entry. A re-run without
flags **honours the recorded choice**. A re-run whose flag contradicts the
record is **refused**, naming `{tool}:storage` as the way to change it.

This closes the `git:init` trap above, and it is the one stage that must not
be skipped — everything else is ergonomics.

### Stage 4 — `{tool}:storage`

A real command to move a deployed tool between backends, rather than a flag
that silently repoints an empty one.

```
larakube {tool}:storage {environment} --to=commons|pvc|external
  [--provider=r2|s3] [--bucket=] [--endpoint=]
```

- Stops the tool, moves the data, repoints it, restarts, updates the record.
- **Migrates only where the layout is a verified object-key mirror**
  (PocketBase's `pb_data/storage/{collection}/{record}/{file}` maps straight
  onto object keys; Forgejo attachments likewise). Where it is not verified, it
  **refuses** rather than guesses.
- Surfaces an existing Commons when one is present, so a user pointing a second
  tool at storage learns they already have SeaweedFS.

> **Open decision — external S3 and backups.** `backup:run` archives SeaweedFS
> whole as its single explicit entry; that is how tool buckets are covered
> today. A bucket on R2 or AWS leaves LaraKube's backups entirely. Either
> `{tool}:storage --provider=…` registers the external bucket as a backup
> target, or it prints plainly that the bucket is now the operator's
> responsibility. Needs a decision before Stage 4 ships.

## PocketBase specifically

PocketBase's S3 is set through its Settings API, not environment variables, and
there are **two** configs — file storage and backups — which upstream
recommends keeping in separate buckets. Backups explicitly do not include files
already moved to S3 (though on LaraKube those objects are still covered, since
`backup:run` archives SeaweedFS whole).

So `data:init --storage=commons` for PocketBase is a post-deploy API call, the
same shape as `stalwartSetPermissiveCors()`. Consequences:

- An admin can turn it off again in the UI. `data:init` sets it; `data:show`
  reports the drift. It does not re-enforce on every run and fight the admin.
- Flipping it on for an **existing** install does not move files already on the
  PVC. Init-only, with a warning on re-init, and `data:storage` for the real
  move.

## Rollout order

1. Stage 0 (registry owner model + `plex:allocate`) — unblocks everything and
   fixes a live `plex:evict` hazard.
2. Stage 3 (durability) — closes the `git:init` data trap. Deliberately ahead
   of the ergonomics.
3. Stage 1 (capability declaration).
4. Stage 2 (three flags + three-way prompt) — one tool at a time, starting with
   the tools that already have `--no-plex` and a real standalone mode: git,
   flow, vpn, monitor.
5. Stage 4 (`{tool}:storage`).

## Sequencing against the naming migration

**This waits.** It touches all 34 `:init` commands — the same files the
canonical-naming migration still has to walk through for drive, vpn, crm, mail,
chat, passwords, secrets and sso. Doing it first means doing it twice.
