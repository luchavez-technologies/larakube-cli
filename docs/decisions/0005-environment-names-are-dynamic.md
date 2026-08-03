# 0005 — Environment names are dynamic; only `local` is reserved

**Status:** Accepted (2026-07-25) — convention / invariant

## Context

LaraKube environments are **user-defined**. A user picks the names when they
scaffold a project: one calls it `production`, another `prod`, another `live`,
plus whatever else (`staging`, `qa`, `eu`, …). There is exactly **one reserved,
absolute name**: `local`, which always means the developer's own machine /
K3D cluster and is special-cased throughout the code (`$env === 'local'` gates
TLD resolution, Commons wiring, host derivation, and more).

Everything that is *not* `local` is an arbitrary string the CLI must carry
through verbatim. It must never assume a specific non-local name exists.

This was written down because a real bug slipped in: `sign:init` hardcoded
`'production'` as the OpenBao environment for its secret pushes —

```php
$this->pushClusterSecret($kubectl, 'SIGN_DB_PASSWORD', $dbPassword, 'production');
```

On a cluster whose environment is named `prod` (or anything else), those secrets
land in the wrong OpenBao environment — silently. It only *looked* correct
because the author happened to be testing an environment they'd named
`production`.

## Decision

**Never hardcode a specific environment name.** Thread the resolved `$env`
through instead. The only name any code may compare against literally is
`'local'`.

The one sanctioned transform is the **cluster environment mapping**, because
the secrets backend's default dev slug is `dev`, not `local`:

```php
$clusterEnv = $env === 'local' ? 'dev' : $env;
```

Use that exact expression. Every other environment name passes through untouched.

## Consequences

- ✅ A project named `prod` / `live` / `eu-prod` works identically to one named
  `production`. No env-name is privileged except `local`.
- ✅ Secrets, hosts, and contexts always resolve against the environment the user
  actually deployed, not a guessed default.
- 🚩 **Review check:** a string literal `'production'` (or any other env name)
  in `app/` is almost always a bug. Grep for it before shipping. `'local'` is the
  sole legitimate literal, and only where the developer-machine path genuinely
  differs.
- See `.agents/rules/environments.md` for the agent-facing short form.
