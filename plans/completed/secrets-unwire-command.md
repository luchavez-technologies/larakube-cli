# `secrets:unwire` — reverse an OpenBao static-role DB password handoff

> **What this is:** a code-grounded build plan for a genuinely missing command,
> for a future agent ("Antigravity") to execute. This is **not** the hidden-flag
> anti-pattern from [`no-hidden-flag-commands-cleanup.md`](./no-hidden-flag-commands-cleanup.md)
> — `secrets:wire` has no `--remove`/`--unwire` flag to strip out. It simply has
> no reversal command at all. Found while auditing that plan; confirmed as a real
> gap (not intentional) via research on 2026-08-05 — see
> [[project_no_hidden_flag_cleanup]] memory for the trail.
>
> **Status:** design pass. No code written yet.

---

## The gap

`app/Commands/Secrets/SecretsWireCommand.php` hands a tool's Commons database
password over to OpenBao static-role rotation (`registerStaticRole()`). There is
no command that reverses this — once wired, a tool stays OpenBao-managed until
either (a) it's fully uninstalled (`{tool}:remove`, which calls
`deleteStaticRole()` as teardown hygiene — see
`app/Commands/Tool/AbstractToolRemoveCommand.php:172`), or (b) someone hand-edits
OpenBao/kubectl directly, which is exactly what the "No manual kubectl on live
cluster" hard rule exists to prevent.

---

## The good news: the reversal is low-risk by construction

Read `resources/views/k8s/secrets/eso-db-static.blade.php` closely before
touching anything — the design already makes this safe:

```yaml
kind: ExternalSecret
metadata:
  name: {{ $secretName }}-db
spec:
  target:
    name: {{ $secretName }}        # the TOOL'S OWN original secret, e.g. sign-documenso-secrets
    creationPolicy: Merge          # merges ONE key into it, doesn't own/replace it
    template:
      data:
        {{ $passwordKey ?? 'DB_PASSWORD' }}: "@{{ .password }}"
```

Two things follow from `creationPolicy: Merge` and the absence of an explicit
`deletionPolicy` (ESO's default is `Retain`):

1. **The tool's Deployment never changes.** It has always pointed at
   `{secretName}`/`{passwordKey}` via `valueFrom: secretKeyRef` — wiring and
   unwiring both operate on the *value inside* that existing key, never on
   which secret/key the Deployment references. No manifest re-apply, no
   restart, no risk of the Sign/Data-style `valueFrom` merge conflict from the
   other cleanup plan.
2. **Deleting the `ExternalSecret` does not blank the merged key.** Retain
   semantics mean the target Secret keeps whatever password value ESO last
   wrote into it. Combined with `deleteStaticRole()` deleting the OpenBao
   role *definition* (not the live Postgres password —
   `DELETE /v1/database/static-roles/{roleName}` only stops future rotation;
   it doesn't revert the DB user's current password) — the frozen value in
   the Secret and the actual Postgres role password stay in agreement. The
   tool keeps working, uninterrupted, on whatever password OpenBao most
   recently rotated it to.

**Verify this last claim against a real cluster before shipping** — the reasoning
above is sound from reading the code, but "no restart needed" hasn't been
confirmed live. If it turns out ESO's `Merge` + no-`deletionPolicy` behaves
differently in practice (version-dependent), fall back to reading the current
password out of the Secret first and re-writing it as a plain
`--from-literal=` before deleting the ExternalSecret, to be safe — same
belt-and-suspenders instinct as `wireTool()`'s own
`readStaticRolePassword()` comment already models.

---

## What to build

**New file: `app/Commands/Secrets/SecretsUnwireCommand.php`** — a real standalone
command, per the same governance as
[`no-hidden-flag-commands-cleanup.md`](./no-hidden-flag-commands-cleanup.md):
own `$signature`, own `handle()`, not a flag on `secrets:wire`.

```
secrets:unwire
    {environment=local}
    {--tool=}
    {--all}
    {--context=}
    {--force}
```

(Same shape as `secrets:wire` minus `--rotation-period`, which has no meaning
in reverse.)

`handle()`:
1. Resolve `$kubectl`, guard on `secretsBackendAvailable()` (same checks
   `secrets:wire` does at the top — OpenBao must be deployed to talk to it at
   all, even to *unwire*).
2. Resolve targets. `resolveTargets()` in `SecretsWireCommand` is currently
   `protected` and filters to `dbSecretRef() !== null` + installed — pull it
   into a small shared trait (or just duplicate the ~20 lines; this codebase's
   established precedent for wire/unwire pairs is "share via trait, not
   inheritance" — see `InteractsWithSsoGrants`) so both commands stay
   independent classes.
3. For each target tool:
   - `staticRoleExists($kubectl, $tenant)` — if false/null, nothing to unwire;
     print "not currently OpenBao-managed" and skip (not an error).
   - Confirm (`confirm()` + `--force` to skip), same discipline as every other
     destructive-ish command in this codebase.
   - Delete the generator + ExternalSecret pair:
     `{kubectl} delete externalsecret {ref.secret}-db -n {ref.namespace} --ignore-not-found`
     and
     `{kubectl} delete vaultdynamicsecret.generators.external-secrets.io {ref.secret}-db -n {ref.namespace} --ignore-not-found`
     (two distinct kinds in the one blade file — both need deleting; check the
     exact plural/kind name via `kubectl api-resources | grep -i vaultdynamic`
     against a real cluster, don't guess the CRD's plural).
   - Call the existing `deleteStaticRole($kubectl, $tenant)` (already public
     enough via the trait both commands would share — `SyncsClusterSecrets`).
   - Report success: `"✅ {$tool->getLabel()}'s DB password is now static (frozen at its last OpenBao-rotated value) — re-run \`secrets:wire\` anytime to hand it back."`

**New test file: `tests/Feature/SecretsUnwireCommandTest.php`** — fake the
`externalsecret`/`vaultdynamicsecret` deletes and the OpenBao DELETE call
(`openBaoApi` goes through `Process::run` exec into the pod, per existing
`SecretsWireCommand` tests — mirror whatever fake shape
`tests/Feature/SecretsWireCommandTest.php` already uses for
`registerStaticRole`/`deleteStaticRole`, if that test file exists yet; if not,
model it on `tests/Feature/SsoRevokeCommandTest.php`'s realism level, not a
thin pass-through fake).

---

## Acceptance check

1. `larakube secrets:unwire --help` shows a real, own description (not
   delegating language).
2. `secrets:wire --tool=X` then `secrets:unwire --tool=X` then
   `secrets:wire --tool=X` again — round-trips cleanly, idempotent both ways.
3. `./php vendor/bin/pest` green, including the new test file.
4. Live-cluster smoke test (manual, by the user — not the agent, per this
   repo's "no manual kubectl" and "user runs builds" conventions): confirm the
   tool's pod does NOT need restarting after unwire, backing up the claim in
   the design section above. If it turns out it DOES need one, update this
   plan and add the restart step before calling this done.
