# Test Plan: PlexService extraction on orbstack

**Status:** ⛔ NOT STARTED

Covers the PlexService extraction (Phase 2 `2214393`, Phases 3–4 and the
`PlexJoinCommand` conversion `b756d69`) plus the `wordpress:new` output fix
(`9325d08`), exercised against a real Commons on the local OrbStack cluster.

Every step uses a throwaway name — `plex-check`, `next-check`, `wp-check`,
`notes-check.test` — so no existing tenant is touched. **If a prompt names a
tenant you didn't create in this plan, stop.**

---

## 0. Before you start

- [ ] Start OrbStack.
- [ ] Rebuild the CLI: `composer format && ./build`. Build from a committed
      state — if another session has uncommitted work in `GeneratesProjectInfrastructure.php`
      or the Dockerfile templates, wait for it to land first.
- [ ] Check the current kube-context. Scaffolders join the Commons, and `down`
      tears down, on the **current** context — not `--context`:

  ```bash
  kubectl config current-context
  ```

  If it isn't `orbstack`:

  ```bash
  kubectl config use-context orbstack
  ```

- [ ] Take a baseline and save the output — step 7 compares against it:

  ```bash
  larakube plex:show local --context=orbstack
  ```

## 1. Commons reads still work

- [ ] The step-0 `plex:show` ran without errors and lists the same services and
      tenants as before the change.

Exercises `PlexService::commonsSpec()`, `registry()`, `kubectl()`, `contextReachable()`.

## 2. `plex:join` through its own PlexService

Exercises the converted `PlexJoinCommand`, `ensureCommons()`, database and bucket
creation, Redis slot allocation, tenant registration and `commonsEnvValues()`.

```bash
larakube new plex-check --fast
```

```bash
cd plex-check && grep -E '^(DB_HOST|DB_DATABASE|REDIS_DB|AWS_BUCKET)=' .env
```

- [ ] `DB_HOST=postgres.larakube-plex.svc.cluster.local`
- [ ] `DB_DATABASE=plex_check_local`
- [ ] `REDIS_DB` has a number
- [ ] `AWS_BUCKET=plex-check-local`

Re-join explicitly — it must be idempotent:

```bash
larakube plex:join local --context=orbstack --no-interaction
```

- [ ] Prints `'plex_check_local' is already a tenant`
- [ ] `REDIS_DB` in `.env` is unchanged (no second Redis slot taken)

```bash
larakube up
```

- [ ] The app answers, and its namespace has no Postgres or Redis pods of its own.

## 3. Next.js joins the same way

```bash
cd .. && larakube nextjs:new next-check --fast
```

- [ ] `next-check/.env` has a `DATABASE_URL` pointing at `postgres.larakube-plex…/next_check_local`
- [ ] `plex:show` lists `next_check_local`

## 4. Cluster Tool path (optional — needs local SSO)

Exercises the same allocation code reached from a tool's `:init`, the trait
wrappers without an explicit service, and the "no public host" warning.
Outline needs a login provider, so skip this unless Zitadel runs locally. A new
`--domain` creates a separate instance; your existing one is untouched.

```bash
larakube notes:init local --context=orbstack --domain=notes-check.test
```

- [ ] Installs and prints its Commons Redis slot
- [ ] `plex:show` shows one more Redis slot in use

Remove it with its Commons data — `--purge` only touches this throwaway instance:

```bash
larakube notes:remove local --context=orbstack --domain=notes-check.test --purge --force
```

- [ ] Its Redis slot is free again in `plex:show`

## 5. Eviction through `down --full`

```bash
cd plex-check && larakube down local --full --dry-run
```

- [ ] Names `plex_check_local` and nothing else

```bash
larakube down local --full --force
```

- [ ] `plex:show` no longer lists `plex_check_local`
- [ ] Its Redis slot is free

## 6. WordPress output

```bash
cd .. && larakube wp:new wp-check --fast
```

- [ ] The closing text says to open the site for WordPress's installer
- [ ] No mention of WP-Cron or `.infrastructure/k8s/secrets/`
- [ ] Optional: `larakube up` in `wp-check` and the installer loads

## 7. Clean up and compare

- [ ] `larakube down local --full --force` inside `next-check` and `wp-check`
- [ ] Delete the three project folders
- [ ] `larakube plex:show local --context=orbstack` matches the step-0 baseline:
      same tenants, same number of free Redis slots

---

Steps 2 and 5 carry the most weight — together they exercise nearly everything
that moved into `PlexService`.
