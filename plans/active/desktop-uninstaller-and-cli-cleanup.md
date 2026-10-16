# Architectural Plan: LaraKube Desktop Uninstallation & CLI Cleanup Suite

**Document ID:** `PLAN-2026-DESKTOP-UNINSTALLER`  
**Status:** Completed  
**Target Applications:** LaraKube Desktop (NativePHP / Electron), LaraKube CLI  
**Target Environments:** macOS (Darwin), Windows (Win32 / WSL 2), Linux  

---

## 1. Executive Summary & The NativePHP Context

### 1.1 The NativePHP Documentation Puzzle
When exploring the official [NativePHP documentation](https://nativephp.com), there is **zero mention** of how an application uninstaller is created, configured, or customized.

**Why is this the case?**
NativePHP's architecture is strictly an application-layer bridge: it compiles and boots a local PHP-FPM / Laravel runtime within an Electron shell powered by Chromium and Node.js. NativePHP purposefully abstracts and delegates all packaging, code signing, installer compilation, and distribution tasks to **`electron-builder`**.

Because NativePHP leaves packaging to `electron-builder`, it does not provide an internal PHP API for OS uninstallation. Operating systems treat application uninstallation in fundamentally incompatible ways:
- **macOS** uses drag-and-drop `.app` bundles inside `.dmg` disk images with **no OS uninstaller hooks**.
- **Windows** uses executable installers (NSIS `.exe` or WiX `.msi`) that create active uninstaller executables registered in Windows Settings / Control Panel.
- **Linux** uses `.AppImage` (portable zero-install binary) or `.deb`/`.rpm` package managers.

### 1.2 The User Problem
When a developer installs LaraKube Desktop, the application automatically provisions system-level tools:
1. **The LaraKube CLI binary** (`~/.larakube/bin/larakube`).
2. **Companion DevOps tools** (`~/.larakube/bin/{kubectl,tofu,k9s,tea,gh,aws,gcloud}`).
3. **Shell profile integration** (`export PATH="$HOME/.larakube/bin:$PATH"` in `~/.zshrc`, `~/.bashrc`, `~/.bash_profile`, `~/.profile`).
4. **Cluster configs and state** (`~/.larakube/`).
5. **Windows WSL 2 Virtual Machine** (`larakube-ubuntu` distro and virtual disk `ext4.vhdx`).
6. **Application state & caches** (`~/Library/Application Support/LaraKube` or `%APPDATA%\LaraKube`).

If a user uninstalls LaraKube Desktop by simply dragging `LaraKube.app` to the Trash on macOS, or running the default Windows uninstaller, **all these external tools and shell modifications remain permanently orphaned on disk**.

Furthermore, many developers love and rely on the **LaraKube CLI** for automated scripts and terminal workflows. They might wish to uninstall the heavy Desktop GUI while **intentionally retaining the CLI tools and shell integration**, or conversely perform a 100% complete factory wipe.

This document plans the architecture and implementation for:
1. An **In-App Uninstallation Suite** with granular checkboxes for optional CLI and cluster cleanup.
2. An **NSIS Uninstaller Hook (`installer.nsh`)** on Windows that prompts or silently cleans up during OS Add/Remove Programs uninstall.
3. Clean **Shell PATH reversal** and companion tool uninstallation.

---

## 2. Platform Realities: macOS vs. Windows

```
┌────────────────────────────────────────────────────────────────────────┐
│                          UNINSTALLATION PARADIGM                       │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
           ┌────────────────────────┴────────────────────────┐
           ▼                                                 ▼
┌──────────────────────────────────────┐  ┌──────────────────────────────────────┐
│                macOS                 │  │               Windows                │
│            (Drag & Drop)             │  │            (NSIS .exe)               │
├──────────────────────────────────────┤  ├──────────────────────────────────────┤
│ • Distributed as .dmg containing     │  │ • Distributed as NSIS Setup .exe     │
│   LaraKube.app                       │  │ • Registered in Windows Settings:    │
│ • User drags app to Trash            │  │   "Installed apps" & Add/Remove      │
│ • OS HAS NO UNINSTALL SCRIPT HOOK!   │  │ • OS launches Uninstall LaraKube.exe │
│ • Dragging to Trash NEVER executes   │  │ • Active script execution via NSIS   │
│   any cleanup script                 │  │   macros (!macro customUnInstall)    │
│ • All ~/.larakube files are ORPHANED │  │ • Can prompt user with MessageBox    │
│                                      │  │ • Can execute wsl --unregister       │
│ ► SOLUTION: In-App Uninstaller       │  │ ► SOLUTION: Dual Approach:           │
│   (Settings > Danger Zone & Menu)    │  │   1. In-App Uninstaller Wizard       │
│   User triggers cleanup from inside  │  │   2. NSIS customUnInstall hook       │
│   the app, which trashes bundle &    │  │      when triggered from Windows     │
│   quits cleanly.                     │  │      Settings / Control Panel        │
└──────────────────────────────────────┘  └──────────────────────────────────────┘
```

### 2.1 macOS: The In-App Uninstaller Standard
On macOS, because the operating system provides no uninstallation hook for `.app` bundles, industry-standard developer tools (Docker Desktop, OrbStack, Visual Studio Code, Homebrew casks) implement an **In-App Uninstaller**:
1. Accessible from **Settings → Danger Zone** or the macOS App Menu bar (**LaraKube → Uninstall LaraKube Desktop…**).
2. The user is presented with a dialog displaying granular checkboxes to configure their uninstallation.
3. Upon confirmation, LaraKube Desktop:
   - Cleans the selected components (CLI binaries, shell profile PATH lines, cluster configs).
   - Uses `Native\Desktop\Facades\Shell::trashFile('/Applications/LaraKube.app')` to move the `.app` bundle to macOS Trash.
   - Invokes `Native\Desktop\Facades\App::quit()` to terminate the process.
4. **Safety Net for Post-Trash Cleanup**: If a user already dragged `LaraKube.app` to Trash without running the in-app wizard, they can run `larakube uninstall --all` or a one-line curl command:
   ```bash
   curl -fsSL https://cli.larakube.app/uninstall.sh | bash
   ```

### 2.2 Windows: The NSIS Uninstaller Hook + In-App Wizard
On Windows, `electron-builder` packages the application using the **NSIS (Nullsoft Scriptable Install System)** target.
1. When LaraKube is installed on Windows, NSIS registers an entry in **Windows Settings → Apps → Installed apps** that invokes:
   `%LOCALAPPDATA%\Programs\LaraKube\Uninstall LaraKube.exe`
2. `electron-builder` allows extending NSIS scripts via `buildResources`:
   `desktop/nativephp/electron/build/installer.nsh`.
3. Inside `installer.nsh`, we inject the `customUnInstall` macro:
   ```nsis
   !macro customUnInstall
     ${IfNot} ${Silent}
       MessageBox MB_YESNO|MB_ICONQUESTION \
         "Do you also want to remove the LaraKube CLI tools and the LaraKube Linux (WSL) environment from your computer?$\n$\nSelect 'No' if you plan to keep using the LaraKube CLI from PowerShell or WSL." \
         IDNO skipLaraKubeCleanup
     ${EndIf}

     ; 1. Unregister WSL 2 Distro (removes virtual disk and Linux environment)
     nsExec::Exec 'wsl.exe --unregister larakube-ubuntu'

     ; 2. Delete Windows profile .larakube directory
     RMDir /r "$PROFILE\.larakube"

     skipLaraKubeCleanup:
   !macroend
   ```
4. In addition, the **In-App Uninstallation Suite** is available on Windows through Settings → Danger Zone, allowing the user to create an automated backup of user projects (`Wsl::backupUserHome()`) before removing the WSL distro.

---

## 3. Granular Component Breakdown (Optional Inclusions)

The uninstaller presents users with modular options:

| Component | Default | Destruction Scope | Rationale for Making It Optional |
|---|---|---|---|
| **LaraKube Desktop App** | **Mandatory** | Application bundle (`/Applications/LaraKube.app` or `%LOCALAPPDATA%\Programs\LaraKube`) | The app itself is what the user is uninstalling. |
| **CLI Binaries & Tools** | **Checked** | `~/.larakube/bin/` (`larakube`, `kubectl`, `tofu`, `k9s`, `tea`, `gh`, `aws`, `gcloud`) | **Developers frequently want to keep the CLI!** Power users may prefer running `larakube up/down` from their shell while freeing RAM/disk taken by the Electron app. |
| **Shell PATH Export** | **Checked** | Removes `export PATH="$HOME/.larakube/bin:$PATH"` from `~/.zshrc`, `~/.bashrc`, `~/.bash_profile`, `~/.profile` | If CLI tools are kept, the shell PATH must also be kept so the terminal commands continue to function. |
| **Cluster & Tool Data** | **Unchecked** (Warned) | `~/.larakube/` (kubeconfigs, OpenBao tokens, OpenTofu cluster state, devbox configs) | Contains operational credentials for live cloud clusters (DOKS, Hetzner, AWS, GCP). Deleting this loses access to managed clusters unless backed up. |
| **Windows WSL 2 Distro** | **Checked** (Windows only) | `wsl.exe --unregister larakube-ubuntu` | Frees 5–20 GB of disk space (`ext4.vhdx`). Automatically backs up projects to `%LOCALAPPDATA%\LaraKube\backups\` first. |
| **App Support & Logs** | **Checked** | SQLite database, session tokens, updater cache (`~/Library/Application Support/LaraKube`) | Desktop application local state. |

---

## 4. Architecture & Implementation Design

### 4.1 Shell PATH Reversal: `TerminalIntegration::uninstall()`
Currently, `desktop/app/Services/LaraKube/TerminalIntegration.php` only supports `install()`.
We add `uninstall()`:

```php
/**
 * Remove the LaraKube bin directory export from all user shell configuration files.
 *
 * @return array{success: bool, files: list<string>, message: string}
 */
public function uninstall(): array
{
    $home = ToolLocator::home();
    $candidates = [
        "{$home}/.zshrc",
        "{$home}/.bashrc",
        "{$home}/.bash_profile",
        "{$home}/.profile",
    ];

    $cleaned = [];
    foreach ($candidates as $candidate) {
        if (! File::exists($candidate)) {
            continue;
        }

        $content = (string) File::get($candidate);
        if (str_contains($content, '.larakube/bin')) {
            // Remove with leading comment
            $pattern = '/\n?# Added by LaraKube Desktop\n'.preg_quote(self::EXPORT_LINE, '/').'\n?/';
            $newContent = preg_replace($pattern, "\n", $content);

            // Fallback for standalone export line
            if (str_contains((string) $newContent, self::EXPORT_LINE)) {
                $newContent = str_replace(self::EXPORT_LINE."\n", '', (string) $newContent);
                $newContent = str_replace(self::EXPORT_LINE, '', (string) $newContent);
            }

            File::put($candidate, trim((string) $newContent)."\n");
            $cleaned[] = $candidate;
        }
    }

    return [
        'success' => true,
        'files' => $cleaned,
        'message' => count($cleaned) > 0
            ? 'Removed LaraKube from shell profile configuration.'
            : 'No shell configuration files needed updating.',
    ];
}
```

### 4.2 The Uninstaller Service: `App\Services\LaraKube\DesktopUninstaller`
Create `desktop/app/Services/LaraKube/DesktopUninstaller.php`:

```php
namespace App\Services\LaraKube;

use App\Services\Wsl;
use Illuminate\Support\Facades\File;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Shell;

class DesktopUninstaller
{
    public function __construct(
        private TerminalIntegration $terminal,
        private ToolLocator $locator,
        private Wsl $wsl,
    ) {}

    /**
     * Executes the uninstallation tasks according to user selections.
     *
     * @param array{
     *     removeCli: bool,
     *     removeShellPath: bool,
     *     purgeClusterData: bool,
     *     removeWsl: bool,
     *     purgeAppData: bool,
     *     trashAppBundle: bool
     * } $options
     * @return array{success: bool, actions: list<string>, error?: string}
     */
    public function execute(array $options): array
    {
        $actions = [];

        // 1. Remove CLI Binaries if requested (~/.larakube/bin)
        if (! empty($options['removeCli'])) {
            $binDir = $this->locator->directories()[1] ?? ToolLocator::home().'/.larakube/bin';
            if (is_dir($binDir)) {
                File::deleteDirectory($binDir);
                $actions[] = "Deleted CLI binaries directory: {$binDir}";
            }
        }

        // 2. Remove Shell Profile PATH export
        if (! empty($options['removeShellPath']) || ! empty($options['removeCli'])) {
            $result = $this->terminal->uninstall();
            if (! empty($result['files'])) {
                $actions[] = 'Cleaned shell PATH from: '.implode(', ', $result['files']);
            }
        }

        // 3. Purge ~/.larakube Cluster Data & Configuration
        if (! empty($options['purgeClusterData'])) {
            $larakubeDir = ToolLocator::home().'/.larakube';
            if (is_dir($larakubeDir)) {
                File::deleteDirectory($larakubeDir);
                $actions[] = "Purged LaraKube data directory: {$larakubeDir}";
            }
        }

        // 4. Windows WSL 2 Distro Removal
        if ($this->locator->isWindows() && ! empty($options['removeWsl'])) {
            $resetResult = $this->wsl->reset();
            $actions[] = "WSL reset: {$resetResult['message']}";
        }

        // 5. Purge AppData / Application Support (database, logs, caches)
        if (! empty($options['purgeAppData'])) {
            $appData = storage_path();
            // Clear cache and session files
            @File::cleanDirectory(storage_path('framework/cache'));
            @File::cleanDirectory(storage_path('framework/sessions'));
            @File::cleanDirectory(storage_path('logs'));
            $actions[] = 'Purged local cache and logs';
        }

        // 6. Trash Application Bundle (macOS)
        if (! empty($options['trashAppBundle']) && $this->locator->isDarwin()) {
            $appBundlePath = '/Applications/LaraKube.app';
            if (File::exists($appBundlePath)) {
                Shell::trashFile($appBundlePath);
                $actions[] = "Moved {$appBundlePath} to Trash";
            }
        }

        return [
            'success' => true,
            'actions' => $actions,
        ];
    }

    /**
     * Terminate the application immediately after uninstall execution.
     */
    public function finishAndQuit(): void
    {
        App::quit();
    }
}
```

### 4.3 Controller & Route: `App\Http\Controllers\UninstallController`
Create `desktop/app/Http/Controllers/UninstallController.php`:

```php
namespace App\Http\Controllers;

use App\Services\LaraKube\DesktopUninstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UninstallController extends Controller
{
    public function execute(Request $request, DesktopUninstaller $uninstaller): JsonResponse
    {
        $validated = $request->validate([
            'removeCli' => 'required|boolean',
            'removeShellPath' => 'required|boolean',
            'purgeClusterData' => 'required|boolean',
            'removeWsl' => 'required|boolean',
            'purgeAppData' => 'required|boolean',
            'trashAppBundle' => 'required|boolean',
        ]);

        $result = $uninstaller->execute($validated);

        // Schedule immediate quit after response flush
        dispatch(function () use ($uninstaller) {
            usleep(500000); // 500ms delay to allow HTTP response to reach the browser window
            $uninstaller->finishAndQuit();
        })->afterResponse();

        return response()->json($result);
    }
}
```

Add route in `desktop/routes/web.php`:
```php
Route::post('/uninstall', [UninstallController::class, 'execute'])->name('uninstall.execute');
```

### 4.4 App Menu Integration in `NativeAppServiceProvider`
In `desktop/app/Providers/NativeAppServiceProvider.php`, customize the application menu on macOS:
```php
Menu::new()
    ->appMenu()
    ->submenu('Help', Menu::new()
        ->link('https://larakube.app/docs', 'Documentation')
        ->separator()
        ->link(url('/settings?action=uninstall'), 'Uninstall LaraKube Desktop…')
    )
    ->register();
```

### 4.5 Windows NSIS Hook: `desktop/nativephp/electron/build/installer.nsh`
Create `desktop/nativephp/electron/build/installer.nsh`:

```nsis
; LaraKube Desktop NSIS Custom Uninstallation Script
; Injected by electron-builder via directories.buildResources = 'build'

!macro customUnInstall
  ; Check if running in silent mode (/S)
  ${IfNot} ${Silent}
    MessageBox MB_YESNO|MB_ICONQUESTION|MB_DEFBUTTON2 \
      "Do you also want to remove LaraKube CLI tools (~/.larakube) and the LaraKube Linux (WSL) environment from your computer?$\n$\nSelect 'No' if you want to keep using the LaraKube CLI from PowerShell or WSL." \
      IDNO skipLaraKubeClean
  ${EndIf}

  DetailPrint "Terminating and unregistering LaraKube Linux WSL environment..."
  nsExec::Exec 'wsl.exe --terminate larakube-ubuntu'
  nsExec::Exec 'wsl.exe --unregister larakube-ubuntu'

  DetailPrint "Removing LaraKube CLI tools and user configuration..."
  RMDir /r "$PROFILE\.larakube"

  skipLaraKubeClean:
!macroend
```

### 4.6 Frontend UI: Settings "Danger Zone" Uninstallation Modal
In `desktop/resources/js/pages/settings/index.tsx`:
Add an **"Uninstall & Cleanup"** section in the Danger Zone with a modal:
- **Title**: "Uninstall LaraKube Desktop & Clean Up System"
- **Description**: "Customize what gets removed from your system. You can choose to keep CLI tools if you want to continue using LaraKube from your terminal."
- **Interactive Options**:
  - `[x] Remove LaraKube CLI & Companion Tools (~/.larakube/bin)`
    - *Sub-bullet*: Removes `larakube`, `kubectl`, `tofu`, `k9s`, `tea`, `gh`.
  - `[x] Clean Shell PATH Configuration`
    - *Sub-bullet*: Removes export lines from `.zshrc`, `.bashrc`, and `.profile`.
  - `[ ] Purge Cluster Credentials & Local State (~/.larakube)`
    - *Sub-bullet*: ⚠️ Warning: Deletes saved kubeconfigs, OpenTofu state, and secrets.
  - `{windows && [x] Remove LaraKube Linux (WSL 2) Distro}`
    - *Sub-bullet*: Reclaims disk space by deleting `larakube-ubuntu` (backups kept in `%LOCALAPPDATA%\LaraKube\backups`).
  - `{isDarwin && [x] Move LaraKube.app to macOS Trash}`
    - *Sub-bullet*: Moves the application bundle to Trash and exits.
- **Action Buttons**:
  - `Cancel` (Secondary button with `<XCircle />` icon)
  - `Uninstall & Quit LaraKube` (Destructive button with `<Trash2 />` icon and loading spinner)

---

## 5. Testing & Verification Strategy (TDD)

Following LaraKube's mandatory TDD rules:

### 5.1 Unit Tests
1. `tests/Unit/TerminalIntegrationTest.php`:
   - Test `uninstall()` cleanly removes export line from mocked `.zshrc` and `.bashrc`.
   - Test `uninstall()` is idempotent and safe when files don't contain LaraKube exports.
   - Test preserving other user exports and PATH customizations.
2. `tests/Unit/DesktopUninstallerTest.php`:
   - Test selective component deletion (`removeCli = false` preserves `~/.larakube/bin`).
   - Test `removeCli = true` invokes directory deletion and shell PATH cleanup.
   - Test `purgeClusterData = true` deletes `~/.larakube`.
   - Test Windows WSL call delegation.

### 5.2 Feature Tests
1. `tests/Feature/UninstallControllerTest.php`:
   - Test POST `/uninstall` validates boolean inputs.
   - Test POST `/uninstall` calls `DesktopUninstaller::execute()`.
   - Test response contains list of executed cleanup actions.

### 5.3 Code Quality & Formatting
- Proactively run `composer format` (Pint + Rector).
- Proactively run `composer analyse` (PHPStan level 8).
- Proactively run `composer test` (Pest).

---

## 6. Implementation Checklist & Phasing

- [x] **Phase 1: Shell Reversal Service**
  - Implement `TerminalIntegration::uninstall()`.
  - Write Pest unit tests covering all shell variants.
- [x] **Phase 2: Core Uninstaller Service & Controller**
  - Implement `DesktopUninstaller.php`.
  - Implement `UninstallController.php` & route `POST /uninstall`.
  - Write feature tests for endpoint and service orchestration.
- [x] **Phase 3: Windows NSIS Installer Script**
  - Create `desktop/nativephp/electron/build/installer.nsh` with `!macro customUnInstall`.
  - Verify electron-builder configuration picks up the custom script.
- [x] **Phase 4: Frontend UI in Settings**
  - Add "Uninstall LaraKube Desktop" to Settings Danger Zone.
  - Implement modal with granular checkboxes, safety warnings, and Lucide icons.
  - Connect to `POST /uninstall` with feedback and automatic app termination.
- [x] **Phase 5: CLI Fallback Command Alignment**
  - Review CLI `cli/app/Commands/UninstallCommand.php` to align with companion tool removal and shell profile cleanup.
