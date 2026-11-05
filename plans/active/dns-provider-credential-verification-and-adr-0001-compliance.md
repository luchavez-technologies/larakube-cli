# Plan · DNS Provider Credential Verification & Desktop ADR 0001 Compliance

**Status:** Approved by Operator · **Owner:** LaraKube CLI & Desktop · **Started:** 2026-11-05  
**Home (Repository Invariant):** `cli/plans/active/dns-provider-credential-verification-and-adr-0001-compliance.md`

---

## 1. Context & Architectural Root Cause

In response to users missing required Cloudflare token permissions (`Zone → DNS → Edit` and `Zone → Zone Settings → Read`), a 4-step token verifier was initially implemented directly in the Desktop backend (`desktop/app/Http/Controllers/CloudflareController.php`).

This violated **Desktop ADR 0001** (*"Drive the LaraKube CLI as a subprocess; never embed its code"*):
- **Desktop must not implement CLI domain/infrastructure logic directly.**
- A CLI user running `larakube cloud:create` or `larakube tool:init --tool=external-dns` received zero verification, only finding out days or hours later when ExternalDNS pods crash-looped or failed to sync.
- Logic was split between CLI's Saloon integrations (`InteractsWithCloudflareApi.php`) and Desktop's Laravel `Http` facade.

### Provider Distinction
- **`CloudProvider` (`cli/app/Enums/CloudProvider.php`)**: Manages Compute infrastructure (DO, GCP, AWS, Hetzner).
- **`DnsProvider` (`cli/app/Enums/DnsProvider.php`)**: Manages DNS & TLS automation (Cloudflare, AWS Route 53, Google Cloud DNS).

The Cloudflare token verification belongs under **`DnsProvider`** and its supporting traits in the CLI.

---

## 2. Target Architecture

```
                    ┌─────────────────────────┐
                    │      Desktop UI         │
                    │   (DnsTlsFields.tsx)    │
                    └────────────┬────────────┘
                                 │ POST /cloudflare/verify-token
                                 ▼
                    ┌─────────────────────────┐
                    │  Desktop Controller     │
                    │ (CloudflareController)  │
                    └────────────┬────────────┘
                                 │ Subprocess call:
                                 │ larakube dns:verify --provider=cloudflare --token=... --json
                                 ▼
     ┌─────────────────────────────────────────────────────────┐
     │                       LaraKube CLI                      │
     │                                                         │
     │  ┌───────────────────────────────────────────────────┐  │
     │  │   DnsVerifyCommand (`dns:verify`)                 │  │
     │  │   - Terminal: Laravel Prompts table & spinners    │  │
     │  │   - Headless: --json output for Desktop & CI      │  │
     │  └─────────────────────────┬─────────────────────────┘  │
     │                            │                            │
     │  ┌─────────────────────────▼─────────────────────────┐  │
     │  │   DnsProvider Enum (`cli/app/Enums/DnsProvider`)  │  │
     │  │   - verifyCredential(array $credential): array   │  │
     │  └─────────────────────────┬─────────────────────────┘  │
     │                            │                            │
     │  ┌─────────────────────────▼─────────────────────────┐  │
     │  │   InteractsWithCloudflareApi Trait (Saloon v3)    │  │
     │  │   - cloudflareVerifyToken(string $token): array   │  │
     │  │     1. /user/tokens/verify                        │  │
     │  │     2. /zones (discovery)                         │  │
     │  │     3. /zones/{id}/dns_records (DNS Edit probe)   │  │
     │  │     4. /zones/{id}/settings/ssl (SSL Read probe)  │  │
     │  └───────────────────────────────────────────────────┘  │
     └─────────────────────────────────────────────────────────┘
```

---

## 3. Implementation Steps

### Phase 1: CLI Core Engine (`cli/`)
1. **Extend `InteractsWithCloudflareApi.php`**:
   - Add `cloudflareVerifyToken(string $token): array` returning:
     ```php
     [
         'valid' => bool,
         'status' => string, // 'active' | 'invalid' | 'missing'
         'zones' => string[], // ['domain1.com', 'domain2.com']
         'checks' => [
             'token_active' => bool,
             'zones_accessible' => bool,
             'dns_edit' => bool,
             'ssl_read' => bool,
         ],
         'errors' => string[],
     ]
     ```
   - Utilize existing Saloon connectors and requests (`CloudflareConnector`, `ListZonesRequest`, `GetZoneSettingRequest`, `ListDnsRecordsRequest`).
   - Add Saloon request `VerifyTokenRequest` (`GET /client/v4/user/tokens/verify`) if not already present.

2. **Add `verifyCredential` on `DnsProvider.php`**:
   - Dispatch based on provider enum:
     - `DnsProvider::CLOUDFLARE`: calls `cloudflareVerifyToken`.
     - `DnsProvider::ROUTE53`: checks AWS credentials & Route53 zone access.
     - `DnsProvider::GOOGLE`: checks GCP service account & Cloud DNS access.

3. **Create `DnsVerifyCommand.php` (`dns:verify`)**:
   - Signature: `dns:verify {--provider=cloudflare} {--token=} {--json}`
   - In interactive mode: Prompts via Laravel Prompts, renders live spinners and status table.
   - In `--json` mode: Emits clean JSON envelope `{ success: true, result: [...] }`.

4. **Integrate into `ExternalDnsInitCommand.php` & `CloudCreateCommand.php`**:
   - In CLI interactive prompts, verify the supplied token before writing to Kubernetes secrets.
   - If `dns_edit` is missing, warn operator immediately and provide link to fix permissions.

5. **Pest Tests & Quality Gates in CLI**:
   - Write unit tests in `cli/tests/Unit/InteractsWithCloudflareApiTest.php` and `cli/tests/Feature/DnsVerifyCommandTest.php`.
   - Run `composer format`, `composer analyse`, `composer test`.
   - Note: Do NOT run `./build` (forbidden per workspace rules; instruct user to build).

### Phase 2: Desktop Integration (`desktop/`)
1. **Refactor `desktop/app/Http/Controllers/CloudflareController.php`**:
   - Remove direct `Http::withToken()` calls.
   - Delegate verification to the CLI runner:
     `$cli->runIsolated(['dns:verify', '--provider=cloudflare', '--token=' . $token, '--json'])`.
   - Forward structured JSON response to React frontend.
2. **Desktop Pest Tests**:
   - Update `desktop/tests/Feature/CloudflareTest.php` to mock CLI invocation and verify contract compatibility.
   - Run `composer test` in `desktop`.

---

## 4. Verification & Testing Plan
- **CLI Tests**: `composer test -- --filter=DnsVerify` & `InteractsWithCloudflareApiTest`.
- **PHPStan Analysis**: `composer analyse` (0 errors).
- **Code Standards**: `composer format` (Pint & Rector).
- **Desktop Tests**: `cd desktop && php artisan test --filter=CloudflareTest`.

