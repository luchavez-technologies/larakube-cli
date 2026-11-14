# Plan: Windows/WSL Tool Separation, Environment Indicators, and Copyable Tool Locations

## Context & Goals
1. **Separate LaraKube CLI entries on Windows (`larakube` vs `larakube-wsl`)**:
   - On Windows, the native Windows binary (`%USERPROFILE%\.larakube\bin\larakube.exe`) and the WSL distro binary (`/usr/local/bin/larakube` inside `larakube-ubuntu`) are currently combined into a single `larakube` row on the Setup page.
   - Separating them into two distinct entries on Windows (`larakube` for Windows and `larakube-wsl` for WSL when in Local development mode) allows independent status reporting, version display, file location display, and separate **Install** / **Update** actions.
2. **Indicate Windows vs WSL environment per tool (Windows context ONLY)**:
   - When running on Windows (`ToolLocator::isWindows() === true`), tag each tool in the catalog with `environment: 'windows' | 'wsl'`:
     - `'windows'`: `larakube`, `kubectl`, `k9s`, `tofu`, `ssh`, `aws`, `gcloud`
     - `'wsl'`: `larakube-wsl`, `gh`, `tea`, `git`, `docker`, `podman`, `dnsmasq`
   - On macOS and Linux (`ToolLocator::isWindows() === false`), `environment` is `null` and `larakube-wsl` is omitted from `catalog()` so macOS/Linux users never see Windows/WSL indicators or duplicate CLI entries.
3. **Make tool file locations (`tool.path`) copyable**:
   - In `ToolRow` (`desktop/resources/js/components/setup/tool-catalog.tsx`), make the displayed binary path (`tool.path`) clickable to copy to clipboard with `Copy` / `Check` Lucide icon feedback.

---

## Ordered Implementation Steps

### Step 1: Save Plan in `cli/plans/active/windows-wsl-tool-separation-and-copyable-paths.md`
- Write a copy of this plan to `cli/plans/active/windows-wsl-tool-separation-and-copyable-paths.md` per the workspace Plan Artifact Location Standard.

### Step 2: Backend — `ToolLocator`, `ReadinessCheck`, `CliInstaller`, and `ToolInstallController`
1. **`desktop/app/Services/LaraKube/ToolLocator.php`**:
   - Change `private const WINDOWS_NATIVE_TOOLS` to `public const WINDOWS_NATIVE_TOOLS = ['kubectl', 'k9s', 'tofu', 'aws', 'gcloud', 'ssh'];` so `ReadinessCheck` can reference it as the single source of truth.
   - Add a helper `public function isWindowsNativeTool(string $slug): bool` that returns `$slug === 'larakube' || in_array($slug, self::WINDOWS_NATIVE_TOOLS, true)`.

2. **`desktop/app/Services/LaraKube/ReadinessCheck.php`**:
   - In `public const TOOLS`, add `'larakube-wsl'` right after `'larakube'`.
   - Update `catalog(?array $intendedProviders = null): array`:
     - Skip any tool with `($tool['windowsOnly'] ?? false) && ! $this->locator->isWindows()`.
     - Include `'environment' => $this->locator->isWindows() ? ($this->locator->isWindowsNativeTool($slug) ? 'windows' : 'wsl') : null` on each catalog item.
   - Update `status(string $slug, bool $fresh = false): array`:
     - When `$slug === 'larakube-wsl'`, return `$this->wslCliStatus($fresh) ?? ['installed' => false, 'path' => null, 'version' => null, 'diagnostic' => 'LaraKube Linux (WSL) is not installed.']`.
     - Keep the existing `$slug === 'larakube'` behavior (including `$result['wsl']`) so `HandleInertiaRequests` and existing sidebar version checks remain 100% compatible.
   - Update `forget(?string $slug = null): void`:
     - When `$slug === null || $slug === 'larakube' || $slug === 'larakube-wsl'`, forget `'readiness.tool.larakube.wsl'` and `'readiness.tool.larakube-wsl'`.

3. **`desktop/app/Services/LaraKube/CliInstaller.php`**:
   - Add `public function installWsl(?string $channel = null, ?Wsl $wsl = null): array`:
     - Downloads the Linux binary from `$this->linuxCacheDownloadUrl($channel)` into `{$binDir}/larakube-linux-{$this->arch()}`.
     - Uses `$wsl ??= app(Wsl::class)` to check `$wsl->distro() !== null` and call `$wsl->syncCachedLinuxBinaryToDistro()`.
     - Forgets `'larakube-wsl'` and `'larakube'` in `ReadinessCheck` on success and returns `['success' => true, 'path' => '/usr/local/bin/larakube', 'error' => null]`.

4. **`desktop/app/Http/Controllers/ToolInstallController.php`**:
   - In `store()`:
     - For `$tool === 'larakube'`, install only the host binary via `$installer->install($channel)`.
     - Handle `$tool === 'larakube-wsl'` with its own `Run`, calling `$installer->installWsl($channel)` and updating the `Run` status/output accordingly.
   - In `updateTool()`:
     - Route `$tool === 'larakube-wsl'` to the WSL CLI installer (`updateCli` / shared helper with `Update` verb).

### Step 3: Frontend — `larakube.ts`, `provider-logo.tsx`, and `tool-catalog.tsx`
1. **`desktop/resources/js/types/larakube.ts`**:
   - Add `environment?: 'windows' | 'wsl' | null;` to `Tool`.
2. **`desktop/resources/js/components/provider-logo.tsx`**:
   - Add `case 'larakubewsl':` next to `case 'larakube':` so `larakube-wsl` renders the LaraKube icon.
3. **`desktop/resources/js/components/setup/tool-catalog.tsx`**:
   - In `ToolRow`:
     - When `entry.environment === 'windows'` or `entry.environment === 'wsl'`, render a compact environment badge (`Windows` or `WSL`) next to the tool label.
     - Simplify `detail` and path rendering on `ToolRow` to use `tool.version` and `tool.path` directly.
     - Replace the static `<p>{tool.path}</p>` with a copyable path button component (`CopyablePath`) with `Copy` / `Check` Lucide icons.
   - In `ToolState`:
     - Pass `{ channel }` when updating `larakube-wsl` (`handleAction(update(tool.slug).url, { channel })`).

### Step 4: Tests & Verification
1. Update and add tests in `desktop/tests/Feature/ReadinessTest.php` and `desktop/tests/Feature/UpdateCliTest.php`.
2. Run `npx vp check --fix && npx tsc --noEmit` in `desktop/`.
3. Run `composer format`, `composer analyse`, and `composer test` in `desktop/`.
