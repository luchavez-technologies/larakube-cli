# Plan: Drive Domain Detector, Admin Email Cleanup & Local TLD Consistency

## Goal Description
1. **Drive ExternalDNS Domain Detector**: Replace the raw text input "Primary Drive Domain" in the Drive Management empty state (`/drive`) with the standardized `<HostPicker />` component. This enables automatic detection of cluster ExternalDNS zones (e.g. `*.larakube.app`), provider auto-sync badges (Cloudflare, Route 53, etc.), local TLD support (`.kube`, `.dev.test`), and live URL previews.
2. **Admin Email Cleanup (Drive & N8N)**:
   - **Drive (oCIS)**: Remove the redundant "Admin Email (Optional)" input from `drive-empty-state.tsx` and `DriveController.php`. ownCloud Infinite Scale is provisioned headlessly with `admin` and a secure random password saved to Kubernetes Secrets and OpenBao; it does not accept or use an admin email.
   - **N8N & Quick Launch**: In `quick-launch-modal.tsx`, the Administrator Email field is currently hardcoded for all apps. Update `QuickActionController::fields` and `quick-launch-modal.tsx` to conditionally render the email field only for tools that actually require it (`needsAdminEmail`), hiding it for N8N (where the owner account is created by the first web visitor).
3. **Local TLD Consistency (CLI vs Desktop)**:
   - **Root Cause Identified**: In `desktop/app/Services/LaraKube/ProjectInspector.php`, the constructor defines `private ?GlobalSettings $globalSettings = null`. Because it has a default value (`= null`), Laravel's dependency injection container resolves it to `null`. As a result, `$globalTld = $this->globalSettings?->getLocalTld() ?? 'kube'` always evaluated to the fallback `'kube'`, completely ignoring the developer's global TLD setting (`~/.larakube/config.json`, which was set to `"localTld": "test"`).
   - When the user created the Vite app and viewed it in Desktop, the UI showed `hello-vite.kube` with `Default (.kube)`. But when running `larakube up` (Rebuild / Up), the CLI read `~/.larakube/config.json` directly and correctly outputted `https://hello-vite.test` and `https://mailpit.test`.
   - **Fix**: Resolve `GlobalSettings` dynamically via `app(GlobalSettings::class)` in `ProjectInspector` when not explicitly supplied, and standardize any inconsistent hardcoded fallback TLD strings in `desktop/resources/js/pages/projects/show.tsx`.

---

## User Review Required

> [!IMPORTANT]
> **Admin Email Removal on Drive**:
> ownCloud Infinite Scale (oCIS) creates the admin user with username `admin` and a 24-character random password stored in `ocis-admin-secret` (and OpenBao). Passing `--admin-email` is not supported by `OcisInitCommand` and was never utilized. Removing this field simplifies the form and prevents potential CLI errors.

> [!NOTE]
> **N8N Owner Account Setup**:
> As noted in your observation, n8n does not take an admin email during initial provisioning because n8n Community Edition requires the owner to create their account via the initial setup wizard on first web visit. We will hide the email field in the Quick Launch modal for tools that do not need it.

> [!TIP]
> **Local TLD Inconsistency Diagnosis**:
> The CLI and Desktop share `~/.larakube/config.json`. The user had `"localTld": "test"` configured in their global config. CLI `larakube up` used `test` correctly. Desktop's `ProjectInspector` had a bug where `$this->globalSettings` was `null` when auto-wired by Laravel's DI container, causing it to fall back to hardcoded `'kube'`. Auto-wiring `GlobalSettings` in `ProjectInspector` permanently eliminates this discrepancy.

---

## Proposed Changes

### Component 1: Desktop - Drive Management UI & Controller

#### [MODIFY] `desktop/app/Http/Controllers/DriveController.php`
- Inject `DomainCatalog $domains` and `GlobalSettings $settings` into `index()`.
- Resolve `$serverDomains = $domains->forContext($context) ?? []`, `$isLocal = Server::isStackLocal($stack)`, and `$localTld = $settings->getLocalTld()`.
- Pass `domains`, `isLocal`, and `localTld` to `Inertia::render('drive/index', ...)`.
- In `deploy()`, remove `$adminEmail` extraction and do not append `--admin-email` to `$args`.

#### [MODIFY] `desktop/resources/js/pages/drive/index.tsx`
- Accept `domains`, `isLocal`, and `localTld` in `Props`.
- Forward them to `<DriveEmptyState />`.

#### [MODIFY] `desktop/resources/js/pages/drive/drive-empty-state.tsx`
- Import `<HostPicker />` from `@/components/host-picker`.
- Replace the raw "Primary Drive Domain" input with:
  ```tsx
  <HostPicker
      name="domain"
      value={domain}
      onChange={setDomain}
      domains={domains}
      serverName={server.name}
      serverIp={server.ip}
      defaultSubdomain="drive"
      isLocal={isLocal}
      localTld={localTld}
      label="Drive Domain / Host Address"
      showBanners={true}
  />
  ```
- Remove the "Admin Email (Optional)" field and state.

---

### Component 2: Desktop - Quick Launch & Tool Fields

#### [MODIFY] `desktop/app/Http/Controllers/QuickActionController.php`
- In `fields()`, compute `$needsAdminEmail`:
  ```php
  $needsAdminEmail = is_array($row['initFields'] ?? null)
      ? collect($row['initFields'])->contains('key', 'adminEmail')
      : (bool) ($row['requiresAdminEmail'] ?? false);
  ```
- Return `needsAdminEmail` alongside `fields` in JSON response.

#### [MODIFY] `desktop/resources/js/components/quick-launch-modal.tsx`
- Add `const [needsAdminEmail, setNeedsAdminEmail] = useState<boolean>(false)`.
- When fetching `/quick-actions/fields` for the selected app, set `setNeedsAdminEmail(res.data.needsAdminEmail ?? false)`.
- Wrap the Administrator Email input section in `{needsAdminEmail && ( ... )}` so apps like n8n and oCIS don't display a useless email input.

---

### Component 3: Local TLD Consistency (Desktop & UI)

#### [MODIFY] `desktop/app/Services/LaraKube/ProjectInspector.php`
- In `__construct()`, resolve `$this->globalSettings`:
  ```php
  public function __construct(
      private FrameworkCatalog $catalog,
      private ?GlobalSettings $globalSettings = null,
      private ?ToolLocator $locator = null,
  ) {
      $this->globalSettings = $globalSettings ?? (app()->bound(GlobalSettings::class) ? app(GlobalSettings::class) : null);
      $this->locator = $locator ?? (app()->bound(ToolLocator::class) ? app(ToolLocator::class) : null);
  }
  ```
- In `inspect()`, resolve `$globalTld`:
  ```php
  $globalTld = $this->globalSettings?->getLocalTld() ?? 'kube';
  ```
  Now that `$this->globalSettings` is properly resolved from the container, `$globalTld` accurately returns the value from `~/.larakube/config.json` (`test` in this case, or any custom TLD).

#### [MODIFY] `desktop/resources/js/pages/projects/show.tsx`
- Harmonize fallback TLD in `show.tsx` so `effectiveTld` uniformly uses `project.effectiveTld || project.localTld || project.globalTld || 'kube'` across lines 204, 616, and 2388 (replacing divergent `'test'` vs `'kube'` fallbacks).

---

### Component 4: Tests

#### [MODIFY] `desktop/tests/Feature/DriveTest.php`
- Update `test('deploying Drive starts a drive-deploy run with options')` to assert that `--admin-email` is no longer in the command arguments.
- Verify that `domains`, `isLocal`, and `localTld` are supplied to the Inertia page.

#### [MODIFY] `desktop/tests/Feature/QuickActionControllerTest.php`
- Add test verifying that `/quick-actions/fields` returns `needsAdminEmail: false` for N8N and `true` for tools that require an admin email.

#### [MODIFY] `desktop/tests/Feature/ProjectsTest.php`
- Add test verifying that `ProjectInspector::inspect()` returns the custom `globalTld` from `GlobalSettings` when the project does not have an explicit `localTld` override.

---

## Verification Plan

### Automated Tests
1. **Desktop Feature Tests**:
   ```bash
   php artisan test tests/Feature/DriveTest.php
   php artisan test tests/Feature/QuickActionControllerTest.php
   php artisan test tests/Feature/ProjectsTest.php
   ```
2. **Linting & Type Checking**:
   ```bash
   npm run check
   npm run types:check
   composer lint
   composer types:check
   ```
3. **Full Desktop Test Suite**:
   ```bash
   php artisan test --parallel
   ```

### Manual Verification
1. **Drive Domain Detector (`/drive`)**:
   - Verify `<HostPicker />` renders with subdomain pre-filled to `drive`.
   - Verify cluster ExternalDNS domains appear in the dropdown with the "ExternalDNS Auto-Sync" badge.
   - Verify no "Admin Email" field is shown.
2. **Quick Launch N8N (`/`)**:
   - Open Quick Launch modal and select **n8n Automation**.
   - Verify Step 3 ("Config") hides the "Administrator Email" field.
   - Select **PocketBase** and verify "Administrator Email" appears.
3. **Local TLD Inconsistency**:
   - Visit the Vite app (`hello-vite`) in Desktop UI.
   - Verify that the local address displays `https://hello-vite.test` and the TLD override dropdown shows `Default (.test)`.
   - Rebuilding/upping the app matches the CLI output with 100% parity.
