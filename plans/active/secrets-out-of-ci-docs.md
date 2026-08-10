# Plan: Secrets Out of CI — Docs Updates

**Status:** Not started
**Created:** 2026-08-08
**Companion to:** `plans/active/secrets-out-of-ci.md` (implementation, code-complete) and `plans/active/secrets-out-of-ci-testing.md` (manual verification, not started)

---

## Why this needs its own pass

`secrets-out-of-ci` shipped 5 new/changed commands (`dotenv:push`, `dotenv:pull`,
`dotenv --strict`, `secrets:grant`, `secrets:revoke`) and changed what CI
secrets get uploaded. None of that is reflected in the Docusaurus docs site
(`docs/docs/` at the repo root, sibling to `cli/`). Worse: one existing page
actively describes the OLD, now-wrong behavior — a reader following it today
would set up something that no longer matches reality.

Scope is intentionally narrow — just what secrets-out-of-ci touched. The
much larger pre-existing gap ("no `secrets:*` or `sso:*` command has ANY
docs page, at all") is real but NOT this plan's job; that's tracked
separately as `plans/testing-checklist.md` tracker item #27 (command-reference
sweep). Item 5 below deliberately creates the smallest new surface needed
for `secrets:grant`/`secrets:revoke`, not a full sweep of every `secrets:*`
command.

---

## Confirmed by reading the current docs (2026-08-08)

- `docs/docs/deployment/github-actions.md` §"Security standards" **actively
  describes the old design**: "Literal secret injection. Your environment
  variables are injected straight into the Kubernetes Secret during the
  GitHub Actions run" and lists `{ENV}_ENV_FILE_BASE64` as a secret LaraKube
  CLI manages. Both are false now — no `.env` content of any kind is
  uploaded; `dotenv:push` (run locally) is what creates the Secret.
- `docs/docs/deployment/surgical-credentials.md` tells the "why this is
  secure" story for `{ENV}_KUBECONFIG` only. It doesn't mention the `.env`
  secret at all (silence, not wrong info) — but it's the natural place to
  extend that same story to cover runtime credentials now that they don't
  touch CI either.
- `docs/docs/deployment/how-builds-work.md` mentions the BuildKit `--secret
  id=dotenv` mount for Vite — this is about the **public/build** vars and is
  still accurate; do not touch it (confirmed no changes needed here).
- `docs/docs/commands/operations.md` documents `env {name}` and the GitHub
  Actions commands (`cloud:configure --only=ci`, `gha:login`, `gha:switch`)
  — the natural home for the new `dotenv:*` commands, right next to `env`.
- No `commands/*.md` file documents ANY `dotenv`/`dotenv:audit`/`secrets:*`/
  `sso:*` command today — confirms these are genuinely undocumented, not
  something to "update," something to write from scratch.
- No GitLab-equivalent of `github-actions.md` exists in `docs/docs/deployment/`
  at all (separate pre-existing gap, tracker item #19) — if that page gets
  written before this plan runs, make sure it's written with the CURRENT
  (zero-blob) design, not the old one; if it doesn't exist yet, this plan
  doesn't need to create it, just don't let it inherit the old story.

---

## Tasks

- [ ] **1. Rewrite `docs/docs/deployment/github-actions.md`'s "Security standards" section.**
  - Remove the `{ENV}_ENV_FILE_BASE64` bullet entirely.
  - Replace "Literal secret injection... injected straight into the Kubernetes
    Secret during the GitHub Actions run" with the real story: public/build
    vars are baked as literal `echo` lines into the generated workflow file
    itself (computed from your blueprint when you run `cloud:configure --only=ci`),
    and runtime secrets (`APP_KEY`, `DB_PASSWORD`, etc.) never reach GitHub at
    all — only `larakube dotenv:push {env}`, run from your own machine, writes
    `laravel-secrets`.
  - Add a short "Before your first deploy" callout: CI will hard-fail with a
    `dotenv:push {env}` fix-it message if you push before running it once.
  - Keep the `{ENV}_KUBECONFIG` bullet as-is (still accurate).

- [ ] **2. Extend `docs/docs/deployment/surgical-credentials.md`.**
  - Add a section (after "What gets uploaded" or as its own H2) explaining
    that as of this change, `{ENV}_KUBECONFIG` (+ registry/VPN creds) is
    the *only* secret-shaped thing GitHub ever holds for this project —
    runtime `.env` credentials never take this path at all, `dotenv:push`
    writes them directly, cluster to cluster... machine to cluster, never
    through a third party. Ties the existing "surgical" framing to the new
    guarantee instead of leaving it implicit.

- [ ] **3. Add `dotenv`, `dotenv:audit`, `dotenv:push`, `dotenv:pull` to `docs/docs/commands/operations.md`.**
  - Place near the existing `env {name}` entry (same env-file lifecycle
    family). One `##` heading per command, matching the file's existing
    style (see `## env {name}` for the format: signature, one-paragraph
    description, example).
  - `dotenv {environment} --strict --reveal` — document `--strict` explicitly
    (exit code contract) since it's a flag addition to an already-undocumented
    command — document the whole command, not just the new flag.
  - Cross-reference `cloud:deploy`'s CI hard-fail from task 1 so a reader
    lands here from that error message and finds the fix.

- [ ] **4. Decide where `secrets:grant`/`secrets:revoke` live, then write them.**
  - No `commands/secrets.md` exists. Either create one (if `secrets:*` is
    about to get fuller docs coverage soon per tracker #27) or fold these
    two commands into `operations.md`'s GitHub Actions section as a
    "Team access" subsection (smaller footprint, doesn't presuppose #27's
    timing). Pick based on whether #27 is scheduled — check
    `plans/testing-checklist.md` before deciding.
  - Content: the per-app/per-environment access model (`developer`=read-write,
    `viewer`=read-only), that it's scoped to exactly `secret/data/{env}/{app}/*`,
    and — important, don't skip — a pointer to `sso:revoke` as the tool for
    "this person left / this account is compromised" (full sweep across
    every grant, not just this app), so a reader doesn't reach for
    `secrets:revoke` in an incident and miss other access the same person holds.

- [ ] **5. Register + link.**
  - If task 4 created a new file, add it to `docs/sidebars.ts`.
  - Add a "See also" link between `github-actions.md` and the new
    `dotenv:push`/`secrets:grant` command docs, both directions.

- [ ] **6. Read-through pass.** Open the rendered site (or `npm start` in
  `docs/` if that's the local preview command) and click through
  `github-actions.md` → `surgical-credentials.md` → the new command pages —
  confirm the story reads coherently end to end for someone who's never seen
  the old design, not just correct in isolation.

---

## Definition of done

- No page anywhere in `docs/docs/` mentions `{ENV}_ENV_FILE_BASE64` or
  describes uploading `.env` content to a CI provider.
- `dotenv:push`, `dotenv:pull`, `dotenv --strict`, `secrets:grant`,
  `secrets:revoke` each have a documented command entry a user can find
  without already knowing the command name (reachable via sidebar nav, not
  just a deep link).
- `surgical-credentials.md` states the full current guarantee (KUBECONFIG is
  the only CI secret; runtime credentials never touch CI), not just the
  KUBECONFIG half.
