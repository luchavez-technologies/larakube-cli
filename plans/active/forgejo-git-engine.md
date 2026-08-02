# Plan: Forgejo as the default `git` engine (Gitea kept as an alternate)

**Status:** Not started. **Time-sensitive — see "The migration window".**

## Why

LaraKube's premise is escaping platform risk, so its git forge shouldn't itself
be open-core. Gitea moved to a for-profit (CommitGo) and now fences off a
**governance/compliance tier**, verified 2026-07-26:

| Enterprise-only in Gitea | Forgejo |
|---|---|
| SAML 2.0, SCIM, org-mandated 2FA | free |
| **Audit log** | free |
| IP allowlist, Branch Protection Inheritance | free |
| Dependency scanning | free |

OAuth2/OIDC, LDAP, per-user 2FA, Actions, packages and LFS *are* free in Gitea
(`cmd/admin_auth_oauth.go` ships in the MIT repo — that's what `sso:wire` drives),
so nothing is broken today. The problem is **structural**: features have moved
behind the wall before and can again. Forgejo is **GPLv3+ with no enterprise
edition**, governed by a non-profit — there is no wall to move things behind.

## The migration window (act before accumulating data)

**The transparent Gitea→Forgejo upgrade path is already closed for us:**

- Forgejo **v10.0** (Jan 2025) was the *last* release supporting a direct upgrade,
  and only from **Gitea ≤ 1.22**.
- We just pinned Gitea **1.27.0**; latest Forgejo is **v16.0.1**.
- So Gitea 1.27 → Forgejo is **not** a supported direct migration.

**But right now this costs nothing: Gitea is not deployed on the cluster**
(`deployments.apps "gitea" not found` as of 2026-07-26). There is no repo, issue
or CI history to migrate. Switching is a fresh install.

⚠️ **The moment real repos land in Gitea, this becomes genuine migration debt on
an unsupported path.** A demo instance can simply be wiped (`git:remove` + fresh
`git:init --engine=forgejo`); a forge people actually push to cannot.

## Verified facts (2026-07-26 — don't re-research)

- Latest stable: **Forgejo v16.0.1** (2026-07-21).
- License: **GPLv3+** from v9.0 (MIT up to v8.0 inclusive).
- Server image: `codeberg.org/forgejo/forgejo:<version>`.
- Runner image: `code.forgejo.org/forgejo/runner:9.0.3` (Forgejo Actions is the
  same act_runner lineage; GitHub-compatible workflow syntax).
- Config env: Forgejo reads **`FORGEJO__<section>__<KEY>`** and still honours
  `GITEA__` for compatibility. Upstream **recommends passing both prefixes** so
  config survives a future release that drops the old names.

## Implementation — as a `git` engine, not a replacement

`ClusterTool::GIT` has no `engines()` yet. Add them, mirroring `flow`:

```php
self::GIT => ['forgejo' => 'Forgejo', 'gitea' => 'Gitea'],  // first = default
```

Per the "engines are a grouping, not an either/or" decision, `git:init` should
install additively and *advise* on redundancy rather than block. Note both forges
would want the same host, so per-engine hosts apply (`forgejo.<tld>` /
`gitea.<tld>`) exactly as done for `flow`.

Work items:
1. `resources/views/k8s/git/forgejo.blade.php` — copy the Gitea manifest and change:
   - image → `codeberg.org/forgejo/forgejo:16.0.1`
   - emit **both** `FORGEJO__*` and `GITEA__*` for every setting (see above)
   - runner → `code.forgejo.org/forgejo/runner:9.0.3`, keeping the
     **`docker:28.2.2-dind` sidecar + shared `/var/run` emptyDir** (k3s has no
     docker.sock — see the Gitea fix; do not reintroduce a hostPath)
   - deployment names `forgejo` / `forgejo-runner`, services `forgejo-http`/`-ssh`
2. `GitInitCommand` — engine-aware: view name, deployment/service names, and the
   in-pod CLI binary is **`forgejo`**, not `gitea` (verify:
   `forgejo admin auth add-oauth`, `forgejo admin user create`,
   `forgejo actions generate-runner-token`). Config path likely
   `/data/gitea/conf/app.ini` still — **verify, don't assume**.
3. `ClusterTool` — make `smtpEnv(GIT)` / `oidcEnv(GIT)` / `usesCliOidc()` resolve
   the right deployment name per installed engine. This is the same engine-
   awareness gap already logged for `flow` and `drive`; consider fixing the
   general mechanism once rather than three times.
4. Commons wiring carries over unchanged: Postgres tenant, three S3 buckets
   (`-storage`, `-packages`, `-lfs`), and the Valkey logical index for
   cache/session/queue.
5. Tests + docs, and an ADR recording the governance rationale.

## Decision to make

- **Default engine.** Recommend `forgejo`. Gitea stays selectable for anyone who
  wants the company-backed option or already runs it.
- **Existing Gitea installs.** Do NOT build a migration path — it's unsupported
  above Gitea 1.22 and this is pre-release with one cluster. Document
  "back up, wipe, reinstall" and leave it at that.

## Runner + registration — VERIFIED 2026-07-26 (all unknowns resolved)

Forgejo does **not** use Gitea's `GITEA_RUNNER_REGISTRATION_TOKEN` flow. It uses
**offline shared-secret registration**, which is actually simpler for us — the
server issues nothing, we generate the secret ourselves.

**Secret format:** a **40-character hex string**. The first 16 chars are the
identifier (they become the runner UUID); the remaining 24 are the secret.
Generate with `bin2hex(random_bytes(20))`.

**Server side** (exec into the Forgejo pod):
```
forgejo forgejo-cli actions register --name larakube --secret <40-hex>
```
(`--scope <org>` optional; omit for an instance-wide runner.)

**Runner side** — from upstream's own `examples/kubernetes/dind-docker.yaml`:
- runner image `code.forgejo.org/forgejo/runner:6.4.0`
- env `FORGEJO_INSTANCE_URL` (e.g. `http://forgejo-http:3000`) and `RUNNER_SECRET`
  (the same 40-hex string, from a Secret)
- dind **sidecar** `docker.io/docker:28.3.0-dind`, `privileged: true`
- `DOCKER_HOST: tcp://localhost:2376` with TLS certs shared via a `docker-certs`
  emptyDir mounted at `/certs` in both containers
- emptyDir volumes: `docker-certs`, `runner-data` (`/data`), `tmp` (`/tmp`)
- an init container writes the runner config file from `RUNNER_SECRET`

This replaces `actions generate-runner-token` in `GitInitCommand` — Forgejo has
no such subcommand.

## Server — VERIFIED
- image `codeberg.org/forgejo/forgejo:16.0.1` (mirror: `data.forgejo.org`)
- env prefix **`FORGEJO__<SECTION>__<KEY>`**; `GITEA__` still honoured, so the
  existing Commons/mailer/storage/redis wiring maps across 1:1 by swapping the
  prefix. Emit `FORGEJO__` only — we template it, so the "pass both" advice
  (aimed at hand-written compose files surviving a future drop) doesn't apply.
- data volume `/data` (standard image; the rootless image uses `/var/lib/gitea`)

## Still to confirm at implementation time (cheap, do it live)
1. `app.ini` path inside the Forgejo container — expected to remain
   `/data/gitea/conf/app.ini` for compatibility, but the CLI execs depend on it.
   Check with `kubectl exec ... -- ls /data/gitea/conf/`.
2. OIDC callback path — Gitea's is `/user/oauth2/<source name>/callback`; expected
   unchanged, but confirm before registering the redirect URI in Zitadel.
3. `forgejo admin auth add-oauth` flag parity with Gitea's (same fork lineage).

## Scope note
This is a multi-file change: a new ~250-line manifest, engine plumbing in
`GitInitCommand`, and engine-aware `smtpEnv`/`oidcEnv`/teardown. Do it in one
focused pass — a half-applied engine swap (manifest switched but `mail:wire` /
`sso:wire` still targeting the `gitea` Deployment) is worse than not starting,
because both would silently wire the wrong workload.
