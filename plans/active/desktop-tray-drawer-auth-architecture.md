# Desktop Architecture: System Tray, In-Page Activity Drawer & Deep Link Alignment

## 1. Context & Objectives
This plan covers three architectural additions to LaraKube Desktop:
1. **System Tray / MenuBar (`MenuBar`)**: Provide a system tray icon (Windows notification area & macOS menu bar) with quick status and window controls.
2. **GCP Sign-In & `OpenedFromURL` (`larakube://`) Reconciliation**: Reconcile browser-to-desktop authentication with the CLI-driven standard so the CLI remains authoritative and zero drift occurs.
3. **In-Page Activity Log Drawer & Bottom HUD (Option 2)**: Replace jarring full-page redirects to `/runs/{id}` with a persistent in-page status HUD and slide-over terminal log drawer, with a detachable window fallback (Option 1).

---

## 2. Item 1: System Tray Icon (`MenuBar`)

### Implementation
- In `desktop/app/Providers/NativeAppServiceProvider.php`:
  Initialize `MenuBar::create()` alongside `Window::open()`.
  ```php
  MenuBar::create()
      ->icon(public_path('icon.png'))
      ->tooltip('LaraKube')
      ->onlyShowContextMenu()
      ->withContextMenu(
          Menu::new()
              ->link(url('/'), 'Open LaraKube')
              ->separator()
              ->label('Cluster: Ready')
              ->separator()
              ->quit('Quit LaraKube')
      );
  ```
- **Lifecycle Integration**:
  Quitting from the system tray menu triggers Electron's quit sequence, which fires `WindowClosed` and `PowerMonitor\Shutdown`, executing the WSL termination listener implemented previously.

---

## 3. Item 2: GCP Sign-In, `OpenedFromURL` & CLI Alignment

### Architectural Reconciliation (Zero CLI Drift)
1. **CLI Authority**:
   - In `cli`, `CloudLoginCommand` executes:
     - Interactive terminal: `gcloud auth login --update-adc`
     - Non-interactive / GUI (`--json`): `gcloud auth login --no-launch-browser --update-adc`, outputs the OAuth URL on stderr, and reads the authorization code line from stdin.
2. **Desktop Orchestration**:
   - `GcpSignIn.php` launches `larakube cloud:login --provider=gcp --json` as a child process.
   - When Google OAuth returns an authorization code, Desktop passes the code into the CLI process standard input via `ChildProcess::message(trim($code)."\n", $run->alias())`.
3. **Zero Drift Principle**:
   - The CLI remains the sole entity that invokes `gcloud`, manages credentials, and writes authentication tokens.
   - `OpenedFromURL` (`larakube://auth/gcp?code=...`) merely captures the code from the system browser and injects it into the CLI's stdin. The CLI protocol (`--json` over stdio) is completely preserved.

---

## 4. Item 3: In-Page Activity Drawer / Bottom HUD (Option 2)

### The Problem
Controllers currently use `return to_route('runs.show', $run);`, which abruptly redirects the user away from their active view (e.g. Server Show, Create Server, Settings) to the dedicated `/runs/{id}` page.

### The Solution (In-Page Drawer + HUD)
1. **Controller Behavior**:
   - Controllers dispatch the run and return `back()->with('active_run_id', $run->id)` or redirect with a flash/query parameter.
2. **Global Bottom HUD Component (`desktop/resources/js/components/run-drawer.tsx`)**:
   - Positioned fixed at the bottom of `AppLayout`.
   - When an active Run is detected (or polls recent running runs):
     - Displays a compact floating status pill: `[ ⟳ <Run Label> | <Status/Step> | [View Logs] | [X] ]`.
     - Clicking **View Logs** expands a slide-up / slide-over drawer showing live streaming stdout/stderr without leaving the current page.
     - Includes a **Detach Window** button (`[ ↗ Detach ]`) as a direct bridge to Option 1 (opening a secondary native OS window).
     - Includes a **Full Screen** link to `/runs/{id}` if the user prefers the full view.

---

## 5. Verification & Testing
- Unit and feature tests in `desktop/tests/Feature/` for `MenuBar` initialization and run drawer state.
- Pre-commit checks: `composer lint`, `composer types:check`, `npm run types:check`, `composer test`.
