# 0016 — OpenBao auto-unseals in every environment, not just `local`

**Status:** Accepted (2026-08-15, supersedes the local-only decision made when `autoUnseal` was first built)

## Context

`SecretsInitCommand.php` deploys OpenBao with an optional `postStart` lifecycle
hook (`resources/views/k8s/secrets/openbao.blade.php:106-125`) that reads the
`openbao-bootstrap` Secret's `unseal-key` and runs `bao operator unseal`
automatically the moment the container starts. Vault/OpenBao always re-seals
on any restart (the master key lives only in memory, wiped on process exit)
— this hook exists specifically to remove the "nobody notices it's sealed"
failure mode.

It was gated to `local` only: `'autoUnseal' => $env === 'local'`, with the
reasoning documented inline as *"Cloud/production stay manual-unseal by
design — a security boundary, not friction to remove."* The problem this
solved for local dev was named explicitly: a laptop sleep/wake cycle
restarts the pod with nobody watching.

**That same problem exists in production, just with a different trigger.**
Confirmed live 2026-08-15: a node instability incident (`NodeNotReady`
cascade, unrelated to OpenBao itself) restarted `openbao-backend` on
`larakube-159.89.205.239`. It came back sealed and stayed sealed for
hours, silently breaking everything downstream of it:
- ESO's Kubernetes-auth login to OpenBao (needed for every
  `VaultDynamicSecret`/static-role read)
- `secrets:wire`/`plex:join`'s KV pushes and reads
- `passwords:init`'s own OpenBao sync step, which failed mid-run

Two concrete casualties: Forgejo and Vaultwarden both went into
`CrashLoopBackOff` on `password authentication failed` — not because
anything was wrong with their databases, but because their DB passwords had
been rotated (by OpenBao's own static-role mechanism, or by
`passwords:init` regenerating one directly) while OpenBao was sealed, so the
new password never made it into the `ExternalSecret`-synced K8s Secret the
pod actually reads. Nobody noticed until the running app fell over hours
later, then required two rounds of manual `secrets:unseal` +
force-reconcile + rollout-restart on unrelated tools to actually trace back
to the root cause.

## Why the original "security boundary" reasoning doesn't hold up

The unseal key is stored, in plaintext, in the `openbao-bootstrap` Kubernetes
Secret — **regardless of whether `autoUnseal` is enabled.** It has to be:
that's the only place `secrets:unseal` (the manual command) or this
`postStart` hook can read it from. Anyone with RBAC to read Secrets in
`larakube-secrets` can already run `bao operator unseal $(cat unseal-key)`
by hand in about the same ten seconds `secrets:unseal` takes. Withholding
auto-unseal in production doesn't protect the key from a real compromise —
the key is exactly as reachable either way. What it actually gates is
whether a *routine, benign* restart (a pod eviction, a node hiccup, a
rolling update) requires a human to notice and intervene before anything
downstream recovers. That's not a meaningful security boundary; it's a
single point of manual-intervention failure with no compensating benefit.

## Decision

1. `SecretsInitCommand.php` always passes `'autoUnseal' => true`, for every
   environment — no more `$env === 'local'` gate.
2. The `postStart` hook mechanism itself is unchanged — same key, same
   Secret, same 30-attempt/1s-interval retry loop. Nothing new was built;
   the fix was removing an environment restriction on an already-working,
   already-tested mechanism (`OpenBaoManifestYamlTest.php` already covers
   the template's `autoUnseal: true` rendering).
3. This does NOT change anything about who can read the unseal key or where
   it's stored — that trust boundary (RBAC on the `larakube-secrets`
   namespace) was and remains the actual security control.

## Consequences

- A restarted `openbao-backend` pod — for any reason, in any environment —
  unseals itself within seconds, with no human in the loop.
- Existing installs need `secrets:init` re-run once to pick up the updated
  Deployment spec (adds the bootstrap volume mount + lifecycle hook) — this
  triggers one rolling restart of `openbao-backend`, which is expected and
  harmless (it'll come back up and immediately auto-unseal itself, proving
  the fix live).
- If OpenBao's storage backend or unseal-key custody model ever changes
  (e.g. adopting real KMS/transit auto-unseal, which removes the Shamir key
  from Kubernetes Secrets entirely), that would be a strictly stronger
  security posture than either state described in this ADR and should
  supersede it.
