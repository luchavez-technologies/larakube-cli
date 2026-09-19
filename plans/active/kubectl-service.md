# Plan: `KubectlService`, one way to talk to a cluster

**Status:** Stage 1 ✅ (`App\Services\Kubectl`, `App\Data\KubectlResult`,
`Tests\Support\FakeKubectl`, `tests/Unit/KubectlTest.php`). Stage 2 ✅ (every
`~/.kube/config` prefix is `Kubectl::forContext()->prefix()`; a test forbids
copies). Stage 2b ✅ (`forKubeconfig()`; only `Kubectl` sets KUBECONFIG for a
kubectl command, test-enforced). Stage 3 ✅. Stage 5 ✅ (`App\Services\ToolRegistry`, `FakeToolRegistry`). Stage 4 in progress.


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
2. **Prefix builders.** 31 of the per-tool `*Kubectl()` methods are
   byte-identical copies that build `--context=<ctx>` **unquoted** (a context
   name with a space or shell character breaks out of the command). Every
   call site becomes `Kubectl::forContext($context)->prefix()` and the copy is
   deleted; `contextKubectl()`, `remoteKubectl()`, `rbacKubectl()`,
   `kubectlPinned()` and `kubectl()` go the same way. Builders that also
   *resolve* a context (`environmentKubectl()`, `PlexService::kubectl()`)
   keep that logic and delegate the string. The only intended change is the
   quoting; tests that pinned the unquoted string are updated to the quoted
   one. A test fails if any `*Kubectl()` builder method reappears.
2b. **Kubeconfig-path handles.** `Kubectl::forKubeconfig(string $path, ?string
   $context = null)` for the prefixes that point at a *different* kubeconfig on
   purpose (scoped deploy kubeconfigs in `InteractsWithRemoteDeploy`, context
   merges in `cluster:setup`, `context:import`, `cloud:create`,
   `ProvisionsK3sNode`, `PrunesKubeContext`, `ContextRemoveCommand`), so every
   kubectl prefix in the CLI comes from `Kubectl`.
3. **Context audit of the 138 bare strings.** Classify each as local-intended
   (keep, but through `Kubectl::forContext(null)` so it's explicit) or cloud
   (must pin). Fix every cloud one found; each fix gets a test.
   **Stage 3 result:** 128 local / current-server strings go through
   `Kubectl::current()` (prefix is exactly `kubectl`, following the shell's
   KUBECONFIG, which `bundle:install` on a k3s host needs). Four commands took
   an environment and ignored it: `stop`, `start`, `about` and `snapshot:list`
   ran on the current context; they now use the environment's saved cluster
   and refuse a cloud environment without one. A test forbids command strings
   that start with a bare `kubectl`.
   Follow-ups found, not fixed here:
   - `snapshot:*` use namespace `<app>` while apps live in `<app>-<env>`;
     `snapshot:create`/`clone` take no environment and a non-environment
     positional (breaks the one-positional rule). Needs its own redesign.
   - `up <env>` deploys to the current context by design (it warns on a
     local/remote mismatch only for `local` and `production`).
4. **Typed calls, tool by tool,** as each tool goes through `ToolInstance`
   Stage 2. `raw()` usage must only shrink (a test counts it).
5. **`ToolRegistry` service.** `InteractsWithToolRegistry` is 600 lines and 21
   methods that each take a kubectl string, composed into 22 files;
   `ToolInstance::registered()` reads the registry a second, separate way,
   and every test fakes it as a base64 blob in a Secret. Replace the data
   half with `App\Services\ToolRegistry::on(Kubectl $cluster)`:
   `instances($tool)`, `forHost($tool, $host)`, `hosts($tool)`,
   `register(...)`, `unregister(...)`, backed by `Kubectl::secretValue()` /
   `putSecret()`, plus an in-memory `FakeToolRegistry` over `FakeKubectl`.
   `ToolInstance::registered()` reads through it. Prompts, pickers and
   messages (`reportToolNotInstalled()`, the `:remove` picker) stay in
   command traits: the service never talks to the user. Callers move over
   trait method by trait method; the trait is deleted when empty.

   **Stage 5 result:** `ToolRegistry::on($cluster)` owns every registry read
   and write (rows, sole-row resolution, host → instance, register with
   legacy-only self-heal, aliases, unregister); `InteractsWithToolRegistry`
   keeps one-line delegates plus what talks to the user and the live probes.
   `ToolInstance::registered()` and the Meet lookup read through it (the Meet
   lookup only matched a legacy `''` instance, so `LIVEKIT_URL` never came
   from the registry). Transport strings are unchanged, so the 48 test files
   that fake them still pass; new tests use `FakeToolRegistry::install()`.

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
