# 0017 — `{tool}:init` defers to OpenBao's static-role password before calling `allocateDatabase()`, never after only

**Status:** Accepted (2026-08-15)

## Context

Nine commands provision a Commons Postgres role AND separately register that
same role as an OpenBao database-secrets-engine static role: `Passwords`
(Vaultwarden), `Sign` (Documenso), `Record` (Sendrec), `Design` (Penpot),
`Resume` (Reactive Resume), `Sso` (Zitadel), `Git` (Forgejo), `Mail`
(Stalwart), and `Plex\PlexJoinCommand` (application tenant databases). All
nine shared the same latent bug, present since the OpenBao static-role
integration first shipped (2026-07-31, see ADR-adjacent history in
`SyncsClusterSecrets.php`'s docblocks).

`allocateDatabase()`'s SQL is `ALTER ROLE ... PASSWORD '...'` —
**unconditional**, every call. Every one of the nine commands calls it with
either a freshly-generated password (`Str::random(24)`/`bin2hex(...)`,
unconditionally, every run — `PasswordsInitCommand`, `PlexJoinCommand`) or a
*locally cached* one read back from the tool's own Kubernetes Secret
(`Sign`/`Record`/`Design`/`Resume`/`Sso`/`Git`/`Mail`). Both are wrong once
OpenBao already owns the role, for different reasons:

- **Fresh-every-run** (`Passwords`, `PlexJoin`): obviously wrong — every
  single re-run forces Postgres to a new value nothing else knows about.
- **Locally-cached** (`Sign`, `Record`, `Design`, `Resume`, `Sso`, `Git`,
  `Mail`): wrong on a narrower but real trigger — OpenBao's static role
  rotates Postgres directly, on its own schedule (default 168h), via its
  own DB connection, independent of any `:init` run. If that rotation
  happens between two `:init` runs, the tool's locally-cached `db-password`
  Secret key is now stale *relative to Postgres*. The next `:init` run
  reads that stale value, force-`ALTER ROLE`s Postgres back to it
  (undoing OpenBao's rotation from Postgres's point of view), then the
  existing post-hoc block (`registerStaticRole()` + `readStaticRolePassword()`,
  present in all nine before this fix) reads OpenBao's bookkeeping — which
  still reports the value from *before* this run's `ALTER ROLE`, now wrong
  relative to what Postgres actually has — and pushes *that* to the synced
  Secret. Three-way inconsistency: Postgres has A, OpenBao's bookkeeping
  says B, the Secret (and thus the running pod) gets B.

That post-hoc block (`registerStaticRole()` then `readStaticRolePassword()`
immediately after `allocateDatabase()`) was designed for exactly one
scenario — a role's **first-ever** registration, which OpenBao rotates as a
side effect of creation — and does nothing to protect a *re-run* against an
*already-existing* role. Confirmed live 2026-08-15: this exact gap put
Forgejo and Vaultwarden into `CrashLoopBackOff` on
`password authentication failed` within the same incident, on the same
cluster, within minutes of each other.

## Decision

1. `SyncsClusterSecrets::resolveManagedDbPassword(string $kubectl, string $roleName, string $localPassword): string`
   is the one shared implementation. Every `{tool}:init`/`plex:join` that
   both provisions a Commons Postgres role and registers an OpenBao static
   role for it MUST call this **before** `allocateDatabase()`, using
   whatever `$localPassword` its own read-existing-or-generate-fresh logic
   already produced:

   ```php
   $dbPassword = $this->readXSecret($kubectl, $ns, 'db-password') ?? Str::random(24);
   $dbPassword = $this->resolveManagedDbPassword($kubectl, $roleName, $dbPassword);

   if (! $this->allocateDatabase(DatabaseDriver::POSTGRESQL, $dbName, $dbPassword)) {
       return 1;
   }
   ```

   It returns OpenBao's current static-role password when OpenBao already
   manages the role (bootstrapped, database secrets engine mounted, role
   exists), and `$localPassword` unchanged otherwise — which is also
   correctly the value used on a role's genuine first-ever creation, since
   there is no prior OpenBao password to defer to yet.

2. The existing post-hoc `registerStaticRole()` + `readStaticRolePassword()`
   block (after `allocateDatabase()`) is **not removed** — it's still the
   only thing that catches the first-creation-triggers-immediate-rotation
   case. The two are complementary: the new upfront call protects re-runs
   against an existing role; the existing after-the-fact block protects a
   fresh role's first registration.

3. **No interface was introduced.** This isn't per-tool-vendor metadata —
   it's a generic `(kubectl, role name, fallback password) → password`
   operation with the same shape as the trait methods it's built from
   (`databaseEngineMounted()`, `registerStaticRole()`,
   `readStaticRolePassword()`, none of which have interfaces either). A
   shared trait method is the right unit; introducing a
   `HasManagedDbPassword`-style interface would add ceremony with no
   corresponding polymorphism need — every caller uses the exact same
   implementation, not a per-tool override.

## Consequences

- Every one of the nine affected commands (`PasswordsInitCommand`,
  `SignInitCommand`, `RecordInitCommand`, `DesignInitCommand`,
  `ResumeInitCommand`, `SsoInitCommand`, `GitInitCommand`,
  `MailInitCommand`, `PlexJoinCommand`) now calls
  `resolveManagedDbPassword()` before `allocateDatabase()`.
- A `:init` re-run against an already-OpenBao-managed role is now a true
  no-op on the password (Postgres, OpenBao's bookkeeping, and the synced
  Secret all agree, unconditionally) — regardless of how much time or how
  many OpenBao-side rotations happened since the last run.
- Regression coverage: `tests/Unit/SyncsClusterSecretsTest.php` pins all
  four branches of `resolveManagedDbPassword()` directly (not bootstrapped,
  engine not mounted, role doesn't exist yet, role exists → defers to
  OpenBao). Individual `{tool}:init` test suites were not each given a new
  end-to-end regression test for this — the shared method's own unit
  coverage is the single source of truth every caller now goes through, so
  a regression there is a regression everywhere without needing nine
  near-duplicate integration tests to say so.
- Any *future* `{tool}:init` command that provisions a Commons DB role AND
  wires it into OpenBao's static-role rotation must follow this same
  sequencing — call `resolveManagedDbPassword()` before
  `allocateDatabase()`, not just the post-hoc read-and-overwrite pattern
  copied from an older command.
