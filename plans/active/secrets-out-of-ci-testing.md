# Test Plan: Secrets Out of CI — Manual/Cluster Verification

**Status:** ⛔ NOT STARTED — confirmed against the cluster 2026-08-08: the only non-system ServiceAccounts on `larakube-159.89.205.239` are tool ones (`larakube-backup`, `prometheus`, `external-dns`, headlamp, openbao/ESO) plus the `larakube-access/eman` teammate grant. No per-(app, environment) deploy SA exists, so `secrets:grant` has never run here.

---

Covers manual verification for `dotenv:push`, `dotenv:pull` (both new),
`dotenv --strict` (new flag on the existing `dotenv` command),
`secrets:grant`/`secrets:revoke` (new, per-app/per-env OpenBao access), and
the CI workflow rewrite (GHA + GitLab) that stops uploading ANY `.env`
content as a CI secret — public/build vars are now baked as literal `echo`
lines into the generated workflow file itself.

The automated suite (1333 passing at implementation time, `Process`/`Http`
faked throughout) has never actually round-tripped against a real cluster,
real OpenBao, or a real CI run — this is that walkthrough. Ordered by blast
radius: free/local first, the real GitHub Actions run (💰 in the sense of
burning real CI minutes and touching a real repo) last.

Standalone on purpose — kept out of `plans/testing-checklist.md`, which has
grown too large and multi-era to be a good home for a single feature's test
plan. Fold a short pointer in there instead of the full content.

---

## Phase A — `dotenv:push` / `dotenv:pull`, no OpenBao (local, free)
- [ ] On a project with **no** `secrets:init` run yet (no OpenBao on the target cluster), edit `.env.production` (or `.env` for local) and change/add a secret-shaped value, e.g. `AIRTABLE_API_KEY=key_live_test123`.
- [ ] `larakube dotenv:push production` — confirm it prints "OpenBao not detected on this cluster — writing directly to the cluster Secret," and exits 0.
- [ ] `kubectl get secret laravel-secrets -n <app>-production -o json` — confirm `AIRTABLE_API_KEY` is present with the new value, and `laravel-config` was **not** touched (still only public keys).
- [ ] Rotate the value again locally, re-run `dotenv:push production` — confirm only that one key changes in the cluster (spot-check another key's value is untouched — proves per-key granularity, not a full-file overwrite).
- [ ] On a machine WITHOUT that key locally (simulate a fresh clone: rename/hide `.env.production`), run `larakube dotenv:pull production` — confirm `.env.production` is created (or updated) with `AIRTABLE_API_KEY=key_live_test123` restored from the cluster.
- [ ] `larakube dotenv:pull production` again with the file already present and unrelated local edits nearby — confirm only the pulled keys change; other lines/comments in the file survive untouched.
- [ ] Lock the file (`.larakube.json` → add `.env.production` under a locked-files entry, or however your project already exercises `addLockedFile`) and re-run `dotenv:pull production` — confirm it prints "locked — skipping" and the file is genuinely untouched (checksum/diff before-after).

## Phase B — `dotenv --strict` (local, free)
- [ ] With local and cluster in sync, `larakube dotenv production --strict` — exits 0.
- [ ] Change one value locally without pushing, re-run with `--strict` — confirm it exits 1 (`echo $?`) and the table shows that key as `drift`.
- [ ] On a project joined to a Plex Commons with the DB OpenBao-rotates (`plex:join` wired a static role), run `larakube plex:rotate production` (or wait for a natural rotation) so the live `DB_PASSWORD` no longer matches whatever's in the local file, then `dotenv production --strict` — confirm it exits **0** (not 1), the table shows `DB_PASSWORD` as `rotated (excluded)` rather than `drift`, and the summary line explicitly calls out the excluded key. This is the one that actually matters — a naive `--strict` would false-positive-fail every CI run on any Plex-backed app the moment a rotation happens.
- [ ] Confirm `--reveal` still masks/reveals correctly with `--strict` combined (the two flags are independent).

## Phase C — Zero-blob CI generation (free, no CI run needed yet)
- [ ] `larakube cloud:configure production --only=ci` (or `cloud:configure:gha` if that's still the entry point in your build) on a project with GitHub Actions — after it completes, open `.github/workflows/larakube-deploy-production.yml` and confirm: **no** `ENV_FILE_BASE64` or `BUILD_ENV_BASE64` reference anywhere in the file (`grep -c BASE64` should only match `KUBECONFIG`-related lines).
- [ ] In the same file, confirm a block of literal `echo 'KEY=VALUE' >> .env` lines appears (not a `base64 -d` decode) — spot check `VITE_APP_URL`, and if the project uses Reverb, `VITE_REVERB_APP_KEY`/`VITE_REVERB_HOST`/`VITE_REVERB_PORT`/`VITE_REVERB_SCHEME` are all present with real values, not placeholders.
- [ ] `gh secret list` (or the repo's Settings → Secrets UI) for this repo — confirm **no** `{ENV}_ENV_FILE_BASE64` or `{ENV}_BUILD_ENV_BASE64` secret exists (delete any left over from a previous run of this CLI, if upgrading from before this change — the old secret is now dead weight, not read by anything).
- [ ] Same two checks (no BASE64 env-blob variable, literal `echo` lines present) for the GitLab pipeline: `larakube cloud:configure production --only=ci` on a GitLab remote (or `cloud:configure:gitlab`), then inspect `.gitlab-ci.yml` and `glab variable list` / GitLab Settings → CI/CD → Variables.
- [ ] `larakube pipeline:test production --job=build` (uses `act` locally) — confirm the build job runs successfully with no `ENV_FILE_BASE64`-shaped secret in the mock secrets file it generates, and the Vite build produces real (not empty) `VITE_*` values in the built assets.

## Phase D — `dotenv:push` / `dotenv:pull` WITH OpenBao (needs `secrets:init` + `sso:init` on the target cluster)
- [ ] With OpenBao installed (`larakube secrets:init` already run), edit a secret value locally and `larakube dotenv:push production --app=<name>` — confirm it prints "Pushing N secret key(s) to OpenBao" then "synced 'laravel-secrets'".
- [ ] Port-forward or `larakube shell secrets`-equivalent into OpenBao and inspect `secret/data/production/<name>/<KEY>` — confirm the pushed value is there under the app-scoped path (NOT a flat `secret/data/production/<KEY>` — the whole point of the app-scoping).
- [ ] `kubectl get secret laravel-secrets -n <app>-production -o json` — confirm the value materialized into the native Secret (via the ExternalSecret the push wired).
- [ ] `kubectl get externalsecret -n <app>-production laravel-secrets -o yaml` — confirm its `spec.data[]` list has one explicit `secretKey`/`remoteRef.key` entry per pushed key (this is the drive-by fix — the OLD `dataFrom.extract` shape would show here instead, and would never actually sync). Confirm `status.conditions` shows `Ready: True`.
- [ ] Rotate the OpenBao root token or restart `openbao-backend`, then `larakube dotenv:pull production --app=<name>` — confirm it reads back the same values (round-trip proof), scoped to `<name>` only.
- [ ] If a second app shares the same cluster/environment (or simulate with `--app=other-app`), push a DIFFERENT value under `other-app`, then `dotenv:pull production --app=<name>` again — confirm `<name>`'s pull is unaffected by `other-app`'s keys (proves the app-scoping actually isolates, not just labels).

## Phase E — `secrets:grant` / `secrets:revoke` (needs `sso:init` + `secrets:init`, real Zitadel user)
- [ ] `larakube secrets:grant production --app=<name> --role=developer --email=<a real Zitadel user's email>` — confirm success output shows the role key (`secrets-<name>-production-developer`) and the scope line (`secret/data/production/<name>/*`, read-write).
- [ ] Have that user log into the OpenBao Web UI (`secrets.<domain>`) via SSO, selecting role `secrets-<name>-production-developer` at login — confirm they land in with access, and can read/write under `secret/data/production/<name>/` but get denied reading `secret/data/production/<other-app>/` or `secret/data/staging/<name>/`.
- [ ] Same test with `--role=viewer` for a second user — confirm they can read but a write attempt (via the UI or `bao kv put`) is denied.
- [ ] `larakube secrets:revoke production --app=<name> --email=<that user> --force` — confirm it revokes cleanly, and the user's next OpenBao login with that role fails (no longer bound).
- [ ] Grant the SAME (app, env, role) to two different users, then `secrets:revoke` for just one of them with `--role` omitted — confirm the single-holder short-circuit path revokes without prompting, and the OTHER user's access is untouched.

## Phase F — `sso:revoke`'s incident sweep covers `secrets:grant` grants (local/free, needs SSO)
- [ ] Grant a user BOTH a global tier (`larakube sso:grant --tool=secrets --role=openbao-operator --email=<user>`) AND an app-scoped grant (`larakube secrets:grant production --app=<name> --role=developer --email=<user>`).
- [ ] `larakube sso:revoke --email=<user>` (no `--role`, discovery path) — confirm the picker lists BOTH `openbao-operator` and `secrets-<name>-production-developer` together, select both, confirm revoke removes both in one pass.
- [ ] Confirm the user can no longer log into OpenBao at all afterward (proves the incident-sweep story — one command, one user, everything gone — actually holds for app-scoped grants too, not just the fixed tiers).

## Phase G — 💰 Real GitHub Actions run end to end
- [ ] Push a commit that triggers the generated workflow WITHOUT having run `dotenv:push` first on a brand-new environment (fresh `laravel-secrets`-less namespace) — confirm the "🔒 Verify runtime secrets were pushed" step fails the job with the `dotenv:push {env}` fix-it message, and the deploy job stops there (build job may still have succeeded — that's correct, only the deploy job needs the Secret).
- [ ] Run `larakube dotenv:push {env}` locally, then re-trigger the workflow (push again, or re-run the failed job) — confirm it now proceeds past that check and completes a full deploy.
- [ ] Open the completed run's logs in the GitHub UI — confirm no secret value (API keys, DB password, `APP_KEY`) appears anywhere in plaintext in any step's output (GitHub's own masking should catch registered secrets, but there should be nothing to mask since none were ever uploaded).
- [ ] Visit the deployed app — confirm it works normally (DB connects, mail sends if applicable, Reverb/WebSocket connects if applicable) — proves the split (public vars baked in CI, secret vars via `dotenv:push`) actually reassembles into a fully working `laravel-secrets` + `laravel-config` pair in the cluster.

---

## Docs follow-up (not testing, but adjacent)

Moved to its own plan: `plans/active/secrets-out-of-ci-docs.md` — same reasoning as this file getting pulled out of `plans/testing-checklist.md`. It has the concrete file list (`docs/docs/deployment/github-actions.md` still describes the old `{ENV}_ENV_FILE_BASE64` design, `surgical-credentials.md`, `commands/operations.md`, and where `secrets:grant`/`secrets:revoke` should live).
