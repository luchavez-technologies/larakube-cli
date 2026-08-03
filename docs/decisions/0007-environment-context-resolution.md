# 0007 — Environment → kube-context resolution is a single contract

**Status:** accepted
**Date:** 2026-07-27

## Context

`{tool}:init {environment}` must talk to *that environment's* cluster. Getting this
wrong is not a cosmetic bug: `record:init production` resolving to a local context
reports "No Commons on this cluster yet" and then **offers to create one**, one
keystroke away from provisioning Postgres/Redis/SeaweedFS onto a dev cluster.

The coordinates live in **two files**, and reading only the first is the recurring
mistake this record exists to stop:

| file | committed? | holds |
|---|---|---|
| `.larakube.json` | yes | blueprint: name, features, `environments[env].hosts`, drivers |
| `.larakube.local.json` | **gitignored** | `environments[env].cloud` — `ip`, `user`, `port`, `key`, managed `context` |

`ConfigData::loadFromFile()` merges the second into the first
(`$data['environments'][$env]['cloud'] = $envLocal['cloud']`). **`.larakube.json`
alone never contains a cloud block or a context** — `saveToFile()` deliberately
strips it, because a server IP and SSH key are operator/machine-specific and must
never be committed. Grepping `.larakube.json` for `context` or `cloud` returns
nothing *by design*. That is not evidence the environment is unconfigured.

## Decision

**One resolution path. No command re-implements it.**

```php
$env     = $this->resolveToolEnvironment(ClusterTool::X);   // positional arg wins
$context = $this->resolveToolContext($env, $this->option('context'));
$this->plexContext = $context;                              // BEFORE any Commons call
$kubectl = $this->xKubectl($context);
```

`resolveToolContext()` (`DeploysClusterTool`) → `environmentContextOrCurrent()`
(`ResolvesEnvironmentContext`), which resolves in this order:

1. `--context=` explicit override
2. `$env === 'local'` → null (current context, correct for local)
3. `getCloud($env)->context` — managed clusters (DOKS/EKS) store a kube-context
4. `getCloud($env)->ip` → `environmentContextName($ip)` → `larakube-<ip>` — VPS
5. null → falls back to whatever `kubectl config current-context` says

`mail:init` inlines steps 1–2 and then calls the same helper; that is equivalent,
not different. **`notes:init`, `record:init`, `sheets:init` and `mail:init` already
resolve context identically.** When one of them targets the wrong cluster, the cause
is *not* a divergent resolution path — do not "fix" it by copying another command's
lines.

### `$this->plexContext` is not optional

`InteractsWithPlex` reaches the Commons through its **own** `plexKubectl()`, built
from `$plexContext` — never from the `$kubectl` the command holds. Leaving it null
makes every Commons lookup query the *current* context while the rest of the command
correctly targets the environment. `getCommonsSpec()` then returns null and the
command reports "no Commons" about a cluster it was never asked to look at.

## Consequences

- **Diagnosing a wrong-cluster report starts with `.larakube.local.json`**, then
  `kubectl config current-context`, then whether `plexContext` was set before the
  first Commons call. Not with the committed blueprint.
- Step 5's silent fallback is the sharp edge. `k9s` warns in this case
  (`K9sCommand.php`: *"No deploy target saved for '{env}' — opening k9s on your
  current context"*); `resolveToolContext()` does not. Browsing the wrong cluster is
  harmless, **writing to it is not** — a non-local environment that resolves to no
  saved target should refuse and point at `cloud:configure`, with `--context=` as the
  explicit override. Tracked as follow-up; not yet implemented.
- Any new `{tool}:init` copies the four-line block above verbatim.
