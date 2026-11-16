# Tool updates: `tool:outdated`, `tool:update`, Desktop "Update all"

## Status: IN PROGRESS (approved 2026-11-16)

## Context
A newly built CLI changes live tools in three ways: image pins (n8n `2.39.8 → 2.47.7`), manifest shape (the
`luchtech.dev/` label keys, resource requests) and manifest fixes. Today the only way to apply them is to re-run each
tool's `:init` by hand, with the same options, from the CLI. Desktop's Update button is a disabled placeholder.
The first real use is the luchtech-vps label rollout (`label-prefix-rollout-luchtech-vps.md`).

## Design
- **Revision, not version.** Dev builds report their version as `local`, so a CLI version can't tell builds apart.
  `App\Support\ToolRevision::for(ClusterTool)` hashes the tool's template directory (`resources/views/k8s/<dir>`)
  plus its vendor class (where image pins live), the same idea as the existing `$__tplHash` checksums.
- **Stamped on every install.** `registerDeployedTool()`, the seam every `:init` passes through, annotates the
  instance's Deployments/StatefulSets/DaemonSets with:
  - `luchtech.dev/revision`: the revision that rendered it;
  - `luchtech.dev/install-options`: the resolved non-secret init options as JSON (`--env`/`--env-secret` values
    are excluded; they live in the tool's own custom-env ConfigMap/Secret, which re-runs keep).
- **`tool:outdated <env> [--json]`**: per registered instance, current / outdated / unstamped (installed before
  stamping), plus running images.
- **`tool:update <env> --tool= [--domain=] | --all [--json]`**: re-runs the tool's `:init` with its saved options.
  An unstamped instance falls back to what a re-run already knows: host, `proxied` and `noPlex` from the registry,
  and `vpnOnly` from the live Ingress (so a VPN-only tool never comes back public). `--all` continues past a
  failure and reports each instance.
- **Desktop**: enable the Update button when outdated; "Update all (N)" on the server page; count in the bell.

## Steps
1. [x] Labels `REVISION` and `INSTALL_OPTIONS`; `ToolRevision`; stamping in `registerDeployedTool()`.
2. [x] `tool:outdated`.
3. [x] `tool:update` (single + `--all`).
4. [x] Desktop Update button + "Update all".
5. [ ] Use it for the luchtech-vps rollout.

## Notes
- Grafana's install-time logs/traces choice isn't recoverable on an unstamped instance; per the user, Grafana's data
  is disposable, so it re-runs with defaults.
- The capacity guard still runs inside each `:init` (A4 later separates it from `--force`).
- The stamp records settled values, not just typed flags (`rememberOption()`): prompted answers, Grafana's
  component choice, and branding read back from the previous stamp. Branding moved off the registry onto the
  stamp (ADR 0012 amendment).
- luchtech-vps: Forgejo ("LuchTech Git"), Grafana ("LuchTech Monitor", logs on, traces off) and Matrix
  ("LuchTech Chat") must be re-run by hand with explicit `--app-name` / `--with-logs --no-traces` before
  "Update all"; a default re-run would reset their names and remove Loki.
- **Paused apps stay paused.** An app is often paused because it needs more
  than the cluster can spare, so an update must never start it again.
  - `tool:update --all` skips a paused instance and reports it as `skipped`.
  - `--tool` on a paused instance refuses.
  - Every `:init` refuses to apply a manifest whose Deployments are live and
    paused (`guardPreInstall()`). `--force` does not get past this.
  - "Paused" means scaled to zero with the `paused-replicas` marker set. A
    marker left on a running Deployment is stale; `tool:resume` clears it.
