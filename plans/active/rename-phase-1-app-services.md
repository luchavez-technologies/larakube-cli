# Rename Phase 1 app services to instance-suffixed naming (link-kutt, passwords-vaultwarden, monitor-grafana, monitor-prometheus)

## Context

Following the successful renaming of the mechanical batch (\`forgejo-runner\`, \`meet-lk-jwt\`, \`loki\`, \`promtail\`) in \`a090730\`, this plan implements Phase 1 of the remaining companion tool workloads:
- **\`link-kutt\`** (\`ClusterTool::LINK\`) ➔ \`link-kutt-{\$instance}\`
- **\`vaultwarden\`** (\`ClusterTool::PASSWORDS\`) ➔ \`passwords-vaultwarden-{\$instance}\`
- **\`grafana\`** (\`ClusterTool::MONITOR\`) ➔ \`monitor-grafana-{\$instance}\`
- **\`prometheus\`** (\`ClusterTool::MONITOR\`) ➔ \`monitor-prometheus-{\$instance}\`

These services are application workloads whose persistent state resides in Commons Postgres/Redis or existing PVCs.

## Naming Matrix

| Resource | Old Name | New Name | Namespace | Data / Storage |
|---|---|---|---|---|
| Link Deployment / Service | \`link-kutt\` / \`link\` | \`link-kutt-{\$instance}\` | \`larakube-shared\` | Postgres \`link_kutt\` (unchanged) |
| Link Ingress | \`link\` | \`link-{\$instance}\` | \`larakube-shared\` | — |
| Passwords Deployment / Service | \`vaultwarden\` | \`passwords-vaultwarden-{\$instance}\` | \`larakube-vault\` | Postgres \`vaultwarden\` + PVC \`/data\` |
| Passwords Ingress | \`vaultwarden\` | \`passwords-vaultwarden-{\$instance}\` | \`larakube-vault\` | — |
| Monitor Grafana Deployment / Service | \`grafana\` | \`monitor-grafana-{\$instance}\` | \`larakube-shared\` | Postgres \`grafana\` (unchanged) |
| Monitor Grafana Ingress | \`grafana\` | \`monitor-grafana-{\$instance}\` | \`larakube-shared\` | — |
| Monitor Prometheus Deployment / Service | \`prometheus\` | \`monitor-prometheus-{\$instance}\` | \`larakube-shared\` | PVC \`prometheus-storage\` (unchanged) |
| Monitor Prometheus ConfigMap | \`prometheus-config\` | \`monitor-prometheus-config-{\$instance}\` | \`larakube-shared\` | — |

## Code Changes

### 1. \`ClusterTool::LINK\`
- Update \`resources/views/k8s/link/shared.blade.php\` and \`ingress.blade.php\` to accept \`\$instance\` and apply \`\$suffix\`.
- Update \`LinkInitCommand.php\` to compute \`\$instance\` and pass into views.
- Update \`LinkTool.php\` and tests.

### 2. \`ClusterTool::PASSWORDS\`
- Update \`app/Vendors/PasswordTool.php\` components to \`\$name('passwords-vaultwarden')\`.
- Update \`resources/views/k8s/vault/shared.blade.php\` and \`ingress.blade.php\` to accept \`\$instance\` and apply \`\$suffix\`.
- Update \`PasswordInitCommand.php\` / \`PasswordRemoveCommand.php\`.

### 3. \`ClusterTool::MONITOR\`
- Update \`app/Vendors/MonitorTool.php\` \`baseDeploymentName()\` to \`'monitor-grafana'\`.
- Update \`resources/views/k8s/monitoring/shared.blade.php\` to apply \`\$grafanaName\`, \`\$prometheusName\`, \`\$prometheusConfigMapName\`.
- Update Grafana datasources Prometheus URL to \`http://{\$prometheusName}.larakube-shared.svc.cluster.local:9090\`.
- Update \`MonitorInitCommand.php\` and \`MonitorRemoveCommand.php\` rollout and teardown references.

## Verification Results (Completed)
- ✅ Pint: 0 issues formatted.
- ✅ PHPStan: 0 errors analysed.
- ✅ Pest Parallel Suite: 1,884 tests passed, 0 failures, 0 errors across entire test suite.
- ✅ Live Data Protection: Preserves existing PVC claims (`vaultwarden-storage`, `prometheus-storage`), DB secrets (`vault-secrets`, `monitor-secrets`), and passwords intact without loss.
