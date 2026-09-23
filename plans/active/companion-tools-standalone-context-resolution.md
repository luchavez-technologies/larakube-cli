# Architectural Plan: Companion Tools Standalone Context Resolution

## 1. Problem Statement & Audit Findings

When attempting to deploy PocketBase to a remote VPS cluster using:
```bash
larakube data:init --context=larakube-34.27.253.31 --domain=pocket-test.luchtech.dev
```
from a directory outside of a Laravel project (standalone mode), the command failed with:
```
  Which environment?
  You passed --domain=pocket-test.luchtech.dev but no environment.

  A domain does not say which cluster to deploy to. Naming it explicitly
  avoids wiring a real hostname into a local-TLS ingress on the wrong cluster.

  e.g. larakube data:init production --domain=pocket-test.luchtech.dev
```

### Full Codebase Audit
A thorough inspection of `cli/app` revealed:
- **46 commands** across the CLI use `ResolvesToolEnvironment`:
  - **31 companion tool `:init` commands**: `data:init`, `crm:init`, `notes:init`, `sso:init`, `uptime:init`, `mail:init`, `flow:init`, `sheets:init`, `chat:init`, `design:init`, `desk:init`, `drive:init`, `office:init`, `errors:init`, `git:init`, `link:init`, `meet:init`, `monitor:init`, `passwords:init`, `paste:init`, `record:init`, `resume:init`, `sign:init`, `support:init`, `tasks:init`, `vpn:init`, `webmail:init`, `insights:init`, `analytics:init`, `secrets:init`, `dns:init`.
  - **15 operational & wire commands**: `data:wire`, `meet:wire`, `meet:unwire`, `secrets:export`, `secrets:import`, `secrets:migrate`, `secrets:rotate`, `secrets:wire`, `backup:init`, `backup:run`, `dns:list`, `dns:remove`, `drive:ext:add`, `drive:ext:remove`, `drive:ext:show`.
- **Root Cause A (`AmbiguousEnvironmentException` false-positive)**:
  `ResolvesToolEnvironment::resolveToolEnvironment()` previously inspected only `$this->argument('environment')` and `$this->option('domain')`. If `--domain` was given without a positional `{environment}`, it unconditionally threw `AmbiguousEnvironmentException`, stating *"A domain does not say which cluster to deploy to"*. It failed to realize that when `--context` is passed, the target cluster **is explicitly and unambiguously specified**.
- **Root Cause B (Defaulting to `local` outside of a project)**:
  If an operator omitted `--domain` and ran `larakube data:init --context=larakube-34.27.253.31` in standalone mode, `ResolvesToolEnvironment` found no project config (`$known = []`) and either defaulted to `'local'` (under `--no-interaction`) or prompted a `select` with only `'local'` as an option. In turn, `$env === 'local'` caused `isLocal` to evaluate to `true`, deploying self-signed local TLS certificates on a remote cloud VPS, skipping Let's Encrypt ACME registration, and saving credentials to `/v1/secret/data/local/...` instead of `production`.
- **Root Cause C (Missing `--context` in `SnapshotInitCommand`)**:
  `SnapshotInitCommand` lacked `{--context=}` in its signature and relied directly on `Kubectl::current()`.

---

## 2. Agreed Architectural Design (via `/grill-me`)

1. **Context-Driven Environment Deduction**:
   - If `--context=<context>` is provided:
     - Check if it matches a project-mapped environment in `.larakube.local.json` (when in a project). If matched, use that environment name.
     - Otherwise, inspect the context with `Kubectl::isLocalContextName($context)`:
       - If **Local** (e.g. `orbstack`, `docker-desktop`, `minikube`, `kind`, `colima`, `k3s-larakube`) → resolve to `'local'`.
       - If **Remote / Cloud** (e.g. `larakube-<ip>`, `doks-*`, `gke_*`, `aks-*`, `eks-*`) → resolve to `'production'`.
   - An explicit positional argument (e.g. `larakube data:init staging --context=...`) **always wins** over automatic deduction.

2. **Standalone Interactive Kube-Context Prompting**:
   - When running interactively outside a project without `--context` or `--domain`:
     - Query available Kubernetes contexts from `availableKubeContexts()`.
     - Render an interactive `Laravel\Prompts\select` prompt:
       `"Which Kubernetes context would you like to target for <Tool>?"`
     - Once selected, classify into `'local'` vs `'production'` automatically.

3. **Context Preservation Between Environment and Tool Deployment**:
   - Store the resolved or selected context on `$this->resolvedToolContext`.
   - Update `DeploysClusterTool::resolveToolContext` so that it seamlessly reuses `$this->resolvedToolContext`, preventing redundant prompts or `RuntimeException: No kube-context recorded for 'production'`.

4. **Clarified Ambiguity Handling**:
   - If `--domain` is passed without `{environment}` AND without `--context`, throw `AmbiguousEnvironmentException` with updated guidance suggesting `--context=<kube-context>` alongside environment names.

5. **Signature Alignment**:
   - Add `{--context= : Target a specific kube-context}` to `SnapshotInitCommand` for 100% uniformity across all companion tools.

---

## 3. Implementation Steps

### Step 1: Update `ResolvesToolEnvironment.php`
- Add property `protected ?string $resolvedToolContext = null;`.
- Add context extraction:
  ```php
  $contextOption = $this->hasOption('context') ? (string) ($this->option('context') ?? '') : '';
  if ($contextOption !== '') {
      $this->resolvedToolContext = $contextOption;
      $config ??= $this->loadProjectConfigIfAny();
      if ($config !== null) {
          foreach ($config->getCloudEnvironments() as $env) {
              if (method_exists($this, 'recordedContextFor') && $this->recordedContextFor($config, $env) === $contextOption) {
                  return $env;
              }
          }
      }
      return Kubectl::isLocalContextName($contextOption) ? 'local' : 'production';
  }
  ```
- If outside of a project and interactive without `--context` or `--domain`:
  - Retrieve `availableKubeContexts()`.
  - Prompt user to pick the cluster context.
  - Store chosen context in `$this->resolvedToolContext` and classify as `'local'` or `'production'`.

### Step 2: Update `DeploysClusterTool.php`
- In `resolveToolContext(string $env, ?string $explicitContext = null)`:
  - Check `$explicitContext ??= $this->resolvedToolContext ?? null;`.
  - If `$explicitContext !== null`, return it immediately.

### Step 3: Update `AmbiguousEnvironmentException.php`
- Update console rendering to include `--context=<kube-context>` in the actionable examples.

### Step 4: Update `SnapshotInitCommand.php`
- Add `{--context= : Target a specific kube-context}` to `$signature`.
- Pass context to `Kubectl::forContext(...)`.

### Step 5: Test Suite Expansion
- Expand `tests/Feature/ToolEnvironmentResolutionTest.php`:
  - Test remote context without environment resolves to `'production'` even with `--domain`.
  - Test local context without environment resolves to `'local'` even with `--domain`.
  - Test explicit positional argument overrides `--context` deduction.
  - Test project-mapped context resolution when `.larakube.local.json` is present.
  - Test refusal when `--domain` is passed with neither environment nor `--context`.
  - Test standalone interactive prompt for available contexts.

---

## 4. Verification & Quality Gates

1. Run Pint: `./php vendor/bin/pint`
2. Run PHPStan: `./php vendor/bin/phpstan`
3. Run Pest: `./php vendor/bin/pest tests/Feature/ToolEnvironmentResolutionTest.php`
4. Prompt user to execute `./build` and test:
   `larakube data:init --context=larakube-34.27.253.31 --domain=pocket-test.luchtech.dev`
