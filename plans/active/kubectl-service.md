# Plan: `KubectlService`, one way to talk to a cluster

**Status:** Stage 1 ✅ (`App\Services\Kubectl`, `App\Data\KubectlResult`,
`Tests\Support\FakeKubectl`, `tests/Unit/KubectlTest.php`). Stages 2–4 not started.

Stage 1 notes: the prefix is byte-identical to `contextKubectl()` (pinned
kubeconfig, shell-quoted `--context`); `FakeKubectl` is an in-memory cluster
behind `Process::fake()` that parses Kubectl's quoted argument lists, so no
container binding is needed and old string-based fakes keep working alongside.
**Pairs with:** `plans/active/tool-instance-naming.md`. `ToolInstance` decides
*what* a resource is called; `KubectlService` decides *where* a command goes
and *how* it's sent. Land this before `ToolInstance` Stage 2, whose generic
teardown becomes `$kubectl->delete(...$instance->owned())`.

## Why

Every command builds its own `kubectl` prefix and command string:

- **46 prefix builders**, ~30 of them per-tool copies (`pasteKubectl()`,
  `notesKubectl()`, `plexKubectl()`, `vaultKubectl()`, …) next to the shared
  `kubectl()`, `contextKubectl()`, `kubectlPinned()`, `remoteKubectl()`,
  `environmentKubectl()`. Each re-decides the context and whether to pin
  `KUBECONFIG`.
- **376** `Process::run` calls assemble `kubectl` strings by hand, so quoting,
  `--ignore-not-found`, `-n` and timeouts are re-invented each time.
- **138** bare `"kubectl "` strings. Most are local-only code (local Traefik,
  `share`, bundles, companions, preview) where "the current context" is the
  intent. Any on a cloud path would act on whatever cluster the shell points at.
- Tests can only match command strings (`'*delete *'`), and the drift harness
  has to parse them back into resources.

## Design

### `App\Services\Kubectl`
An immutable handle on one cluster:

```php
Kubectl::forContext(?string $context)   // null = current context (local only)
Kubectl::forEnvironment(ConfigData|null $config, string $env) // resolves the saved context
```

Every instance pins `KUBECONFIG=~/.kube/config` and `--context`. A cloud
environment without a resolvable context is an error, never a silent fallback
to the current context.

Typed operations; each returns a small result object (`ok`, `output`, `error`):

| Method | Notes |
|---|---|
| `apply(string $yaml)` | manifest on stdin, never a temp file left behind |
| `applyAndWait(string $yaml, ResourceRef $deployment, int $timeout)` | replaces `applyAndVerifyRollout()` |
| `delete(ResourceRef ...$refs)` | always `--ignore-not-found`, grouped by namespace |
| `get(ResourceRef)`, `exists(ResourceRef)`, `list(kind, ns, labels)` | JSON decoded |
| `secretValue(ns, name, key)` | replaces `readClusterSecretKey()` |
| `putSecret(ns, name, array $data)` | values on stdin, never argv |
| `putConfigMap(ns, name, array $data)` | |
| `exec(ns, target, array $argv, ?string $stdin)` | |
| `rolloutStatus` / `rolloutRestart` / `waitForDelete` | |
| `raw(array $args)` | escape hatch, visibly greppable, shrinking over time |

### `Tests\Support\FakeKubectl`
Bound in the container in place of the real one. It keeps an in-memory set of
objects and records every typed call, so tests assert
`$kube->deleted()->contains($ref)` instead of matching strings. The drift harness
moves onto it and stops parsing command lines.

## Stages (one commit each)

1. **Service + fake.** `Kubectl`, result object, `FakeKubectl`, unit tests
   (context pinning, secrets never in argv, delete grouping, cloud env without
   a context refuses). No callers change.
2. **Prefix builders.** The ~30 per-tool `*Kubectl()` methods and the shared
   builders return / use a `Kubectl` handle; their string callers keep working
   through `raw()` for now. Delete each builder once it has no callers.
3. **Context audit of the 138 bare strings.** Classify each as local-intended
   (keep, but through `Kubectl::forContext(null)` so it's explicit) or cloud
   (must pin). Fix every cloud one found; each fix gets a test.
4. **Typed calls, tool by tool,** as each tool goes through `ToolInstance`
   Stage 2. `raw()` usage must only shrink (a test counts it).

## Rules
- Never put a secret value in argv: `putSecret()` and `exec(..., stdin:)` only.
- No behaviour change per stage: the commands run, and the clusters they hit,
  must be identical, proven by the existing tests passing unchanged in Stage 1–2.
- Local vs cloud stays explicit at construction; no helper guesses.

## Sibling: `ContainerRuntime`
Same shape for Docker/Podman, after `Kubectl` Stage 1 so both share one design.
Smaller: `ResolvesContainerRuntime` already centralizes detection
(`containerRuntime()`, `runtimeIsPodman()`); only 9 `"docker "` and 3
`"podman "` literals remain (`InteractsWithRemoteDeploy`, `DetectsWsl`,
`ResolvesContainerRuntime`, `UpCommand`, `SetupCommand`). Promote the trait to a
class with `build()`, `push()`, `run()` and `login()` (password on stdin), plus a
fake for tests. The install/configure helpers (`installRootlessPodman()`,
`ensureDockerInstalled()`, …) stay where they are.

## Open questions
- Keep `Process` underneath (so existing `Process::fake()` tests keep working
  during the migration), or talk to the API server directly? Recommendation:
  keep `Process`; it's what users already have configured.
- Should `Kubectl` also own `helm` and `kustomize` invocations? Probably a
  sibling later; out of scope here.
