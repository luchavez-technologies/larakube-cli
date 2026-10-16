# Architectural Plan: LaraKube Desktop Menu Bar Popover & Custom Application Menu Suite

**Document ID:** `PLAN-2026-DESKTOP-MENUBAR-APP-MENU`  
**Status:** Completed  
**Target Application:** LaraKube Desktop (NativePHP / Electron)  
**Target Environments:** macOS (Darwin), Windows (Win32), Linux  

---

## 1. Goal Description & Executive Summary

### 1.1 The Context: Unlocking Native Desktop Real Estate
LaraKube Desktop currently has two underutilized native OS surfaces:
1. **The System Tray / Menu Bar Icon**: Shows only a bare two-item context menu (`Open LaraKube`, `Quit LaraKube`).
2. **The Window Application Menu**: Currently outputs generic Electron defaults (`Menu::default()`), showing an empty `"Close Window ⌘W"` on macOS and a legacy, purposeless menu strip on Windows.

### 1.2 The Solution
Deliver a unified **Native Desktop OS Integration Suite** covering:
1. **Menu Bar Popover Control Center (`/tray`)**: A sleek, dark-mode, compact `360px` × `520px` floating dashboard showing cluster telemetry, recent projects, installed cluster tools, and an active CLI execution HUD.
2. **Recent Project & Server Direct Openers**: Clicking any project or server in the popover immediately focuses the main desktop window and navigates straight to `/projects/{id}` or `/servers/{id}`.
3. **Custom Application Menu (`File`, `Edit`, `View`, `Cluster`, `Window`, `Help`)**: Replaces `Menu::default()` with a fully integrated menu with keyboard accelerators (`⌘N`, `⇧⌘N`, `⌘1`–`⌘4`), dynamic "Open Recent Project ▶" submenus, quick cluster operations, and direct links to documentation and the in-app uninstaller.
4. **Always-Visible Windows Menu Bar**: On Windows, the menu bar stays permanently visible right below the title bar, turning it into a prominent, powerful desktop feature.

---

## 2. Information Architecture

### 2.1 The Menu Bar Popover Control Center (`360px` × `520px`)
```
┌────────────────────────────────────────────────────────┐
│  [ Feather ]  LaraKube Control Center       [ ⚙ ] [ ✕ ]│
├────────────────────────────────────────────────────────┤
│  🟢 SERVERS                                            │
│  ● k3d-larakube (Local Cluster)            ● Healthy  │
│    CPU: 12%  RAM: 2.1 / 8 GB     [ ↗ View Server Details ]│
│  ☁ doks-production (Cloud Cluster)         ● Connected │
├────────────────────────────────────────────────────────┤
│  RECENT & ACTIVE PROJECTS (3)               [ Stop All ]│
│                                                        │
│  ● blog-api                                 [ ■ Stop ] │
│    ↳ Click row to open in App Details                  │
│    ↳ https://blog-api.dev.test   [ ↗ Browser ] [ ⌨ Code ]│
│                                                        │
│  ● billing-service                          [ ■ Stop ] │
│    ↳ https://billing.dev.test    [ ↗ Browser ] [ ⌨ Code ]│
│                                                        │
│  ○ docs-site (stopped)                      [ ▶ Start ]│
├────────────────────────────────────────────────────────┤
│  INSTALLED CLUSTER TOOLS (4)                           │
│  [ 🌐 Traefik ]  [ ✉ Stalwart ]  [ 📦 Forgejo ]         │
│  [ 🔐 OpenBao ]  [ 📁 Drive ]                          │
├────────────────────────────────────────────────────────┤
│  LIVE ACTIVITY (CLI)                                   │
│  ⟳ larakube up (blog-api)                              │
│  [████████████████░░░░] Applying manifests...          │
├────────────────────────────────────────────────────────┤
│  [ ↗ Open Full Desktop ]               [ ⏻ Quit App ]  │
└────────────────────────────────────────────────────────┘
```

### 2.2 The Custom Application Menu Structure

| Top-Level Menu | Items & Structure | Shortcut | Target / Action |
|---|---|---|---|
| **LaraKube** *(macOS only)* | `About LaraKube Desktop`<br>`Preferences / Settings…`<br>─────────────────────<br>`Hide LaraKube`<br>`Hide Others`<br>`Show All`<br>─────────────────────<br>`Quit LaraKube` | <br>`⌘,`<br><br>`⌘H`<br>`⌥⌘H`<br><br><br>`⌘Q` | Standard App Identity<br>Navigates to `/settings`<br><br>Native hide<br>Native hide others<br>Native unhide<br><br>Terminates app |
| **File** | `New Project…`<br>`New Server…`<br>─────────────────────<br>**`Open Recent Project ▶`**<br>&nbsp;&nbsp;↳ `1. blog-api`<br>&nbsp;&nbsp;↳ `2. billing-service`<br>&nbsp;&nbsp;↳ `3. docs-site`<br>&nbsp;&nbsp;↳ `─────────────────────`<br>&nbsp;&nbsp;↳ `View All Projects…`<br>**`Open Recent Server ▶`**<br>&nbsp;&nbsp;↳ `1. k3d-larakube (Local)`<br>&nbsp;&nbsp;↳ `2. doks-production (Cloud)`<br>&nbsp;&nbsp;↳ `─────────────────────`<br>&nbsp;&nbsp;↳ `View All Servers…`<br>─────────────────────<br>`Settings…`<br>`Close Window` | `⌘N`<br>`⇧⌘N`<br><br><br><br><br><br><br><br><br><br><br><br><br><br>`⌘,`<br>`⌘W` | Navigates to `/projects/create`<br>Navigates to `/servers/create`<br><br>Dynamic top-5 recent projects<br>Focuses window & navigates to `/projects/{id}`<br><br><br>Navigates to `/projects`<br>Dynamic top-5 servers<br>Focuses window & navigates to `/servers/{id}`<br><br><br>Navigates to `/servers`<br><br>Navigates to `/settings`<br>Hides window |
| **Edit** | Standard Undo, Redo, Cut, Copy, Paste, Select All | `⌘Z`, `⌘C`, `⌘V`, `⌘A` | Standard system text clipboard |
| **View** | `Servers Dashboard`<br>`Projects Fleet`<br>`Cluster Tools`<br>`Activity & Terminal Runs`<br>─────────────────────<br>`Reload`<br>`Force Reload`<br>─────────────────────<br>`Actual Size`<br>`Zoom In`<br>`Zoom Out`<br>─────────────────────<br>`Toggle Full Screen`<br>`Toggle Developer Tools` | `⌘1`<br>`⌘2`<br>`⌘3`<br>`⌘4`<br><br>`⌘R`<br>`⇧⌘R`<br><br>`⌘0`<br>`⌘=`<br>`⌘-`<br><br>`⌃⌘F` / `F11`<br>`⌥⌘I` | Navigates to `/servers`<br>Navigates to `/projects`<br>Navigates to `/tools`<br>Navigates to `/runs`<br><br>Browser reload<br>Cache-bust reload<br><br>100% zoom<br>Enlarge zoom<br>Shrink zoom<br><br>Full screen<br>Electron DevTools |
| **Cluster** *(New!)* | `Start All Workloads`<br>`Stop All Workloads`<br>─────────────────────<br>`Sync Fleet Telemetry` | <br><br><br>`⇧⌘R` | Dispatches `startAll`<br>Dispatches `stopAll`<br><br>Dispatches background sync jobs |
| **Window** | Standard Minimize, Zoom, Bring All to Front | `⌘M` | Native window manager |
| **Help** | `LaraKube Documentation`<br>`Report an Issue…`<br>─────────────────────<br>`Check for Updates…`<br>`Uninstall LaraKube Desktop…` | | Opens `https://larakube.app/docs`<br>Opens GitHub Issues<br><br>Triggers updater check<br>Navigates to `/settings?action=uninstall` |

---

## 3. Architecture & Technical Design

### 3.1 Menu Bar Configuration in `NativeAppServiceProvider`
```php
MenuBar::create()
    ->icon(public_path('IconTemplate.png'))
    ->tooltip(config('app.name', 'LaraKube'))
    ->showDockIcon(true)
    ->onlyShowContextMenu(false) // Enables popover window mode
    ->width(360)
    ->height(520)
    ->resizable(false)
    ->url(url('/tray'))
    ->withContextMenu(
        Menu::make(
            Menu::link(url('/'), 'Open LaraKube Desktop'),
            Menu::separator(),
            Menu::link(url('/settings'), 'Settings…'),
            Menu::separator(),
            Menu::quit('Quit LaraKube'),
        )
    );
```

### 3.2 Dynamic Application Menu Service: `App\Services\LaraKube\AppMenu`
Create `desktop/app/Services/LaraKube/AppMenu.php`:
```php
namespace App\Services\LaraKube;

use App\Models\Project;
use App\Models\Server;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Menu\Menu as NativeMenu;

class AppMenu
{
    public static function register(): void
    {
        $recentProjects = Project::latest('updated_at')->take(5)->get();
        $recentServers = Server::latest('updated_at')->take(5)->get();

        $projectItems = [];
        foreach ($recentProjects as $project) {
            $projectItems[] = Menu::link(url('/projects/' . $project->id), $project->name);
        }
        if (count($projectItems) === 0) {
            $projectItems[] = Menu::label('No recent projects');
        }
        $projectItems[] = Menu::separator();
        $projectItems[] = Menu::link(url('/projects'), 'View All Projects…');

        $serverItems = [];
        foreach ($recentServers as $server) {
            $serverItems[] = Menu::link(url('/servers/' . $server->id), $server->name . ' (' . ($server->is_local ? 'Local' : 'Cloud') . ')');
        }
        if (count($serverItems) === 0) {
            $serverItems[] = Menu::label('No servers registered');
        }
        $serverItems[] = Menu::separator();
        $serverItems[] = Menu::link(url('/servers'), 'View All Servers…');

        Menu::new()
            ->appMenu()
            ->fileMenu(
                Menu::link(url('/projects/create'), 'New Project…')->accelerator('CmdOrCtrl+N'),
                Menu::link(url('/servers/create'), 'New Server…')->accelerator('CmdOrCtrl+Shift+N'),
                Menu::separator(),
                Menu::submenu('Open Recent Project', NativeMenu::make(...$projectItems)),
                Menu::submenu('Open Recent Server', NativeMenu::make(...$serverItems)),
                Menu::separator(),
                Menu::link(url('/settings'), 'Settings…')->accelerator('CmdOrCtrl+,'),
                Menu::close()->accelerator('CmdOrCtrl+W'),
            )
            ->editMenu()
            ->submenu('View', NativeMenu::make(
                Menu::link(url('/servers'), 'Servers Dashboard')->accelerator('CmdOrCtrl+1'),
                Menu::link(url('/projects'), 'Projects Fleet')->accelerator('CmdOrCtrl+2'),
                Menu::link(url('/tools'), 'Cluster Tools')->accelerator('CmdOrCtrl+3'),
                Menu::link(url('/runs'), 'Activity & Terminal Runs')->accelerator('CmdOrCtrl+4'),
                Menu::separator(),
                Menu::reload()->accelerator('CmdOrCtrl+R'),
                Menu::forceReload()->accelerator('CmdOrCtrl+Shift+R'),
                Menu::separator(),
                Menu::actualSize()->accelerator('CmdOrCtrl+0'),
                Menu::zoomIn()->accelerator('CmdOrCtrl+='),
                Menu::zoomOut()->accelerator('CmdOrCtrl+-'),
                Menu::separator(),
                Menu::fullscreen()->accelerator(PHP_OS_FAMILY === 'Darwin' ? 'Ctrl+Cmd+F' : 'F11'),
                Menu::toggleDevTools()->accelerator('CmdOrCtrl+Shift+I'),
            ))
            ->submenu('Cluster', NativeMenu::make(
                Menu::link(url('/actions/start-all'), 'Start All Workloads'),
                Menu::link(url('/actions/stop-all'), 'Stop All Workloads'),
                Menu::separator(),
                Menu::link(url('/actions/sync-telemetry'), 'Sync Fleet Telemetry')->accelerator('CmdOrCtrl+Shift+R'),
            ))
            ->windowMenu()
            ->helpMenu(
                Menu::link('https://larakube.app/docs', 'LaraKube Documentation')->openInBrowser(),
                Menu::link('https://github.com/luchavez-technologies/larakube-desktop/issues', 'Report an Issue…')->openInBrowser(),
                Menu::separator(),
                Menu::link(url('/settings?action=check-updates'), 'Check for Updates…'),
                Menu::link(url('/settings?action=uninstall'), 'Uninstall LaraKube Desktop…'),
            )
            ->register();
    }
}
```

### 3.3 Dynamic Menu Auto-Refresh
To guarantee the recent projects and servers in the menu never go stale:
- Call `AppMenu::register()` in `NativeAppServiceProvider::boot()`.
- Listen for `Project` and `Server` eloquent events (`saved`, `deleted`), refreshing the menu dynamically via `AppMenu::register()`.

### 3.4 Deep Window Navigation Controller: `TrayController`
Create `desktop/app/Http/Controllers/TrayController.php`:
- `index()`: Serves Inertia page `tray/index`.
- `data()`: JSON endpoint returning servers, recent projects, installed tools, and running operations.
- `openProject(Project $project)`: Focuses `main` window and navigates to `/projects/{id}`.
- `openServer(Server $server)`: Focuses `main` window and navigates to `/servers/{id}`.
- `openEditor(Project $project)`: Invokes [`EditorLauncher`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Services/EditorLauncher.php) to open VS Code / PhpStorm.
- `toggleProject(Project $project)`: Runs `larakube down` or `larakube up`.
- `stopAll()`: Dispatches stop-all across local workloads.

### 3.5 Frontend Popover: `desktop/resources/js/pages/tray/index.tsx`
- Compact `360px` container with dark-mode aesthetic.
- Auto-polling every 4 seconds to `/tray/data`.
- Click on any project row to open inside LaraKube Desktop.
- Click on any server row to open inside LaraKube Desktop.
- Direct external link buttons `[ ↗ ]` for `.dev.test` domains.
- Direct IDE buttons `[ ⌨ ]` to launch VS Code / PhpStorm.
- Fleet action button: "Stop All" (`PowerOff`).

---

## 4. Proposed File Changes

### Backend Components
1. `desktop/app/Services/LaraKube/AppMenu.php` [NEW]
   - Builds and registers the comprehensive Application Menu with accelerators and dynamic recent project/server submenus.
2. `desktop/app/Providers/NativeAppServiceProvider.php`
   - Replaces `Menu::default()` with `AppMenu::register()`.
   - Configures `MenuBar::create()` with `onlyShowContextMenu(false)`, `width(360)`, `height(520)`, `url(url('/tray'))`.
   - Leaves menu bar always visible on Windows (no `autoHideMenuBar`).
3. `desktop/app/Http/Controllers/TrayController.php` [NEW]
   - Implements `/tray` views, data API, and deep project/server openers.
4. `desktop/routes/web.php`
   - Registers `/tray` and `/actions` routes.
5. `desktop/app/Listeners/ShutdownWslOnWindowClose.php`
   - Keeps LaraKube running in the menu bar when the main window is closed.

### Frontend Components
1. `desktop/resources/js/pages/tray/index.tsx` [NEW]
   - Hyper-compact popover interface for projects, servers, tools, and run HUD.
2. `desktop/resources/js/layouts/tray-layout.tsx` [NEW]
   - Frameless popover layout wrapper.

---

## 5. Verification Plan

### Automated Tests
1. `tests/Feature/AppMenuTest.php` [NEW]:
   - Test `AppMenu::register()` compiles without exceptions.
   - Test dynamic top-5 project and server items are correctly formatted with links.
   - Test fallback when no projects/servers exist.
2. `tests/Feature/TrayControllerTest.php` [NEW]:
   - Test GET `/tray` returns Inertia page.
   - Test GET `/tray/data` returns valid telemetry JSON.
   - Test POST `/tray/open-project/{id}` triggers `Window::open('main')->url(...)`.
   - Test POST `/tray/open-server/{id}` triggers `Window::open('main')->url(...)`.
3. Static Analysis & Linting:
   - `composer lint:check` (Pint)
   - `composer types:check` (PHPStan level 8)
   - `npm run types:check` (TypeScript)
   - `./vendor/bin/pest tests/Feature/AppMenuTest.php tests/Feature/TrayControllerTest.php`

### Manual Verification
1. **Application Menu**:
   - Verify `File`, `Edit`, `View`, `Cluster`, `Window`, `Help` appear on macOS top bar and Windows window toolbar.
   - Press `⌘N` (or `Ctrl+N`): Verify it navigates to New Project.
   - Press `⌘1`–`⌘4`: Verify instant navigation across Servers, Projects, Tools, and Runs.
   - Open "File → Open Recent Project": Click a project and verify it opens `/projects/{id}`.
2. **Menu Bar Popover**:
   - Click the menu bar feather icon: Verify the popover window opens anchored to the icon.
   - Click a project card: Verify main window opens and loads the project.
   - Click a server card: Verify main window opens and loads the server.
   - Click vanity domain link: Verify browser opens `https://<app>.dev.test`.
   - Close the main window: Verify LaraKube stays alive in the menu bar tray.
