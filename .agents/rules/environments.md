---
trigger: always_on
description: Environment names are user-defined and dynamic. Only `local` is reserved. Never hardcode an environment name.
---

## Environments

LaraKube environment names are **chosen by the user** — `production`, `prod`,
`live`, `staging`, `eu`, anything. The CLI must carry the resolved `$env` string
through verbatim and never assume a particular name exists.

`local` is the **only** reserved, absolute name (developer machine / K3D). It is
the only environment name any code may compare against as a literal.

Rules:
- **Never hardcode a specific environment name** (`'production'`, `'prod'`, …) in
  `app/`. Thread the resolved `$env` instead. A string literal env name other
  than `'local'` is almost always a bug — grep for it before finishing.
- The one sanctioned transform is the secrets backend env slug, because the backend's dev
  slug is `dev`: `$clusterEnv = $env === 'local' ? 'dev' : $env;` (see
  `WebmailInitCommand`, `SsoWireCommand`, `SignInitCommand`). Everything else
  passes through untouched.
- Full rationale: `docs/decisions/0005-environment-names-are-dynamic.md`.
