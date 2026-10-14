# Plan: Fast Status-Only Cloud Provider Discovery and Decoupled Catalog Fetching

## Context & Motivation
Currently, whenever LaraKube Desktop needs cloud provider information (e.g. on the **Setup / Readiness** page, during server creation, or inside the **Cloud Accounts** drawer), it executes `larakube cloud:providers --json --no-interaction`.

`cloud:providers` eagerly performs live discovery for *every* connected provider, invoking:
- `aws ec2 describe-regions` & `aws ec2 describe-instance-types`
- `gcloud compute regions list` & `gcloud compute machine-types list`
- DigitalOcean & Hetzner REST endpoints

This causes 200KB+ of payload and up to ~25 seconds of latency on cold refresh, even though:
1. The **Setup** page (`readiness.tsx`) only displays credential status and account names.
2. The **Cloud Accounts** right drawer (`CloudAccountsModal`) only manages tokens and profiles.
3. The **Server Creation** page only needs regions/sizes for the specific selected provider.

## Proposed Changes

### 1. CLI: Add `--status-only` and `--provider=` flags to `cloud:providers`
- Update `cli/app/Commands/Cloud/CloudProvidersCommand.php`:
  - Add `{--status-only : Only return provider metadata, credential status, and accounts without live instance catalog discovery}`.
  - Add `{--provider= : Only describe a single cloud provider (do, hetzner, gcp, aws)}`.
  - In `describe(CloudProvider $provider, bool $statusOnly = false)`:
    - When `$statusOnly` is `true`, return `slug`, `label`, `credentials`, `accounts`, `activeAccount`, `defaultRegion`, and `regions` (using built-in regions), skipping `LiveProviderCatalog` entirely.
    - When `$statusOnly` is `false`, continue full catalog discovery.
- Update table output when not `--json`:
  - Render concise `Provider`, `Slug`, `Active Account`, `Credentials` columns when `--status-only` is passed.

### 2. CLI Tests:
- Add tests in `cli/tests/Feature/CloudProvidersCommandTest.php`:
  - Test `--status-only --json` returns provider list without invoking live catalog or process mocks.
  - Test `--provider=aws` filters output to only AWS.
  - Test `--provider=invalid` returns validation error.

### 3. Desktop: Update `ReadinessCheck` and `ReadinessController`
- In `desktop/app/Services/LaraKube/ReadinessCheck.php`:
  - Update `providers(bool $fresh = false, bool $statusOnly = false): ?array`:
    - Cache key: `readiness.providers.status` vs `readiness.providers.full`.
    - Pass `--status-only` when `$statusOnly` is true.
- In `desktop/app/Http/Controllers/ReadinessController.php`:
  - Call `$readiness->providers(statusOnly: true)` for the Setup page.

## Verification & Safety
1. Run Rector & Pint (`composer format`), PHPStan (`composer analyse`), and Pest tests (`composer test`) in `cli/`.
2. Run Biome (`npm run check`), TypeScript (`npm run types:check`), and Pest tests in `desktop/`.
3. Verify via `php artisan tinker` that status resolution takes < 50ms.
4. Reminder to user to run `./build` for testing the updated CLI binary.
