# Plan: Metabase BI Integration (`insights:init`)

## 1. Overview
The goal is to implement `larakube insights:init`, a new Shared Cluster Tool that provisions **Metabase** (an open-source Business Intelligence and Data Visualization platform). This will allow developers and teams to instantly query, visualize, and share insights from their cluster's databases.

Like the other shared tools (`flow`, `sheets`, `secrets`), `insights:init` will leverage the "Commons" architecture (Plex) for its internal state, support VPN-only access, and gracefully handle local vs. cloud routing.

## 2. Requirements & Architecture
- **Command Name**: `larakube insights:init`
- **Slug**: `insights`
- **Default Subdomain**: `insights.*` (e.g., `insights.example.com`, `insights.dev.test`)
- **Internal Database**: Metabase requires an internal database to store its questions, dashboards, and users. We will allocate a `metabase` database inside the Plex Postgres instance by default (or allow `--no-plex` for an isolated PVC).
- **Security**: Must support `--vpn-only` to restrict dashboard access to the NetBird mesh.
- **Local Testing**: Must use `$isLocal` to bypass `letsencrypt` on Traefik when testing locally.

## 3. Implementation Steps

### Phase 1: Core Command
Create `App\Commands\Insights\InsightsInitCommand`.
It should use the standard traits:
- `InteractsWithClusterContext`
- `InteractsWithPlex`
- `InteractsWithInsights` (new trait for namespace/host resolution)

**Signature**:
```php
protected $signature = 'insights:init
    {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted.}
    {--context=  : Target a specific kube-context (defaults to current context)}
    {--env=      : Legacy alias for the environment argument}
    {--domain=   : Raw override for the Metabase cluster domain (e.g. example.com → insights.example.com)}
    {--no-plex   : Bypass Plex Commons and deploy dedicated database/cache pods instead}
    {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
    {--remove    : Tear down the Metabase stack from larakube-shared}';
```

### Phase 2: Manifest Generation
Create the Kubernetes Blade templates for Metabase.

1. **`cli/resources/views/k8s/insights/shared.blade.php`**
   - The master manifest file.
   - Generates the Deployment, Service, and Ingress.
   - Wires up the `MB_DB_TYPE`, `MB_DB_DBNAME`, `MB_DB_PORT`, `MB_DB_USER`, `MB_DB_PASS`, and `MB_DB_HOST` environment variables to the Plex Postgres database.
   - Sets `MB_ENCRYPTION_SECRET_KEY` for secure credential storage (generated at runtime).

2. **`cli/resources/views/k8s/insights/ingress.blade.php`**
   - Standard LaraKube Traefik ingress configuration.
   - Implements the exact same security headers added to the other tools:
     ```yaml
     @unless($isLocal ?? false)
         traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
     @endunless
     @if($vpnOnly ?? false)
         traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-insights-vpn-only@kubernetescrd
     @endif
     ```

### Phase 3: Registration & Discovery
Add `insights` to the `ClusterTool` Enum.
Modify `App\Enums\ClusterTool.php`:
```php
case INSIGHTS = 'insights';

// Inside getLabel():
self::INSIGHTS => 'Metabase (Business Intelligence & Dashboards)',
```
This single change will automatically make `insights:init` appear in the interactive dropdowns for `larakube tool:add` and `larakube tool:remove`.

## 4. Execution Flow (`handle()`)
1. Create `larakube-shared` namespace if it doesn't exist.
2. Resolve domain/host.
3. If not `--no-plex`, check for Plex Postgres and dynamically allocate a `metabase` database and user.
4. Generate a random `MB_ENCRYPTION_SECRET_KEY`.
5. Render `k8s.insights.shared` and apply via `kubectl`.
6. Wait for rollout (`kubectl rollout status deploy/metabase`).
7. Print the ready URL (`https://insights.example.com`).
