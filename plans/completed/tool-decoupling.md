# Decoupling Cluster Tools from Laravel Projects

**Status:** ✅ BUILT — verified 2026-08-08: `tool:add` takes `{environment?}` and `--context`, so it is cluster-state driven and no longer requires a local `.larakube.json`.

This plan addresses the need to make LaraKube's cluster tools (`tool:add`, `tool:remove`) environment-aware, completely decouple them from requiring a local `.larakube.json`, and make them strictly driven by Kubernetes Cluster State. 

## 1. Command Signatures (`tool:add`, `tool:remove`)
All tools will standardize on the `<command> <environment> --<flag>=<value>` pattern so they are completely scriptable in CI or LaraKube Cloud.

**New Signature Pattern:**
```php
protected $signature = 'tool:add 
    {environment? : The environment (or kube-context) to target} 
    {--tool= : The specific tool to add}
    {--context= : Explicit kube-context override}
    {--env= : Legacy env override}';
```

## 2. Environment & Context Awareness
When the `<environment>` argument is omitted, the CLI will use a smart heuristic to determine what to ask the user:

### Scenario A: Inside a LaraKube Project
* **Detection:** Check if `file_exists(getcwd() . '/.larakube.json')`.
* **Behavior:** Read the `.larakube.json` and prompt the user to select from the project's configured environments (e.g. `local`, `production`, `staging`). 
* **Resolution:** It maps the selected environment to its assigned Kubernetes context.

### Scenario B: Outside a LaraKube Project (Standalone Mode)
* **Detection:** No `.larakube.json` exists.
* **Behavior:** Scan the developer's `~/.kube/config` (using `InteractsWithClusterContext::kubectlContextNames()`).
* **Prompt:** Prompt the user to select a target Kubernetes context directly (e.g. `k3s-larakube`, `my-doks-cluster`). 

## 3. Cluster State Management (The "Tool Registry")
Currently, LaraKube relies on `.larakube.json` to remember what's deployed. For cluster-wide tools, this is an anti-pattern. Instead, the cluster itself will hold a registry of installed tools via a Kubernetes Secret.

* **Registry Secret:** `larakube-tools-registry` (Namespace: `larakube-shared`).
* **Format:** JSON payload containing the active tools and their parameters (engine, configuration).
* **Benefits:** 
  * If a second developer authenticates to the same cluster without the Laravel source code, running `larakube tool:remove` will still accurately list all installed tools on that cluster.
  * LaraKube Cloud can read this secret to instantly display a UI dashboard of active integrations.

## 4. `tool:add` and `tool:remove` Logic

**`tool:add` Behavior:**
1. Determine context via the heuristic (Section 2).
2. Fetch the `larakube-tools-registry` secret from the cluster.
3. If `--tool` is missing, prompt the user with a list of tools *excluding* those already marked active in the registry.
4. Pass `--no-interaction` down to the `{tool}:init` proxy (e.g. `sso:init --engine=zitadel`) so it runs without human prompts.
5. On success, save the tool to the `larakube-tools-registry` secret.
6. Check if the tool unlocks wiring (e.g., has `oidcEnv()`). If yes, we can offer to run `sso:wire --tool=...` or `mail:wire --tool=...` automatically if their prerequisites are in the registry!

**`tool:remove` Behavior:**
1. Determine context.
2. Fetch the registry from the cluster.
3. If `--tool` is missing, prompt the user *only* with the list of tools currently active in the registry.
4. Proxy down to `{tool}:init --remove`.
5. On success, remove the tool from the registry.

## Next Steps
1. Create `App\Traits\InteractsWithToolRegistry` to handle reading/writing the `larakube-tools-registry` Kubernetes secret.
2. Update `App\Commands\Tool\ToolAddCommand.php` to use the new signature, the smart environment heuristic, and the registry logic.
3. Update `App\Commands\Tool\ToolRemoveCommand.php` to match.
