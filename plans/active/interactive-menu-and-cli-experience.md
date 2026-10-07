# Architectural Plan: LaraKube CLI Interactive Menu & Modern Terminal Experience

## Goal Description
As command-line tools have evolved (e.g., Antigravity CLI, Claude CLI, OpenCode, GitHub CLI, Stripe CLI), modern developers expect rich, interactive terminal user interfaces (TUIs) rather than memorizing dozens of obscure subcommands from a static 400-line help wall.

LaraKube CLI currently contains over 100 commands across core lifecycle, cloud provisioning, networking, and companion services. When invoked without arguments, it outputs a massive, overwhelming command list via `NunoMaduro\LaravelConsoleSummary\SummaryCommand`.

This plan transforms LaraKube CLI into a modern, context-aware interactive terminal experience:
1. **Interactive Menu & TUI Dashboard**: Launching a guided, searchable dashboard on bare `larakube` (or `larakube menu`) built entirely with Laravel Prompts.
2. **Context-Aware Workflows**: Dynamically adapting actions based on whether the shell is inside an active Laravel project (`.larakube.json`) or in a global workspace.
3. **OS Desktop Notifications**: Native macOS, Linux, and Windows desktop notifications when long-running operations (`cloud:create`, `devbox:create`, `cloud:destroy`, `up`) complete.
4. **Native Browser Integration (`larakube browse`)**: Seamless zero-dependency URL opening in the user's default browser for local apps, companion services, and Cloudflare tunnels.

---

## User Review Required

> [!IMPORTANT]
> **Non-Interactive & CI Compatibility**:
> Running `larakube` in non-interactive environments (CI/CD pipelines, cron, pipes, or with `--no-interaction` / `-n`) will automatically bypass the interactive menu and preserve the standard stdout summary or fail-fast behavior. `larakube --help` and `larakube list` continue to output the raw command list.

> [!NOTE]
> **Zero New Binary Dependencies**:
> By using Laravel Prompts (`select()`, `search()`, `suggest()`, `confirm()`, `table()`, `spin()`) and Laravel Zero's native notification system, this architecture maintains full Windows/macOS/Linux cross-platform support without requiring `ext-posix` or heavy headless browser binaries.

---

## Architecture Overview

```mermaid
flowchart TD
    subgraph Invocation ["CLI Entrypoint (larakube)"]
        EXEC["$ larakube [args]"]
        CHECK{"Arguments passed\nor Non-Interactive?"}
        RAW_CMD["Execute Command / Summary\n(CI, Scripts, --help)"]
        MENU_CMD["Interactive Menu Command\n(DefaultCommand / MenuCommand)"]
    end

    subgraph Detection ["Context Detection"]
        INSPECT{"Inside Project?\n(.larakube.json present)"}
        PROJ_CONTEXT["Project Context\n- Project Name & Domain\n- Local Cluster / Pod Health\n- Stack & Cloud Bindings"]
        GLOBAL_CONTEXT["Global Context\n- Local K3s Health\n- Cloud Accounts Logged In\n- Active Fleet Servers"]
    end

    subgraph Actions ["Interactive Menu Actions (Laravel Prompts)"]
        PROJ_ACTIONS["Project Actions:\n🚀 up / down / heal\n📜 logs / shell / artisan\n🌐 browse / share\n⚙️ project settings"]
        GLOBAL_ACTIONS["Global Actions:\n✨ new (Scaffold project)\n☁️ cloud:create / accounts\n🔧 setup / doctor\n🧰 companion tools"]
    end

    subgraph Enhancements ["Modern CLI Integrations"]
        NOTIF["Native Desktop Notifications\n(cloud:create, devbox, destroy, up)"]
        BROWSER["Native Browser Opener\n(browse verb & URLs)"]
    end

    EXEC --> CHECK
    CHECK -->|Yes| RAW_CMD
    CHECK -->|No (Bare TTY)| MENU_CMD

    MENU_CMD --> INSPECT
    INSPECT -->|Yes| PROJ_CONTEXT
    INSPECT -->|No| GLOBAL_CONTEXT

    PROJ_CONTEXT --> PROJ_ACTIONS
    GLOBAL_CONTEXT --> GLOBAL_ACTIONS

    PROJ_ACTIONS -.-> NOTIF
    PROJ_ACTIONS -.-> BROWSER
    GLOBAL_ACTIONS -.-> NOTIF
    GLOBAL_ACTIONS -.-> BROWSER
```

---

## Proposed Changes

### 1. Interactive Menu & Dashboard Core

#### [MODIFY] [commands.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/config/commands.php)
- Replace `'default' => SummaryCommand::class` with a custom default router:
  `'default' => App\Commands\MenuCommand::class`
- When invoked interactively with no command arguments, `MenuCommand` executes.
- When invoked with `--help`, `-h`, `list`, or non-interactively, it delegates cleanly to `SummaryCommand`.

#### [NEW] [MenuCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/MenuCommand.php)
- Signature: `menu {--no-interaction : Run non-interactively}` (aliased as default command and `menu`).
- Description: `Launch the interactive LaraKube dashboard and command navigator`.
- Implements the main interactive loop:
  1. **Header Banner**: Renders LaraKube ASCII banner with version, environment, and context status.
  2. **Context Inspector**:
     - Uses `ProjectInspector` / local filesystem check for `.larakube.json`.
     - Checks local cluster status via `K3sDetector` / `DockerDetector`.
     - Checks active cloud accounts via `GlobalConfigData` and `~/.aws/credentials`.
  3. **Categorized Action Palette**:
     - Uses `Laravel\Prompts\select()` with rich labels and emoji hints.
     - Includes a `"🔍 Search all commands..."` option that uses `Laravel\Prompts\suggest()` to instantly filter and run any of the 100+ commands.
     - Option to exit cleanly with `q` or `Ctrl+C`.

#### [NEW] [MenuActionCatalog.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Services/Menu/MenuActionCatalog.php)
- Central catalog defining the categories, commands, descriptions, and required context for every menu entry:
  - **Category: Lifecycle (`up`, `down`, `heal`, `purge`, `status`)**
  - **Category: Development & Runtime (`shell`, `artisan`, `proxy`, `logs`, `watch`, `browse`)**
  - **Category: Cloud & Servers (`cloud:create`, `cloud:accounts`, `cloud:scale`, `cloud:destroy`, `devbox:create`)**
  - **Category: Domains & Networking (`share`, `dns:list`, `tls:show`, `tunnel`)**
  - **Category: Companion Services (`mail`, `sso`, `chat`, `vault`, `kuma`, `monitor`)**
  - **Category: System & Setup (`setup`, `trust`, `doctor`, `config`)**

---

### 2. Native Desktop Notifications Integration

#### [MODIFY] [LaraKubeOutput.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/LaraKubeOutput.php)
- Add a helper method to dispatch desktop notifications when enabled:
  ```php
  protected function notifyIfEnabled(string $title, string $message, ?string $icon = null): void
  {
      if (! $this->getGlobalConfig()->isNotificationsEnabled()) {
          return;
      }

      try {
          $this->notify($title, $message, $icon);
      } catch (\Throwable) {
          // Gracefully ignore if desktop notification service is unavailable in current terminal
      }
  }
  ```

#### [MODIFY] [GlobalConfigData.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Data/GlobalConfigData.php)
- Add `public bool $notifications = true` to allow users to toggle desktop notifications on/off via `larakube config --notifications=false`.

#### [MODIFY] Lifecycle Commands to Trigger Notifications
- [CloudCreateCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Cloud/CloudCreateCommand.php): Send notification on server provisioning complete (`"LaraKube: Server Ready"`).
- [DevboxCreateCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Cloud/DevboxCreateCommand.php): Send notification on dev box ready (`"LaraKube: Dev Box Ready"`).
- [CloudDestroyCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Cloud/CloudDestroyCommand.php): Send notification on destruction complete.
- [UpCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/UpCommand.php): Send notification when cluster/app boot finishes.

---

### 3. Native Browser Opener (`larakube browse`)

#### [NEW] [BrowseCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/BrowseCommand.php)
- Signature: `browse {target? : Target service to open (app, mail, sso, traefik, kuma, share)}`
- Resolves the target URL based on project context or companion service:
  - Default / `app`: Opens the active app URL (e.g. `https://my-app.dev.test`).
  - `mail`: Opens Stalwart Webmail or Stalwart Admin.
  - `sso`: Opens Zitadel Admin console.
  - `traefik`: Opens Traefik Network Dashboard.
  - `share`: Opens active Cloudflare share tunnel URL.
- Cross-platform opener:
  - macOS: `open <url>`
  - Linux: `xdg-open <url>`
  - Windows: `start <url>`

---

## Verification Plan

### Automated Tests
1. **Menu Command Tests**:
   - `composer test -- --filter=MenuCommandTest`:
     - Test non-interactive invocation falls back to `SummaryCommand` without blocking.
     - Test menu navigation in project directory presents project lifecycle actions.
     - Test menu navigation outside project presents global workspace actions.
     - Test command search / palette resolution.
2. **Desktop Notification Tests**:
   - `composer test -- --filter=NotificationTest`:
     - Test `notifyIfEnabled` checks global config and handles headless runners gracefully without throwing.
3. **Browse Command Tests**:
   - `composer test -- --filter=BrowseCommandTest`:
     - Test resolving app URL from `.larakube.json`.
     - Test resolving companion service URLs.
     - Test cross-platform command construction.

### Manual Verification
1. Run `php cli/larakube`:
   - Verify beautiful interactive menu appears instead of 400-line wall of text.
   - Test navigating with arrow keys and selecting an action.
   - Test `/` or "Search all commands..." to jump directly to any verb.
2. Run `php cli/larakube browse`:
   - Verify it opens the local app in your default browser.
3. Run a long-running command (e.g. `cloud:create` or `up`):
   - Verify native macOS Notification appears when the command completes.
