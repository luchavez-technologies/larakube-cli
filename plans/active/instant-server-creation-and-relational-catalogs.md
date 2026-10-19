# Instant Server Creation & Relational Cloud Catalogs Plan

## Problem Statement
The "Create a server" page (`servers/create`) takes up to 30 seconds to load, presenting a blank pulsing box.
This happens because `ServerController::create` runs `ReadinessCheck::providers()`, invoking `larakube cloud:providers --json --no-interaction`. The CLI runs live catalog discovery across all connected providers sequentially (running `aws ec2 describe-instance-types` and `gcloud compute machine-types list`), blocking page load even when the user intends to select DigitalOcean or an Existing Custom Server.

Furthermore, `cloud:providers --status-only` in the CLI previously omitted sizes and regions, forcing consumers to trigger full live discovery just to obtain instance sizes.

## Goals
1. **0ms Page Load**: The "Create a server" UI and Step 1 form render instantaneously from local SQLite.
2. **Relational Integrity**: Create 3 normalized tables (`cloud_providers`, `cloud_regions`, `cloud_sizes`) linked to `servers` (`cloud_provider_id`, `cloud_region_id`, `cloud_size_id`, `size`).
3. **Future-Proofing**: Unlock smart server resizing (spec-aware upgrade paths) and VPS-to-Managed conversions by storing structured `vcpu`, `memory_mb`, and `monthly_price`.
4. **Lazy Single-Provider Discovery**: Fetch live instance types only for the selected provider (e.g., AWS or GCP) when advancing to Step 2, displaying a subtle inline loader on the dropdown while the user types the server name.
5. **Fast CLI `--status-only`**: Update `CloudProvidersCommand` so `--status-only` returns curated built-in enum sizes and regions in < 50ms.

## Architecture & Schema

### 1. Database Schema
- `cloud_providers`:
  - `id`
  - `slug` (unique: 'do', 'hetzner', 'gcp', 'aws')
  - `label`
  - `default_region`
  - `default_vps_size`
  - `default_managed_size` (nullable)
  - `managed_provider` (nullable: 'doks', 'eks', 'gke')
  - `ha_option` (nullable)
  - `ha_cost` (nullable)
  - `credentials_ready` (boolean)
  - `credentials_hint` (nullable)
  - `active_account` (nullable)
  - `accounts` (json nullable)
  - `source` ('builtin' | 'live' | 'cached')
  - `last_synced_at` (timestamp nullable)
  - `timestamps`

- `cloud_regions`:
  - `id`
  - `cloud_provider_id` (foreign key -> `cloud_providers.id` cascade)
  - `slug` (e.g. 'nyc1', 'us-east-1')
  - `label`
  - `is_recommended` (boolean)
  - `timestamps`
  - unique constraint on `[cloud_provider_id, slug]`

- `cloud_sizes`:
  - `id`
  - `cloud_provider_id` (foreign key -> `cloud_providers.id` cascade)
  - `slug` (e.g. 't3.micro', 's-2vcpu-4gb')
  - `label`
  - `target_kind` ('vps' | 'managed' | 'both')
  - `vcpu` (integer)
  - `memory_mb` (integer)
  - `monthly_price` (decimal nullable)
  - `currency` (string nullable)
  - `is_recommended` (boolean)
  - `timestamps`
  - unique constraint on `[cloud_provider_id, slug, target_kind]`

- `servers` (alter table):
  - `size` (string nullable)
  - `cloud_provider_id` (foreign key -> `cloud_providers.id` null on delete)
  - `cloud_region_id` (foreign key -> `cloud_regions.id` null on delete)
  - `cloud_size_id` (foreign key -> `cloud_sizes.id` null on delete)

### 2. Auto-Linking in `Server` Model
Model `booted()` hook on `Server` automatically resolves and associates foreign keys whenever `provider`, `region`, or `size` is set or updated.

### 3. Asynchronous Discovery Route
`GET /servers/provider-catalog/{provider}`:
Runs `cloud:providers --provider={provider} --json` for that single provider in the background, updates `cloud_regions` and `cloud_sizes` in SQLite, and returns the updated provider payload.

### 4. Frontend Experience
- Remove `<Deferred data="providers">` wrapper on `servers/create.tsx`.
- Form mounts and displays Step 1 immediately.
- When advancing to Step 2, if provider is AWS or GCP and live discovery is unperformed, start async background refresh with an inline spinner in the select dropdown without blocking typing.

## Implementation Steps
1. CLI: Update `CloudProvidersCommand.php` to populate enum sizes in `--status-only` mode. Run tests.
2. Desktop: Create migration for `cloud_providers`, `cloud_regions`, `cloud_sizes`, and `servers` foreign keys with seed data.
3. Desktop: Create models `CloudProvider`, `CloudRegion`, `CloudSize` and update `Server`.
4. Desktop: Implement `ProviderCatalogService` and `ServerController::providerCatalog` endpoint.
5. Desktop: Update `ServerController::create`, `ServerController::show`, and `DevBoxController::create`.
6. Desktop: Update `servers/create.tsx` to remove deferred blocking and add non-intrusive background refresh indicator.
7. Verification: Run PHPUnit/Pest tests and PHPStan across CLI and Desktop.
