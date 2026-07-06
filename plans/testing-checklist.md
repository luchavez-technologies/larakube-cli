<!--
  CONSOLIDATED TEST & DOCS TRACKER
  Persisted from a chat session on 2026-06-25 so the in-memory task tracker
  survives a chat clear. This single file merges what used to be two files:
  the tickable testing checklist (below) and the detailed manual-test guide
  (further below). Update the checkboxes here as you work.
-->

# LaraKube CLI — Test & Docs Tracker (consolidated)

## 🆕 v0.22.0 + v0.23.0 — Manual Test Guide (2026-07-04 session batch)

Covers everything shipped in commits `c531e3d`..`80a5024` (tags `v0.22.0`,
`v0.23.0`): opt-in environments, the `cloud:configure` consolidation, the
Grafana `/etc/hosts` fix, multi-host web hosts (`additionalWebHosts`),
`larakube env {name} --edit`, and the `cloud:create` stack-naming fix. The
automated suite (`./php vendor/bin/pest`, 547 passing) already covers unit/
feature-level correctness — this is the manual, real-project walkthrough to
build confidence before wider use. Ordered by blast radius: free/local steps
first, real-cloud-cost steps (💰) last.

### Phase A — Opt-in environments (free, local)
- [ ] `larakube new testapp` (or `init` on an existing Laravel app) — confirm `.larakube.json`'s `environments` has **only** `local`. No `production` key, no `.env.production` file.
- [ ] `larakube env production` — the ingress/managed/hosts/additional-hosts/registry wizard runs; confirm `.env.production` is created (copied from `.env`) and `.gitignore` gains a `.env.*` line.

### Phase B — `cloud:configure` consolidation (free, no real cloud target needed)
- [ ] `larakube list | grep configure` — only `cloud:configure` and `cloud:configure:tunnel` appear. No `:base`/`:gha`/`:gitlab`/`:registry`.
- [ ] `larakube cloud:configure production --only=hosts` on an env not yet in the blueprint — errors cleanly ("not in your blueprint").
- [ ] `larakube cloud:configure production --only=bogus` — errors "Unknown --only value".
- [ ] On an env whose `web` host is already set, `cloud:configure {env} --only=hosts` — the prompt shows the **existing** host as its default, not blank.
- [ ] Re-run bare `cloud:configure {env}` on an env that already has a saved deploy target — confirm the "already targets X — reconfigure?" prompt appears defaulting to **No**, and declining leaves `.larakube.local.json` untouched.

### Phase C — Grafana `/etc/hosts` fix (free, local cluster)
- [ ] On a project that has **never** run `monitor:init`, run `larakube up`, then `grep grafana /etc/hosts` — no entry should exist.
- [ ] Run `larakube monitor:init`, then `larakube up` again — **now** `grafana.<tld>` should appear in `/etc/hosts` and resolve.

### Phase D — Multi-host web hosts (free locally; optional 💰 step for real DNS)
- [ ] `larakube env staging --edit` (or fresh `larakube env staging`) — when asked "Any additional hostnames," enter e.g. `admin.staging.example.com, api.staging.example.com`.
- [ ] Check `.larakube.json`: `environments.staging.additionalWebHosts` has both.
- [ ] `larakube kustomize staging` — the rendered Ingress has 3 `rules[]` entries (primary + 2 additional), the same backend service on each, and all 3 listed under `tls[0].hosts`.
- [ ] Locally: set an `additionalWebHosts` entry on `local` (e.g. `admin.myapp.kube`), run `larakube up` — confirm `/etc/hosts` gets an entry for it, and `https://admin.myapp.kube` loads with a trusted cert (no browser warning) — proves the local CA's extra-SAN fix works, including for a name that isn't a subdomain of the project's own TLD.
- [ ] 💰 On a real cloud env with DNS you control: point the additional host's DNS at the ingress IP, `cloud:deploy {env}` — confirm HTTPS works on the additional host with its own independently-issued Let's Encrypt cert, and the post-deploy DNS guidance lists every host, not just the primary.

### Phase E — `larakube env {name} --edit` (free, local)
- [ ] On an existing environment, `larakube env staging` with **no flag** — prints "already exists in DNA; keeping current settings. Pass --edit..." and makes zero changes.
- [ ] `larakube env staging --edit` — every prompt (ingress, managed services, hosts, additional hosts, registry) shows the **current** value as its default; accept everything — `.larakube.json` is byte-identical afterward.
- [ ] `larakube env staging --edit` again, this time actually change the ingress controller (e.g. Traefik → Nginx) — confirm only `ingress` changed; `cloud`, `resources`, `storageClass`, etc. (if set) are untouched.

### Phase F — 💰 `cloud:create` naming (needs a DigitalOcean account + API token — real droplet/cluster cost)
- [ ] Outside any project directory: `larakube cloud:create mycompany-infra` → pick VPS → the stack-name prompt should default to `mycompany-infra-vps`, not `larakube-mycompany-infra-vps`.
- [ ] Inside a project (`cd` into one with `.larakube.json`): `larakube cloud:create staging` → pick VPS → default stack name is `{projectname}-vps` — no "staging" anywhere in it.
- [ ] Still inside the same project, run `larakube cloud:create production` (a **different** environment) → pick VPS again → confirm you're asked "Attach this environment to your existing '{projectname}-vps' stack instead of creating a new one?" defaulting to **Yes** — confirming lets `production` share the same droplet as `staging`, each in its own `{app}-{env}` namespace.
- [ ] For the same project, run `cloud:create` again and pick "managed" this time → confirm the default name is `{projectname}-managed` (a *different* stack from the vps one — no collision) and that a later re-run offers to attach to `{projectname}-managed` specifically, not the vps stack.
- [ ] Destroy anything provisioned in this phase (`larakube cloud:destroy`) once verified, to stop the billing.

## 🆕 v0.24.0 — `env:audit` Manual Test Guide (2026-07-04 session batch)

Covers commit `40241cc` (tag `v0.24.0`): `env:audit`, the checklist command for
"a teammate with edit/admin access is leaving — what do I even rotate?" (see
`app/Commands/EnvAuditCommand.php`). The automated suite fakes every kubectl
call (`tests/Feature/EnvAuditCommandTest.php`, `tests/Unit/Commands/
EnvAuditCommandTest.php`) — it has never actually round-tripped through a
real `laravel-secrets`/`laravel-config` object. This is that walkthrough.
Free, local — no cloud cost.

### Phase G — `env:audit` against a real deploy
- [ ] Prereq: a project that has deployed at least once to some environment (`larakube up` for local, or `cloud:deploy` for a real env) — `laravel-secrets` + `laravel-config` must actually exist in that namespace.
- [ ] Add one throwaway custom key to `.env.{environment}` that would never come from LaraKube itself, e.g. `AIRTABLE_API_KEY=key_live_test123`, and redeploy (`larakube up`, or `cloud:deploy` for a cloud env) so it lands in `laravel-secrets`.
- [ ] `larakube env:audit {environment}` (or bare `env:audit` inside a project with one env — should auto-pick it) — confirm it prints the env/namespace/context line, then a **Secrets** table listing every key in `laravel-secrets`, and a **Config** table for `laravel-config`.
- [ ] In the Secrets table, confirm `AIRTABLE_API_KEY` (or whatever you added) is classified **custom**, and a LaraKube-generated key (`DB_PASSWORD` for a non-sqlite DB, or `APP_KEY`) is classified **LaraKube-managed**.
- [ ] **Safety check (the actual point of the command):** confirm the literal value `key_live_test123` never appears anywhere in the output — only the key name `AIRTABLE_API_KEY`. Same for every other secret's value (DB password, etc.).
- [ ] Remove the throwaway key from `.env.{environment}` and redeploy, so it doesn't linger in a real cluster.

### Phase H — Edge cases (free, local)
- [ ] Run `env:audit {environment}` on an environment that's never been deployed (no `laravel-secrets`/`laravel-config` yet) — confirm it prints "No 'laravel-secrets' or 'laravel-config' found ... nothing deployed yet?" and exits 0, not an error.
- [ ] Run bare `env:audit` **outside any project directory** with no arguments — confirm it errors "Provide a namespace, or run inside a project to pick an environment." and exits 1.
- [ ] Standalone form: `env:audit <namespace> --context <kube-context>` outside a project — confirm it runs against that literal namespace/context and every key in the Secrets table is classified **unknown (no project context)** rather than managed/custom (there's no `.larakube.json` to diff against).

## 🆕 v0.25.0 + v0.26.0 — Plex Migrate/Join/Leave Manual Test Guide (2026-07-05 session batch)

Covers commits `06d3d13` (tag `v0.25.0`) and `bc4556c` (tag `v0.26.0`):
`plex:migrate` gains object-storage (MinIO) support alongside the database,
`plex:join` detects existing self-hosted data for BOTH DB and storage (not
just DB, as before) and offers to migrate it inline, `plex:leave` gains a
full two-phase restore-then-drop path, and the custom `--yes` flag is gone
everywhere in favor of Artisan's built-in `--no-interaction`. Also covers the
`StorageDriver` `AWS_URL` bucket-segment fix and the `ExecCommand`
masked-error fix from the same batch. Needs a real cluster with a Commons
(`plex:init`) and a project using Postgres + MinIO with real data in both.
Free, local — no cloud cost unless noted.

### Phase I — `plex:migrate` storage + idempotency (local, free)
- [ ] On a project with self-hosted Postgres + MinIO holding real data, run `larakube plex:migrate local` — confirm ONE combined confirm/quiesce window covers both, `Dumping data from self-hosted database...` and `Mirroring files into Commons bucket '...'...` both run, and the app's pods pause/resume exactly once (watch `kubectl get pods` mid-run).
- [ ] After it completes, confirm the files that existed in the self-hosted MinIO bucket now exist in the Commons bucket (`larakube shell minio` targeting `larakube-shared`, then `mc ls local/<tenant-bucket>`), and spot-check a DB table's row count matches.
- [ ] Re-run `larakube plex:migrate local` immediately after a successful run — confirm it prints "already Commons-managed ... will only check for a leftover PVC" for both services and does NOT re-pause the app or re-dump/re-mirror anything.
- [ ] Simulate a partial prior run (one service migrated, the other's PVC still present) — confirm the re-run only processes the un-migrated service.

### Phase J — `plex:migrate` PVC-deletion UX (local, free)
- [ ] With real leftover PVCs after a successful migrate, confirm the "Which self-hosted PVC(s) should be deleted?" checkbox lists each by label+name, defaults to all selected, and deselecting one keeps its PVC (prints the manual `kubectl delete pvc ...` line for it).
- [ ] At the "Type the project name" prompt, type the WRONG name — confirm "Confirmation failed — no PVCs deleted" and nothing is deleted.
- [ ] Type the correct name — confirm only the selected PVC(s) are actually deleted (`kubectl get pvc -n <namespace>` before/after).
- [ ] `larakube plex:migrate local --no-interaction` with leftover PVCs — confirm it does NOT delete them (kept, with manual instructions printed) even though prompts are disabled — this is deliberate, not a bug.

### Phase K — `plex:join` existing-data detection (local, free)
- [ ] Self-host a fresh Postgres + MinIO with real data (skip `plex:migrate`), run `larakube plex:join local` — confirm it detects BOTH the DB and storage as existing data (not just DB, like before this batch) and asks "Migrate this data into the Commons now instead of refusing to join?".
- [ ] Answer **no** — confirm mixed mode: only the services WITHOUT existing data (e.g. Redis) get joined; postgres/minio are dropped from the join with no error.
- [ ] Re-run with `larakube plex:join local --migrate` — confirm it skips the question entirely and delegates straight to `plex:migrate`.
- [ ] Re-run with `larakube plex:join local --no-interaction` (existing data still present, no `--migrate`) — confirm it does NOT block waiting for input; it silently resolves to "no" and falls into mixed mode (only `--migrate` bypasses straight to migrating; `--no-interaction` alone must never silently copy real data).

### Phase L — `plex:leave` two-phase (local, free)
- [ ] On an already-joined tenant, run `larakube plex:leave local` (no `--restore`) — confirm it warns what will EVENTUALLY be dropped, asks you to type the app name, then clears `managed`/`plex` in `.larakube.json` and regenerates manifests — confirm NOTHING in `larakube-shared` changes (`kubectl get pvc,deploy -n larakube-shared` before/after).
- [ ] Follow the printed next step (`larakube up` for local) — confirm the self-hosted `postgres`/`minio` pods come back (fresh/empty).
- [ ] Run `larakube plex:leave local` again with no flag — confirm it detects the self-hosted pods are now Ready and asks "Restore the Commons data now and finish leaving?" instead of just re-running step 1.
- [ ] Answer yes — confirm: DB dumped from the Commons and restored into the self-hosted Postgres (row counts match), the MinIO bucket mirrored back into the self-hosted bucket, and only THEN is the Commons DB/login dropped, the Redis index flushed, the Commons bucket deleted, and the tenant removed from `plex-registry`.
- [ ] Verify the app actually works against the restored self-hosted data afterward (visit the site; previously-uploaded files/rows are present).
- [ ] Separately, run `plex:leave {env} --restore` BEFORE redeploying (self-hosted pod doesn't exist / isn't Ready) — confirm it refuses with "isn't Ready yet" and touches nothing on the Commons.

### Phase M — `--no-interaction` replaces `--yes` (local, free)
- [ ] `larakube plex:join local --help` / `plex:migrate local --help` / `plex:leave local --help` — confirm none list a custom `--yes` option (only Symfony's global `--no-interaction`/`-n`).
- [ ] `larakube plex:join local --no-interaction` on a FRESH join (no existing data) — confirm it joins all eligible services with zero prompts, same as the old `--yes` used to.
- [ ] `larakube plex:migrate local --no-interaction` — confirm "Proceed with migration?" auto-resolves to yes (the copy runs) but PVC deletion still behaves per Phase J's last item.

### Phase N — `StorageDriver` / `ExecCommand` fixes (local, free)
- [ ] On a project with self-hosted MinIO, check `.env`'s `AWS_URL` — confirm it includes the bucket segment (`https://s3.<tld>/laravel`), not just the bare host.
- [ ] Open a `Storage::url('public/somefile.jpg')` link in a browser — loads correctly without manually appending the bucket.
- [ ] `larakube exec --service=minio "<a command that fails inside the container>"` — confirm the REAL error from the command prints, not a generic "OCI runtime exec failed ... no such file or directory".

## ✅ Persisted task tracker

### Manual test phases (validate the v0.21.x batch before wider rollout)
- [ ] **#5 — Phase 0: Smoke** (do first; if `larakube up` fails, stop). `larakube about` boots; `larakube up` → pods Running + URLs render; `./php vendor/bin/pest` all green.
- [ ] **#6 — Phase 1: `config:tld` TLD propagation** (HIGHEST RISK). `View::share` removal + fresh-TLD companion fix. Set `.kube` → `companion:add` → `up` → switch to `.test`; ALL hosts move, ZERO stranded on `.kube`.
- [ ] **#7 — Phase 2a: `ext:add`** (imagick → no crash, idempotent, Dockerfile updated).
- [ ] **#8 — Phase 2b: `ext:remove`** (clean removal, not-installed is a no-op).
- [ ] **#9 — Phase 2c: `companion:add` project-aware recommendation** (pgAdmin for Postgres, phpMyAdmin for MySQL/MariaDB, RedisInsight for Redis).
- [ ] **#10 — Phase 3.1: Mailpit + Monitoring** (shared-cluster infra; guide §7, §19–20).
- [ ] **#11 — Phase 3.2: Companions / phpMyAdmin auto-config** (guide §2–3).
- [ ] **#12 — Phase 3.3: Plex init/export/join + `plex:migrate`** (guide §15–17, §10).
- [ ] **#13 — Phase 3.4: clone + bundle CA + bundle tunnel** (guide §9, §11, §21).
- [ ] **#14 — Phase 3.5: tunnels/share + `cloud:init` context** (guide §4–5, §6).
- [ ] **#15 — Phase 3.6: DOKS + Homebrew + GitLab + registry** (guide §12, §13, §14, §18).
- [ ] **#16 — Reverb realtime** (now fixed; re-verify browser WSS on a second project, e.g. `kube-examples/qr-link-tracker`).
- [x] **#17 — Pre-commit gate: naming hard-rule scan** (DONE this session; re-run on the full staged diff before any tag).

### Docs backlog (do after testing; `larakube-docs` repo unless noted)
- [ ] **#18 — Monitoring stack** — expand `development/monitoring.md` (`monitor:init`, exporters, `cloud:configure:monitoring`). Note: a draft `docs/development/monitoring.md` + `sidebars.ts` entry are already uncommitted in the docs repo.
- [ ] **#19 — GitLab CI deploy** — new page for `cloud:configure --only=ci` on a GitLab remote (mirror `github-actions.md`); register in `sidebars.ts`. Note: the §7.2 consolidation (`plans/active/paas-core-expansion.md`) already **shipped** — `cloud:configure:gitlab` is gone, GitLab is now auto-detected by `--only=ci` from the git remote (no `--platform=gitlab` flag exists — that's deferred to unbuilt Phase 1). Document the current auto-detect surface.
- [ ] **#20 — Custom local TLDs** — `config:tld` + multi-TLD (networking.md / configuration.md).
- [ ] **#21 — Tunnels** — `cloud:configure:tunnel` in `development/tunneling.md`.
- [ ] **#22 — Companions** — add/list/remove + recommendations (commands/management.md).
- [ ] **#23 — Plex data migration** — `plex:migrate` (deployment/multiple-projects.md / plex-resources.md).
- [ ] **#24 — PHP extensions** — `ext:add` / `ext:remove` (onboarding/configuration.md).
- [ ] **#25 — `larakube clone`** — give it a real home (commands/management.md).
- [ ] **#26 — bundle custom CA** — `bundle:build --ca-cert/--ca-key` (deployment/airgapped-bundles.md).
- [ ] **#27 — command-reference sweep + changelog + sidebars** — add every new command to `commands/*.md`, write the release changelog entry, register new pages. ⚠️ **Partial defer:** hold the `cloud:configure:*` entries (base/gha/gitlab/registry) until after v1.0.0 §7.2 consolidates them into one `cloud:configure --platform/--registry/--only` surface; the rest of the sweep is safe now.
- [ ] **#28 — verify existing pages now accurate** — reverb.md (local ingress now true), `about` env table, mariadb 11+, doks-quickstart.

### Done this session (context, not tasks)
- Reverb local ingress + `VITE_REVERB_*`; Boost pint command fix (`larakube php vendor/bin/pint`).
- k9s auto-install; removed stale companion wizard prompt.
- `cloud:provision*` → `cloud:init*` rename (backward-compat aliases) — code + docs.
- `monitor:init` environment prompt; `larakube trust` Windows-admin hint.
- Homebrew tap pipeline wired (CI stamps the formula on tag); released through **v0.21.3** (unpushed at chat-clear time — check `git log origin/main..HEAD`).

> Detailed, step-by-step procedures for everything below. The two source files
> (testing checklist + manual-test guide) are now merged into this one document.

---

# LaraKube CLI — Batch Testing Checklist

Single source of truth for validating the current large staged batch (~137 files)
before tagging/committing. Detailed step-by-step lives in the **Manual Test Guide**
section further down in this file; this part is the tickable tracker. Test by
**blast radius** — shared infrastructure first, then per-feature.

> Build step before testing: `./php vendor/bin/pint && ./build`

---

## Phase 0 — Smoke (do first; if 0.2 fails, stop)

- [ ] `larakube about` boots, no stack trace
- [ ] `larakube up` in a test project → pods Running, summary URLs render
- [ ] `./php vendor/bin/pest` → all green, no output leaks

---

## Phase 1 — config:tld TLD propagation regression (HIGHEST RISK)

Tests this batch's `View::share` removal + fresh-TLD `deployCompanion` fix. The
original bug: Mailpit migrated to the new TLD but phpMyAdmin/RedisInsight did not.

```bash
larakube config:tld --set kube
larakube companion:add          # phpMyAdmin (needs MySQL/MariaDB) + RedisInsight (needs Redis)
larakube up                      # note hosts on .kube
larakube config:tld --set test   # chains `up` internally
kubectl get ingress -A | grep -E 'phpmyadmin|redisinsight|mailpit|grafana|console|traefik'
```

Pass — ALL on `.test`, ZERO stranded on `.kube`:
- [ ] Companions: `phpmyadmin.test`, `redisinsight.test`
- [ ] Shared services: `mailpit.test`, `grafana.test` (if monitoring active), Console, Traefik dashboard
- [ ] Project web/vite hosts: `*.<app>.test`
- [ ] Browse `https://phpmyadmin.test` AND `https://mailpit.test` → both load
- [ ] Restore: `larakube config:tld --set kube`

---

## Phase 2a — ext:add (not in the Manual Test Guide below)

- [ ] `larakube ext:add imagick` → no crash (addAdditionalExtension fatal gone)
- [ ] `imagick` in `.larakube.json` → `additionalExtensions`
- [ ] `Dockerfile.php` `install-php-extensions` line gains `imagick`, prior exts preserved
- [ ] Re-run `ext:add imagick` → idempotent (no dup in config or Dockerfile)
- [ ] Outside a project → clean "Not a LaraKube project", exit 1, no trace

## Phase 2b — ext:remove (not in the Manual Test Guide below)

- [ ] `larakube ext:remove imagick` → no crash (removeAdditionalExtension fatal gone)
- [ ] `imagick` removed from `.larakube.json` and stripped from the Dockerfile line, other exts intact
- [ ] `ext:remove notinstalled` → clean "not in your configuration, skipping", exit 0
- [ ] After add+remove+`larakube up`, confirm via `larakube shell` → `php -m`

---

## Phase 2c — companion:add project-aware recommendation (NEW this batch)

- [ ] In a **Postgres** project: `larakube companion:add` → pgAdmin at top, pre-selected, tagged `(recommended)`; Adminer next
- [ ] In a **MySQL/MariaDB** project: phpMyAdmin tops, tagged `(recommended)`
- [ ] Project with **Redis** cache: RedisInsight appears in the recommended set
- [ ] Outside a project (or SQLite-only) → falls back to the flat list, no errors
- [ ] Explicit `larakube companion:add pgadmin` → unchanged (no reordering)

---

## Phase 3 — Walk the Manual Test Guide (below) §1–21 (ordered by blast radius)

- [ ] §7 Mailpit + §19–20 Monitoring (shared-cluster infra)
- [ ] §2–3 Companions / phpMyAdmin auto-config
- [ ] §15–17 Plex init/export/join + §10 plex:migrate
- [ ] §9 clone, §11 bundle CA, §21 bundle tunnel
- [ ] §4–5 tunnels/share, §6 cloud:init context
- [ ] §12 DOKS, §13 Homebrew, §14 GitLab, §18 registry

---

## Pre-commit gate

- [ ] Naming hard-rule scan: grep the staged diff for the forbidden test-app codename (see private naming memory) and for bare "LaraKube" used as a product name. Must find: zero codename hits; no bare "LaraKube" product-name (exceptions: `larakube` commands, "LaraKube CLI", "LaraKube Console", "LaraKube Local CA").

---

## Reverb realtime — NOW FIXED (test it; was previously excluded)

The two local-scaffolding gaps are fixed in this batch (`LaravelFeature` + new
`resources/views/k8s/reverb/ingress.blade.php`). Confirmed working on
`kube-examples/todo` this session.

- [x] Reverb Ingress generated — `getNetworkViewName()`/`getNetworkYamlDestination()`
      now emit `overlays/local/reverb-ingress.yaml` (`reverb.{name}.{localTld}` →
      `reverb:8080`, websecure+TLS), registered in the local overlay kustomization.
- [x] Local `VITE_REVERB_*` scaffolded — `getPublicEnvironmentVariables()` emits
      `VITE_REVERB_APP_KEY`/`HOST` (via `getServiceHost`)/`PORT=443`/`SCHEME=https`
      for the local env into the project `.env`.
- [ ] Re-verify on a second project (e.g. `kube-examples/qr-link-tracker`):
      enable reverb → `larakube up` → browser Echo connects over WSS:443, realtime
      updates without refresh.
# LaraKube CLI — Manual Test Guide

Run these scenarios after any major feature batch. Append a new section for each new feature finished.

---

## Prerequisites

- A working local K3s cluster: `larakube up` succeeds on your test app
- `larakube` binary on `$PATH` (`./build` completed)
- Test app at `~/Codes/MCM/codes/<testapp>` (see project memory)

---

## 1. `config:tld` — Local TLD override

**Goal:** Change `.kube` to another TLD and verify local URLs update.

```bash
# Check current TLD
larakube config:tld

# Change to .local
larakube config:tld --set local

# Verify it was saved
larakube config:tld
# Should output: local

# Run up and confirm URLs use .local
larakube up
# Expect: web URL uses https://app.myapp.local (not .kube)

# Restore to default
larakube config:tld --set kube
```

**Pass criteria:** URLs in the `larakube up` summary match the configured TLD.

---

## 2. Companion Apps — `companion:add / list / remove`

**Goal:** Add, list, and remove companion apps. Verify they are displayed after `larakube up`.

```bash
cd ~/path/to/test-app

# Add phpMyAdmin (requires MySQL/MariaDB)
larakube companion:add
# Pick: phpMyAdmin
# Confirm it was saved

# List installed companions
larakube companion:list
# Should show: phpMyAdmin ✓

# Run up and verify access block appears at the end
larakube up
# Expect near the bottom:
#   ─── Companion Apps ───────────────
#   phpMyAdmin  → https://phpmyadmin.myapp.kube

# Remove it
larakube companion:remove
# Pick: phpMyAdmin
larakube companion:list
# Should show: (none)
```

**Also test Adminer** (add it, check the URL includes the correct query param for your DB driver):
- MySQL/MariaDB → `?server=mysql`
- PostgreSQL → `?pgsql=pgsql`
- MongoDB → `?mongo=mongo`

**Pass criteria:** Companion URLs show up in `larakube up` output. Correct query param per driver.

---

## 3. phpMyAdmin Auto-Server Config (`refreshPhpMyAdminServers`)

**Goal:** When phpMyAdmin is installed alongside MySQL/MariaDB, `larakube up` automatically patches PMA_HOSTS so phpMyAdmin can connect without manual config.

**Setup:** Project must use MySQL or MariaDB as the database driver.

```bash
# Ensure phpMyAdmin is installed
larakube companion:list
# Should include: phpMyAdmin

larakube up

# After up completes, verify phpmyadmin pod env
kubectl get deployment phpmyadmin -n <your-namespace> -o jsonpath='{.spec.template.spec.containers[0].env}' | jq .
# Should contain PMA_HOSTS with your app's MySQL FQDN

# Verify phpmyadmin ConfigMap was created/updated
kubectl get configmap phpmyadmin-hosts -n <your-namespace> -o yaml
```

**Multi-project test:**

```bash
cd project-a
larakube up
# PMA_HOSTS now includes project-a's DB FQDN

cd project-b
larakube up
# PMA_HOSTS should now include BOTH project-a and project-b FQDNs
```

**Pass criteria:** After each `larakube up`, `PMA_HOSTS` in the phpMyAdmin deployment contains the FQDN for that project's DB. Multi-project: both FQDNs accumulate in the ConfigMap.

---

## 4. `cloud:configure:tunnel` — Persistent Cloudflare / Localtonet Tunnel

**Goal:** Deploy a persistent ingress tunnel for a cloud environment that lacks open ports.

> **Prerequisites:** A cloud environment configured in `.larakube.json` (production or staging). A Cloudflare Zero Trust account with a named tunnel created, OR a Localtonet account.

### 4a. Cloudflare Tunnel

```bash
# Configure (interactive)
larakube cloud:configure:tunnel production

# Or non-interactive
larakube cloud:configure:tunnel production --provider=cloudflare --token=<TOKEN>

# Verify the K8s objects were created
kubectl get secret cloudflare-tunnel-token -n <appname>-production
kubectl get deployment cloudflare-tunnel -n <appname>-production
kubectl get pods -n <appname>-production | grep cloudflare

# Check .larakube.json updated
cat .larakube.json | jq '.environments.production.tunnel'
# Should show: {"provider": "cloudflare"}

# Remove the tunnel
larakube cloud:configure:tunnel production --remove
kubectl get deployment cloudflare-tunnel -n <appname>-production
# Should be gone
```

### 4b. Localtonet Tunnel

```bash
larakube cloud:configure:tunnel production --provider=localtonet --token=<TOKEN>

kubectl get deployment localtonet-tunnel -n <appname>-production
kubectl logs deployment/localtonet-tunnel -n <appname>-production
# Should show connected/active status
```

**Pass criteria:** Tunnel pod is Running, `.larakube.json` has the provider set, `--remove` tears everything down cleanly.

---

## 5. `larakube share` — Cloudflare Tunnel (Local Dev)

### 5a. Quick tunnels (no token — Path A)

```bash
# Ensure the local project is up
larakube up

# Start sharing without a token
larakube share
# Should print:
#   No Cloudflare token found — using quick tunnels (random URLs).
# One pod per exposed service (web, optionally node HMR, optionally storage)
# Shows random trycloudflare.com URLs

# In another terminal, verify pods
kubectl get pods -n <namespace> -l larakube.dev/role=share

# Check env patches on web deployment
kubectl get deployment web -n <namespace> -o jsonpath='{.spec.template.spec.containers[0].env}' | jq .
# Should contain AWS_URL if the project uses object storage

# Stop
larakube share --stop
# Pods should be removed, env vars restored
kubectl get pods -n <namespace> -l larakube.dev/role=share
# Should show: No resources found
```

### 5b. Named tunnel (with token — Path B)

```bash
# First run — will prompt for stable URLs
larakube share --token=<CLOUDFLARE_NAMED_TUNNEL_TOKEN>
# Prompts: URL for web, HMR (if Vite frontend), storage (if MinIO/Garage/SeaweedFS)
# After entry: deploys ONE cloudflared pod, applies env patches, keeps alive

# Second run — reuses saved URLs (no prompts)
larakube share --token=<TOKEN>

# Reset URLs and re-prompt
larakube share --token=<TOKEN> --reset

# Verify env var saved in global config
cat ~/.larakube/config.json | jq '.share_token, .share_urls'
```

### 5c. Livewire project (no Vite pod)

```bash
# In a project where frontend = livewire:
larakube share
# Should NOT create a larakube-share-hmr pod
# Should NOT try to patch VITE_HMR_HOST on a non-existent node deployment
kubectl get pods -n <namespace> -l larakube.dev/role=share
# Should only show: larakube-share-web
```

**Pass criteria:**
- Quick tunnels: one pod per active service, trycloudflare.com URLs printed, `--stop` cleans up
- Named tunnel: single pod, stable URLs printed, reused on second run
- Livewire: no HMR pod deployed

---

## 6. `cloud:init` — Context Not Switched

**Goal:** Verify that `cloud:init` no longer silently changes your active kubectl context.

```bash
# Check your current context BEFORE
kubectl config current-context

# Run cloud:init (use a dummy/test VPS or watch the Traefik deploy step)
larakube cloud:init vps
# Step through until the "Deploy Traefik" prompt; say yes

# Check context AFTER
kubectl config current-context
# Should still be the SAME context as before (e.g. your local K3s context)
# NOT switched to larakube-<ip>
```

**Pass criteria:** Active kubectl context is unchanged after `cloud:init` runs Traefik deployment.

---

## 7. Shared Mailpit — Catch-All SMTP in `larakube-shared`

**Goal:** Verify that `larakube up` deploys Mailpit once into `larakube-shared` (not per-project), and every project automatically has MAIL_* pointing to it.

```bash
# First larakube up on any project
larakube up
# Near the end of Traefik setup, expect:
#   Starting shared Mailpit (catch-all SMTP)...

# Verify Mailpit landed in larakube-shared (not in the app namespace)
kubectl get deployment mailpit -n larakube-shared
kubectl get svc mailpit -n larakube-shared
# Both should exist

# Verify Mailpit web UI is reachable
open https://mailpit.kube
# (or mailpit.<your-tld> if you changed TLD)

# Run a second project's larakube up
cd /path/to/project-b
larakube up
# The "Starting shared Mailpit" step should be near-instant (pod already running)

# Verify MAIL_HOST is injected into any project's ConfigMap
kubectl get configmap -n <appname>-local -o yaml | grep MAIL_HOST
# Should show: MAIL_HOST: mailpit.larakube-shared.svc.cluster.local

# Companion block in larakube up output should always show:
#   Mailpit: https://mailpit.kube
```

**Also test cross-project mail:**
1. Send mail from project-a (e.g. trigger a `larakube artisan tinker` → `Mail::raw(...)`)
2. Open `https://mailpit.kube` — the email should appear
3. Send mail from project-b — appears in the same inbox

**Pass criteria:**
- Mailpit runs in `larakube-shared`, not in any app namespace
- Second `larakube up` skips re-deploying (idempotent)
- `MAIL_HOST=mailpit.larakube-shared.svc.cluster.local` in every local project's ConfigMap
- Web UI accessible at `https://mailpit.<tld>` and catches mail from all projects

---

## 8. Plex Local Support — `plex:join local` / `plex:status local`

**Goal:** Verify Plex commands now work on the local environment with a warning instead of hard-stopping.

```bash
cd /path/to/test-app   # must have Plex-eligible services (MySQL/Postgres/Redis)

# plex:init should already work on local (no guard was there)
# plex:join local — should warn, not error
larakube plex:join local
# Expect:
#   ⚠  You are joining Plex in a local environment.
#      Plex commons data will be lost when you run larakube down...
#   Continue anyway? [y/N]
# Answer: y — should proceed to join

# plex:status should show the local Commons
larakube plex:status local
# Should list Commons services and tenant

# plex:leave local — should warn
larakube plex:leave local
# Expect warning + confirm before proceeding

# plex:destroy local — should warn with destructive language
larakube plex:destroy local
# Expect a stronger warning about deleting larakube-shared
```

**Pass criteria:** All four commands warn on `local` and prompt to continue. No hard error. Saying `n` exits cleanly without touching the cluster.

---

## 9. `larakube clone` — Clone and Prepare in One Command

```bash
# Full URL
larakube clone https://github.com/laravel/laravel

# user/repo shorthand (defaults to GitHub HTTPS)
larakube clone laravel/laravel

# Custom directory
larakube clone laravel/laravel my-test-app

# Branch
larakube clone laravel/laravel --branch=10.x

# Verify APP_URL is patched to use local TLD
cat my-test-app/.env | grep -E "APP_URL|ASSET_URL"
# Should show: APP_URL=https://laravel.kube  (directory name + TLD)

# Already a LaraKube CLI project (has .larakube.json) — init skipped
larakube clone user/my-configured-app
```

### 9b. Provider flag (Phase 2)

```bash
# GitLab shorthand
larakube clone myorg/myapp --provider=gitlab
# Clones from: https://gitlab.com/myorg/myapp.git

# Bitbucket shorthand
larakube clone myorg/myapp --provider=bitbucket
# Clones from: https://bitbucket.org/myorg/myapp.git

# Full URL always bypasses provider (provider flag is ignored)
larakube clone https://gitlab.com/myorg/myapp.git --provider=github
# Still clones the GitLab URL as-is

# Unknown provider should error cleanly
larakube clone myorg/myapp --provider=codeberg
# Expect: error "Unknown provider 'codeberg'. Use: github, gitlab, or bitbucket."
```

**Edge cases:**

```bash
# No .env.example — must hard-stop before composer install
larakube clone user/repo-without-env-example
# Expect error: "No .env.example found... Cannot bootstrap .env"

# Directory already exists
larakube clone laravel/laravel laravel
# Expect error: "Directory 'laravel' already exists."

# Not a PHP project (no composer.json)
larakube clone user/some-non-php-repo
# Should warn + offer to continue or abort

# Project with Plex services in .larakube.json
larakube clone user/my-plex-app
# After install, expect:
#   "This project uses Plex commons for: database, redis. Join the local Plex commons now? [y/N]"
```

**Pass criteria:**
- `.env` copied from `.env.example`; `APP_URL`/`ASSET_URL` patched to `https://<dir>.<tld>`
- No `.env.example` → hard error, nothing else runs
- `.larakube.json` present → init skipped; Plex services detected and offered
- `.larakube.json` absent → `larakube init` runs automatically
- `--provider=gitlab` / `--provider=bitbucket` resolves to correct host; full URLs bypass provider

---

## 10. `plex:migrate` — Move Existing Database Data to the Commons

**Goal:** Verify that `plex:migrate` copies a self-hosted DB into the shared Commons, then hands off to `plex:join` to complete credentials and manifest wiring.

> **Prerequisites:**
> - A LaraKube CLI project with MySQL, MariaDB, or PostgreSQL as the DB driver
> - The project is running (`larakube up` or cloud-deployed) so the DB pod + PVC exist
> - A Plex Commons (run `larakube plex:init` if not done yet)

### 10a. Basic migration (production environment)

```bash
cd /path/to/your-app

# Verify self-hosted DB is running
kubectl get deploy mysql -n <appname>-production
kubectl get pvc <appname>-mysql-pvc -n <appname>-production

# Run the migration (will prompt for confirmation)
larakube plex:migrate production

# Expected output:
#   Source: MySQL in <appname>-production
#   Target: Commons MySQL in larakube-shared (tenant: <appname>)
#   ⚠  This will COPY data from the self-hosted pod to the Commons...
#   Proceed with migration? [y/N] → y
#   ✓ Allocating database '<appname>' in the Commons...
#   ✓ Dumping data from self-hosted database...
#     Dump size: 42.7 KB
#   ✓ Restoring data into Commons tenant '<appname>'...
#   ✓ Data migrated successfully.
#   Delete the self-hosted PVC now? [y/N] → y
#   ✓ Running plex:join to finalise credentials and manifests…

# Verify tenant exists in Commons registry
kubectl get configmap plex-registry -n larakube-shared -o jsonpath='{.data.registry\.json}' | jq .
# Should show your appname as a tenant

# Verify .env.production was updated with Commons connection vars
cat .env.production | grep -E "DB_HOST|DB_DATABASE|DB_PASSWORD"
# Should show: DB_HOST=mysql.larakube-shared.svc.cluster.local

# Verify .larakube.json has mysql in managed + plex
cat .larakube.json | jq '.environments.production'
# Should show: "managed": ["mysql"], "plex": ["mysql"]
```

### 10b. Keep PVC after migration

```bash
larakube plex:migrate production --keep-pvc

# The PVC deletion step should be skipped entirely
# PVC should still exist after:
kubectl get pvc <appname>-mysql-pvc -n <appname>-production
# Should still be there

# Manually delete when confident:
kubectl delete pvc <appname>-mysql-pvc -n <appname>-production
```

### 10c. Skip-confirm mode

```bash
larakube plex:migrate production --yes

# No prompts — goes straight through confirmation + PVC deletion
```

### 10d. Local environment

```bash
# For local dev environment (uses current K3s context)
larakube plex:migrate local

# Same flow, target is larakube-shared on the local K3s cluster
```

### 10e. Data integrity check

```bash
# After migration, verify data is in Commons
kubectl exec -n larakube-shared deploy/mysql -- \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <appname> -e "SHOW TABLES;"
# Should show the same tables as your original database
```

### 10f. Error cases

```bash
# Missing source pod (not deployed yet)
larakube plex:migrate production
# Expect error: "No 'mysql' deployment found in '<appname>-production'"

# SQLite project — not migrateable
# (plex:migrate only handles relational drivers)
larakube plex:migrate production
# Expect error: "plex:migrate only applies to relational databases"
```

**Pass criteria:**
- Data is copied from self-hosted pod to Commons tenant DB (verify via kubectl exec)
- `DB_HOST` in `.env.{env}` points to `mysql.larakube-shared.svc.cluster.local`
- `managed` + `plex` arrays in `.larakube.json` contain the db service
- `--keep-pvc` skips PVC deletion; `--yes` skips all confirms
- After migration, `plex:join production --yes` is idempotent (reports "already a tenant")

---

## 11. Custom CA Bundling — `bundle:build --ca-cert / --ca-key`

**Goal:** Verify that an on-prem company-provided CA certificate can be embedded into a LaraKube CLI bundle so that cluster nodes trust it out of the box.

> **Prerequisites:** A CA key pair (`company-ca.key` and `company-ca.crt`). You can generate a throw-away pair for testing:
> ```bash
> openssl req -x509 -newkey rsa:4096 -keyout test-ca.key -out test-ca.crt -days 365 -nodes \
>   -subj "/CN=Test CA"
> ```

### 11a. Build a bundle with a custom CA

```bash
# Build with CA embedded
larakube bundle:build --ca-cert=./test-ca.crt --ca-key=./test-ca.key

# Verify ca/ directory was written into the bundle staging area
ls -la dist/bundle/ca/
# Should contain: company-ca.crt

# Inspect the crt matches what you passed
diff test-ca.crt dist/bundle/ca/company-ca.crt
# Should be identical (no diff)
```

### 11b. Install the bundle and verify CA is trusted

```bash
# Install the bundle (e.g. onto a VPS or a fresh VM)
larakube bundle:install --bundle=./dist/larakube-bundle.tar.gz

# After install, verify the CA was placed in the system trust store
# (path depends on distro)
# Ubuntu/Debian:
ls /usr/local/share/ca-certificates/larakube/
cat /usr/local/share/ca-certificates/larakube/company-ca.crt

# RHEL/Fedora:
ls /etc/pki/ca-trust/source/anchors/
cat /etc/pki/ca-trust/source/anchors/company-ca.crt

# Verify update-ca-certificates was called (cert appears in system store)
openssl verify -CAfile /etc/ssl/certs/ca-certificates.crt test-ca.crt
# Should show: test-ca.crt: OK
```

### 11c. Bundle without CA (baseline)

```bash
# Build without CA flags
larakube bundle:build

# Verify no ca/ directory in bundle
ls dist/bundle/ca/ 2>&1
# Should show: No such file or directory

# install should complete without CA step
larakube bundle:install --bundle=./dist/larakube-bundle.tar.gz
# Should NOT mention "Installing CA certificate"
```

**Pass criteria:**
- `--ca-cert` + `--ca-key` embeds the cert into the bundle
- `bundle:install` places the cert in the OS trust store and runs `update-ca-certificates`
- Bundle without `--ca-cert` installs cleanly with no CA step

---

## 12. `cloud:init:doks` — DigitalOcean Kubernetes

**Goal:** Verify that `cloud:init:doks` installs Traefik on a DOKS cluster, surfaces the LoadBalancer IP, and optionally wires the project.

> **Prerequisites:**
> - A DigitalOcean Kubernetes cluster already created (via the DO Console or `doctl`)
> - `kubectl` context for the cluster (run `doctl kubernetes cluster kubeconfig save <cluster-name>`)
> - A domain pointing at DigitalOcean nameservers (or use a subdomain you control)

```bash
# Check the DOKS context is available
kubectl config get-contexts | grep do-

# Provision Traefik on DOKS (picks up the context interactively)
larakube cloud:init:doks
# Or pass the context directly:
larakube cloud:init:doks --context=do-sfo3-my-cluster

# Expected flow:
#   Target context: do-sfo3-my-cluster
#   Email for Let's Encrypt certificate notices → enter your email
#   Install Traefik + Let's Encrypt (HTTP-01) on this cluster? [Y/n] → y
#   ✓ Installing Traefik with a Let's Encrypt (ACME) resolver...
#   ✓ Waiting for the LoadBalancer IP...
#   ✅ LoadBalancer IP: 12.34.56.78
#   Configure an environment in this project to use this cluster now? [Y/n] → y
#     Which environment runs on this DOKS cluster? → production
#     Web domain for 'production' → app.example.com

# Verify Traefik is running
kubectl get deployment -n traefik traefik --context=do-sfo3-my-cluster
kubectl get svc -n traefik traefik --context=do-sfo3-my-cluster
# EXTERNAL-IP should show the LoadBalancer IP

# Verify .larakube.json was updated
cat .larakube.json | jq '.environments.production'
# Should show: "cloud": { "context": "do-sfo3-my-cluster", ... }, "hosts": { "web": "app.example.com" }
```

### 12b. Re-run idempotency

```bash
# Running again on a cluster that already has Traefik should skip install
larakube cloud:init:doks --context=do-sfo3-my-cluster
# Expect: "Traefik is already installed on this cluster — skipping install."
# Should still print the LoadBalancer IP
```

### 12c. Deploy to DOKS

```bash
# After DNS propagates (app.example.com → LoadBalancer IP):
larakube cloud:configure production --only=registry    # set up GHCR/Docker Hub
larakube cloud:configure production --only=ci           # upload secrets + generate workflow
# Push to main → GHA workflow deploys to DOKS

# Or deploy directly:
larakube cloud:deploy production
```

**Pass criteria:**
- Traefik + LoadBalancer IP provisioned in one command
- `.larakube.json` updated with context + web host
- Re-run skips install but still shows IP
- `cloud:deploy production` succeeds after DNS propagates

---

## 13. Homebrew Tap — Mac Installation

**Goal:** Verify that Mac users can install LaraKube CLI via `brew`.

> **Prerequisites:**
> - The `luchavez-technologies/homebrew-larakube` tap repo exists on GitHub
> - A tagged release has been published (the CI `tap` job runs on `v*` tags)

```bash
# Add the tap
brew tap luchavez-technologies/larakube

# Install
brew install larakube

# Verify
larakube --version

# Upgrade (after a new release)
brew upgrade larakube

# Uninstall
brew uninstall larakube
brew untap luchavez-technologies/larakube
```

**Formula integrity check:**

```bash
# After the CI `tap` job runs on a release tag, inspect the formula
brew info luchavez-technologies/larakube/larakube
# Should show: version, SHA256 hash, homepage: https://larakube.luchtech.dev

# Audit for common Homebrew issues
brew audit --new luchavez-technologies/larakube/larakube
```

**Pass criteria:**
- `brew install larakube` succeeds on both arm64 (M1/M2) and x86_64 Mac
- `larakube --version` prints the correct tagged version
- `brew upgrade larakube` picks up new releases automatically
- SHA256 in formula matches the binary from the GitHub release

---

## 14. `cloud:configure --only=ci` (GitLab) — GitLab CI Deploy Pipeline

**Goal:** Verify that `cloud:configure --only=ci`, run inside a project with a GitLab `origin` remote, auto-detects GitLab (via `detectCiPlatform()`), generates a valid `.gitlab-ci.yml`, and uploads CI/CD variables to GitLab. (The old dedicated `cloud:configure:gitlab` command is gone — there's no way to force GitLab when the remote is something else, e.g. a GitLab project mirrored from GitHub; that's a known gap of the auto-detect-only approach, worth surfacing if it bites someone.)

> **Prerequisites:**
> - Project hosted on GitLab (remote origin = `git@gitlab.com:...`)
> - Cloud environment provisioned (e.g. via `cloud:init:doks` or `cloud:init vps`)
> - `glab` CLI installed and authenticated (`glab auth login`) — optional but recommended

```bash
cd /path/to/your-laravel-app

# Configure for production
larakube cloud:configure production --only=ci

# Expected flow:
#   Targeting GitLab project: mygroup/myapp
#   Step 1: Uploading CI/CD variables for 'production'...
#     ✓ Uploaded PRODUCTION_KUBECONFIG
#     ✓ Uploaded PRODUCTION_ENV_FILE_BASE64
#   Which branch triggers the production deployment? [main]
#   Step 2: Generating GitLab CI pipeline...
#     Pipeline written to: .gitlab-ci.yml
#   ✅ GitLab CI configured for 'production'!

# Inspect the generated pipeline
cat .gitlab-ci.yml
# Should contain:
#   stages: [build, deploy]
#   build:production: — builds Docker image, pushes to registry
#   deploy:production: — kubectl kustomize | apply

# Verify variables were uploaded (requires glab)
glab variable list
# Should show: PRODUCTION_KUBECONFIG, PRODUCTION_ENV_FILE_BASE64 (masked)

# Push to trigger the pipeline
git add .gitlab-ci.yml && git commit -m "chore: add LaraKube CLI GitLab CI pipeline"
git push origin main
# Monitor: GitLab → CI/CD → Pipelines
```

### 14b. Multi-environment pipeline

```bash
# Add staging
larakube cloud:configure staging --only=ci
# .gitlab-ci.yml now contains build:staging + deploy:staging as well
```

### 14c. Without glab CLI

```bash
# Remove glab from PATH temporarily to test fallback
PATH_BACKUP=$PATH
export PATH=$(echo $PATH | sed 's|/opt/homebrew/bin:||')

larakube cloud:configure production --only=ci
# Should print the variables to set manually instead of uploading them:
#   PRODUCTION_KUBECONFIG = (base64 kubeconfig — run: ...)
#   PRODUCTION_ENV_FILE_BASE64 = (base64 of .env.production — run: ...)

export PATH=$PATH_BACKUP
```

**Pass criteria:**
- `.gitlab-ci.yml` generated with build + deploy jobs for each cloud environment
- `KUBECONFIG` and `ENV_FILE_BASE64` variables uploaded (masked) via `glab`
- Without `glab`: variables printed for manual setup
- Pipeline triggers on push to the configured branch
- Deployed pod is Running after the pipeline succeeds

---

## 15. `plex:init` + `plex:export` — Bootstrap and Inspect the Commons

**Goal:** Verify that `plex:init` deploys the Commons tier and `plex:export` prints connection details.

> **Prerequisites:** A running cluster (`larakube up` for local, or a provisioned cloud environment).

### 15a. Initialise on local

```bash
cd /path/to/any-laravel-app

larakube plex:init
# Expected flow:
#   ✓ Deploying Commons MySQL in larakube-shared...
#   ✓ Deploying Commons Redis in larakube-shared...
#   ✓ Commons is ready.
#   Commons MySQL root password: <generated>  ← save this

# Verify pods are running
kubectl get pods -n larakube-shared
# Should show: mysql-..., redis-...

# Verify Plex registry ConfigMap was created
kubectl get configmap plex-registry -n larakube-shared -o jsonpath='{.data.registry\.json}' | jq .
# Should show: { "tenants": {} }
```

### 15b. Initialise on a cloud environment

```bash
larakube plex:init production
# Uses the production kubeconfig context
# Same expected output — Commons lands in larakube-shared on that cluster
```

### 15c. Idempotency

```bash
# Re-run plex:init — should skip already-running services
larakube plex:init
# Expect: "Commons is already running — nothing to do."
```

### 15d. Export Commons credentials

```bash
larakube plex:export
# Expected output (table or env format):
#   COMMONS_MYSQL_HOST=mysql.larakube-shared.svc.cluster.local
#   COMMONS_MYSQL_ROOT_PASSWORD=<password>
#   COMMONS_REDIS_HOST=redis.larakube-shared.svc.cluster.local

# Verify export for cloud env
larakube plex:export production
```

**Pass criteria:**
- `plex:init` deploys MySQL + Redis into `larakube-shared`; idempotent on re-run
- `plex:export` prints usable connection details that match the Commons pods

---

## 16. `plex:join` — Enrol a Project in the Commons

**Goal:** Verify that a fresh project can join the Commons (without first migrating data).

> **Prerequisites:** Commons running (`plex:init` done). Project has a self-hosted DB that is empty or disposable.

### 16a. Basic join

```bash
cd /path/to/test-app

# Join production
larakube plex:join production
# Expected flow:
#   Joining Plex Commons for: <appname> (production)
#   ✓ Commons MySQL is reachable
#   Allocating database '<appname>' in the Commons...
#   ✓ Database allocated. tenant password: <password>
#   Remove self-hosted MySQL deployment and PVC? [Y/n] → y
#   ✓ Self-hosted MySQL removed
#   ✓ .env.production updated with Commons connection vars
#   ✓ .larakube.json updated (plex + managed)

# Verify tenant in registry
kubectl get configmap plex-registry -n larakube-shared -o jsonpath='{.data.registry\.json}' | jq .
# Should include your appname under tenants

# Verify env vars updated
cat .env.production | grep DB_HOST
# Should show: DB_HOST=mysql.larakube-shared.svc.cluster.local
```

### 16b. Join with --yes (no prompts)

```bash
larakube plex:join production --yes
# No confirmation prompts — skips all interactive steps
```

### 16c. Already a tenant (idempotent)

```bash
# Run plex:join again on an already-joined project
larakube plex:join production
# Expect: "This project is already a Plex tenant for 'production'. Nothing to do."
```

### 16d. Local environment warning

```bash
larakube plex:join local
# Expect:
#   ⚠  You are joining Plex in a local environment.
#      Plex commons data will be lost when you run larakube down...
#   Continue anyway? [y/N]
```

**Pass criteria:**
- Tenant DB allocated in Commons; `.env.{env}` and `.larakube.json` updated
- Self-hosted deployment + PVC removed (unless `--keep-pvc`)
- `plex:join` on an already-joined project is idempotent
- Local env warning shown; answering `n` exits without changes

---

## 17. `cloud:deploy` — Direct Deploy (SSH Sideload vs Registry Push)

**Goal:** Verify that `cloud:deploy` auto-selects the right strategy: SSH sideload for single-VPS (no registry), registry push for multi-node / DOKS.

> **Prerequisites:** At least one cloud environment configured in `.larakube.json`. Build your CLI first.

### 17a. SSH sideload (VPS without a registry)

```bash
cd /path/to/your-app

# Environment must have no registry configured
cat .larakube.json | jq '.environments.production.registry'
# Should be: null

larakube cloud:deploy production
# Expected flow:
#   No registry configured — using SSH image sideload.
#   Building image (linux/amd64)...
#   ✓ Image built: <appname>:production-latest
#   Copying image to server via SSH...
#   ✓ Image sideloaded onto server
#   Deploying manifests...
#   ✓ Rollout complete.

# Verify the deployment is running on the server
ssh root@<vps-ip> "kubectl get deploy -n <appname>-production"
# Should show READY replicas
```

### 17b. Registry push (DOKS or multi-node)

```bash
# Configure a registry first if not done
larakube cloud:configure registry production
# Pick GHCR / Docker Hub

larakube cloud:deploy production
# Expected flow:
#   Registry configured: ghcr.io/myorg/myapp
#   Building image (linux/amd64)...
#   ✓ Image pushed: ghcr.io/myorg/myapp:production-<sha>
#   Deploying manifests (scoped RBAC)...
#   ✓ Rollout complete.
```

### 17c. Scoped RBAC — Namespace doc stripped

```bash
# Confirm the Namespace doc was NOT applied (scoped kubeconfig can't apply it)
# Pipeline output should show the awk strip step ran without error
# Verify the namespace already exists (it was created during cloud:init)
kubectl get namespace <appname>-production
# Should exist; the deploy didn't try to re-create it
```

### 17d. Platform flag (`linux/amd64`)

```bash
# On an ARM Mac, the built image must be linux/amd64 for the server
larakube cloud:deploy production
# Check Docker buildx output in verbose mode:
#   --platform linux/amd64   ← must appear

# Verify on the server:
ssh root@<vps-ip> "docker image inspect <appname>:production-latest | jq '.[0].Architecture'"
# Should show: "amd64"
```

**Pass criteria:**
- No-registry VPS → SSH sideload; registry configured → push + pull
- `linux/amd64` platform used even on Apple Silicon
- Namespace doc stripped; rollout completes; pods are Running post-deploy

---

## 18. Per-Environment Registry — `cloud:configure registry`

**Goal:** Verify that each cloud environment can have its own container registry and that `cloud:deploy` picks it up.

> **Prerequisites:** A cloud environment in `.larakube.json`. GitHub or Docker Hub account.

### 18a. Configure GHCR

```bash
cd /path/to/your-app

larakube cloud:configure registry production
# Expected prompts:
#   Registry provider? → GHCR (GitHub Container Registry)
#   GitHub username / org: myorg
#   Personal access token (packages:write): ****
#   Image name [myapp]: (accept default or change)
# ✓ Registry saved to .larakube.json

# Verify .larakube.json
cat .larakube.json | jq '.environments.production.registry'
# Should show: { "provider": "ghcr", "image": "ghcr.io/myorg/myapp" }
```

### 18b. Configure Docker Hub

```bash
larakube cloud:configure registry staging
# Pick: Docker Hub
# Prompts for Docker Hub username + access token
# Image: dockerhub-user/myapp

cat .larakube.json | jq '.environments.staging.registry'
# Should show: { "provider": "dockerhub", "image": "dockerhub-user/myapp" }
```

### 18c. Rotate credentials

```bash
larakube cloud:configure registry production --rotate
# Should prompt for a new token; overwrite existing; NOT change image name
```

### 18d. Verify `cloud:deploy` uses the registry

```bash
# After registry configured, deploy should push (not sideload)
larakube cloud:deploy production
# Expect: "Pushing to ghcr.io/myorg/myapp..." (not SSH sideload message)
```

**Pass criteria:**
- Registry config saved to `.larakube.json` per environment
- `cloud:deploy` switches to push strategy automatically after registry is set
- `--rotate` updates token only; image name unchanged
- GHCR and Docker Hub both work end-to-end

---

## 19. `monitor:init` — Cluster-Wide Prometheus + Grafana

**Goal:** Verify that `monitor:init` deploys Prometheus and Grafana into `larakube-shared` and that Grafana is reachable via the local TLD.

> **Prerequisites:** Local cluster is running (`larakube up` done at least once so `larakube-shared` and Traefik exist).

### 19a. First install

```bash
larakube monitor:init

# Expected output:
#   ✓ Ensuring namespace larakube-shared...
#   ✓ Applying monitoring manifests...
#   ✓ Waiting for Prometheus...
#   ✓ Waiting for Grafana...
#   ✅ Monitoring is live.
#   Grafana:     https://grafana.kube  admin / <generated-password>
#   Prometheus:  prometheus.larakube-shared.svc.cluster.local:9090  (in-cluster)

# Verify pods are running
kubectl get pods -n larakube-shared | grep -E "prometheus|grafana"
# Both should show Running

# Open Grafana in browser
open https://grafana.kube
# Login with: admin / <printed password>

# Verify Prometheus is scraping pods
open https://grafana.kube/connections/datasources/new
# Add Prometheus — URL: http://prometheus.larakube-shared.svc.cluster.local:9090
# Click "Save & test" — should show green "Data source is working"
```

### 19b. Idempotent re-run (stable password)

```bash
# Run again — should reuse the existing Grafana password
larakube monitor:init

# Password printed should be the SAME as the first run
# No new secrets created (existing grafana-admin Secret is preserved)

# Verify:
kubectl get secret grafana-admin -n larakube-shared -o jsonpath='{.data.password}' | base64 -d
# Should match what was printed during 19a
```

### 19c. Prometheus pod discovery

```bash
# Verify Prometheus is discovering pods across namespaces
kubectl port-forward svc/prometheus 9090:9090 -n larakube-shared &
open http://localhost:9090/targets
# Should show kubernetes-pods job with your app's pods listed
# (pods annotated prometheus.io/scrape: "true" will be active)
kill %1
```

### 19d. Specific kube-context

```bash
# Target a cloud cluster's context
larakube monitor:init --context=do-sfo3-my-cluster

# Prometheus + Grafana deploy to larakube-shared on that cluster
kubectl get pods -n larakube-shared --context=do-sfo3-my-cluster
```

### 19e. Remove monitoring

```bash
larakube monitor:init --remove

# Expected:
#   ✓ Removing Prometheus...
#   ✓ Removing Grafana...
#   ✓ Removing cluster RBAC...
#   Monitoring removed from larakube-shared.

# Verify everything is gone
kubectl get deployment,svc,secret,pvc,configmap -n larakube-shared | grep -E "prometheus|grafana"
# Should return nothing (or only "No resources found")

kubectl get clusterrole,clusterrolebinding | grep larakube-prometheus
# Should return nothing
```

**Pass criteria:**
- Grafana accessible at `https://grafana.<tld>` with printed credentials; Prometheus + Loki pre-wired as data sources
- Password is stable across re-runs (not rotated)
- Prometheus discovers pods cluster-wide; Loki receives logs from Promtail
- kube-state-metrics shows deployment/pod health in Prometheus
- `--remove` cleans up all resources including all cluster-scoped RBAC

---

## 20. Monitoring Exporters — Auto-wired on `larakube up` / `cloud:deploy`

**Goal:** Verify that after `monitor:init`, running `larakube up` automatically deploys service-level exporters (MySQL/Redis/etc.) into the project namespace and that Prometheus discovers them.

> **Prerequisites:** `monitor:init` completed (§19). A project with at least one relational DB (MySQL/MariaDB/PostgreSQL) or Redis.

### 20a. MySQL / MariaDB exporter (local)

```bash
cd /path/to/your-app   # project with MySQL or MariaDB

larakube up
# Near the end, expect:
#   ✓ Wiring monitoring exporters...

# Verify exporter pod is running
kubectl get deploy mysql-exporter -n <appname>-local
kubectl get pods -n <appname>-local | grep exporter
# Should show: Running

# Check scrape annotation is on the pod
kubectl get pod -n <appname>-local -l app=mysql-exporter -o jsonpath='{.items[0].metadata.annotations}'
# Should include: prometheus.io/scrape: "true", prometheus.io/port: "9104"
```

### 20b. PostgreSQL exporter

```bash
# Same as 20a but with a PostgreSQL project
# Exporter pod: postgres-exporter, port 9187
kubectl get deploy postgres-exporter -n <appname>-local
```

### 20c. Redis exporter

```bash
# Project with Redis as cache driver
kubectl get deploy redis-exporter -n <appname>-local
# Port 9121
```

### 20d. Verify Prometheus scrapes the exporters

```bash
# Port-forward Prometheus
kubectl port-forward svc/prometheus 9090:9090 -n larakube-shared &

open http://localhost:9090/targets
# Should show:
#   kubernetes-pods — targets including mysql-exporter, redis-exporter etc.
#   Status: UP

# Query a metric in the expression browser:
# mysql_up{kubernetes_namespace="<appname>-local"}
# Should return: 1

kill %1
```

### 20e. No monitoring — exporters skipped

```bash
# Temporarily remove Prometheus to test the guard
kubectl scale deploy prometheus -n larakube-shared --replicas=0

larakube up
# "Wiring monitoring exporters..." step should NOT appear

kubectl scale deploy prometheus -n larakube-shared --replicas=1
```

### 20f. Cloud deploy wires exporters too

```bash
larakube cloud:deploy production
# After rollout, expect exporter pods in production namespace:
kubectl get deploy mysql-exporter -n <appname>-production
# Should be Running (if monitor:init was also run on the cloud cluster)
```

**Pass criteria:**
- `larakube up` deploys db-exporter + redis-exporter pods if monitoring is active
- Pods have correct `prometheus.io/scrape` annotations
- Prometheus `/targets` shows them as UP
- `larakube up` without monitoring is unchanged (no exporter step shown)
- `cloud:deploy` also wires exporters on the cloud cluster

---

---

## 21. Cloudflare Tunnel — `bundle:build --tunnel` + `bundle:install`

**Goal:** Verify that `bundle:build --tunnel` embeds the cloudflared binary and that `bundle:install` installs it as a systemd service from the bundle.

> **Prerequisites:** A Pi or VPS with systemd. A free Cloudflare account with a domain pointed at Cloudflare. Create a tunnel at `one.dash.cloudflare.com → Zero Trust → Networks → Tunnels` and copy the token.

### 21a. Build bundle with tunnel flag

```bash
cd /path/to/your-app

larakube bundle:build airgap --arch=arm64 --tunnel
# Expected:
#   Downloading cloudflared for Cloudflare Tunnel (arm64)...
#   ✅ Bundle assembled: .../dist/...-bundle-...

# Verify cloudflared is in the bundle
ls dist/*-bundle-*/cloudflared
# File should exist and be executable

# Verify bundle.json has tunnelEnabled
cat dist/*-bundle-*/bundle.json | jq '.tunnelEnabled'
# Should output: true
```

### 21b. Install with tunnel token

```bash
# Transfer bundle to Pi, SSH in, extract, then:
cd <bundle-dir>
sudo ./larakube bundle:install

# During install, expect the tunnel prompt:
#   Cloudflare Tunnel detected in bundle.
#   Create a tunnel at: https://one.dash.cloudflare.com → Zero Trust → Networks → Tunnels
#   Cloudflare tunnel token (leave blank to skip): ****

# After rollout:
#   ✅ Cloudflare Tunnel service enabled and started.
```

### 21c. Verify systemd service

```bash
# On the Pi:
systemctl status cloudflared
# Should show: active (running)

# Verify the service file was written
cat /etc/systemd/system/cloudflared.service
# Should show ExecStart=/usr/local/bin/cloudflared tunnel ... run --token <token>

# Verify binary is in place
which cloudflared
# Should show: /usr/local/bin/cloudflared
```

### 21d. Verify app is reachable via tunnel

```bash
# In Cloudflare dashboard, configure the tunnel to route:
#   Public hostname: yourapp.yourdomain.com → http://localhost:80
#
# Then from any browser:
open https://yourapp.yourdomain.com
# Should show the Laravel app (Cloudflare terminates TLS with a real cert)
```

### 21e. Skip tunnel (blank token)

```bash
sudo ./larakube bundle:install
# At the tunnel prompt, press Enter without entering a token

# Expected:
#   ⚠  Cloudflare Tunnel skipped (no token provided). Run manually later:
#      cp cloudflared /usr/local/bin/cloudflared && chmod +x /usr/local/bin/cloudflared
#      cloudflared tunnel --no-autoupdate run --token YOUR_TOKEN
```

### 21f. Reset removes cloudflared

```bash
sudo larakube-reset

# cloudflared.service should be stopped, disabled, and removed:
systemctl status cloudflared
# Should show: Unit cloudflared.service could not be found.

ls /usr/local/bin/cloudflared
# Should: No such file or directory
```

**Pass criteria:**
- `bundle:build --tunnel` downloads cloudflared binary for target arch; `bundle.json` has `tunnelEnabled: true`
- `bundle:install` detects the binary, prompts for token, installs as systemd service
- `systemctl status cloudflared` shows `active (running)` after install
- App is reachable at the Cloudflare Tunnel URL with a real TLS cert
- Blank token skips install with a manual-run hint
- `larakube-reset` fully removes cloudflared service and binary

---

> **Reminder — Homebrew (Section 13):** Test this ONLY after you push a new version tag (`git tag v0.x.x && git push --tags`). Wait for the CI `tap` job to finish, then run `brew tap luchavez-technologies/larakube && brew install larakube` on a fresh terminal to confirm the formula is live.

*Add new sections below as features are completed.*
