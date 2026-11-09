# LaraKube Desktop MCP Server

## Progress log (2026-11-09)

**Phase 1 shipped** (server class, 11 tool classes, routes registration, 11 Pest tests). Full repo-wide `composer analyse` and `composer test` pass (890/890), `pint --test` clean. Two corrections made against this plan's original text along the way:

1. **`laravel/mcp` version**: pinned to `^1.0` (resolved `v1.1.2`), not `^0.9.4` as this plan originally said (copying the CLI's own pin). `laravel/mcp` reached a stable 1.0 well after the CLI's pin was set — checked `composer show laravel/mcp --all` before committing to a version, per this repo's "never assume a stale pin is current" rule. Verified the `Tool`/`Server`/`Response`/`Request`/`Attributes` API used throughout Section 1-4 is unchanged between 0.9.7 and 1.1.2, so no code changes were needed from the original design. The CLI's own `^0.9.4` pin is now known-stale too, but updating it is out of scope here — flagging it as a separate, small follow-up.
2. **Registration file**: `laravel/mcp`'s own `McpServiceProvider::registerRoutes()` auto-loads `routes/ai.php` (`Route::group([], base_path('routes/ai.php'))`) if that file exists — confirmed by reading the installed package source. So the file is named `desktop/routes/ai.php`, not `routes/mcp.php`, and **no `bootstrap/app.php` wiring was needed at all** — this plan's Section 1.3 manual-wiring concern (Laravel Zero's loader not existing in a plain Laravel app) turned out moot once the real package behavior was checked.

Also discovered while writing tests: `CliRunner::start()` dispatches through NativePHP's `Native\Desktop\Facades\ChildProcess` facade (faked with `ChildProcess::fake()` + firing `Native\Desktop\Events\ChildProcess\{ErrorReceived,ProcessExited}` events, which the real `RecordRunOutput` listener picks up synchronously), not Laravel's `Process` facade — Section 7's original test guidance assumed `Process::fake()`/`Process::start()`, which does not apply to this code path. `StackCatalog`/`ToolCatalog` read from `Cache`/the database directly (seeded via `Cache::put(StackCatalog::CACHE_KEY, ...)` in tests), not a CLI round-trip, for a cache hit.

An attempted delegation to Antigravity for the bulk scaffolding (server + 11 tools + 11 tests) was blocked by this session's own permission classifier before any file was touched ("Create Unsafe Agents"), so Phase 1 was implemented directly instead — no loss, since the plan was already fully specified.

**Section 5's open question is resolved — simpler than this plan assumed.** `native:run`/`native:dev` turned out to be enough to verify this without a full `native:build`: NativePHP's own docs confirm dev builds still copy the real app code and use the same bundled static PHP binary, not the host's system PHP ("a major part of the build process, even for debug builds, involves copying your application code into the runtime's build environment"). Confirmed live: `vendor/nativephp/desktop/resources/build/php/php artisan list native` (the exact binary + app copy a running `native:dev` uses) booted this app correctly, and piping a raw `initialize` JSON-RPC request into `... artisan mcp:start desktop` over stdio returned a correct MCP handshake identifying "LaraKube Desktop Server" — full proof the server works for real, not just under Pest's in-process test harness.

The deeper insight this gave: there's no need to resolve a path at all. **`PHP_BINARY`** (a native PHP constant — the exact binary currently executing the calling code) is always correct, in dev or in a packaged build, on any platform, because the running Desktop app computes it about *itself* at the moment `bridge()` runs — confirmed `PHP_BINARY` from inside the bundled binary self-reports its own real path. So `GlobalSettings::bridge()`'s `larakube-desktop` entry is `['command' => PHP_BINARY, 'args' => [base_path('artisan'), 'mcp:start', 'desktop']]` — no `ToolLocator::ownBinary()` or any new resolution logic needed, unlike what Section 5.3 anticipated.

**Shipped**: `GlobalSettings::bridge()` extended with the `larakube-desktop` entry; `isAgentBridged()` now requires both `larakube-cli` and `larakube-desktop` present; Settings page copy (`resources/js/pages/settings/index.tsx`) updated to describe both servers; `SettingsTest.php`'s bridge test extended to assert the new entry's exact `command`/`args`. Full suite still 890/890, `pint`/`phpstan`/`tsc --noEmit` all clean.

**Not yet done**: Section 6 (removing the stale `larakube-console` entry from the CLI's `McpRegisterCommand.php`) remains a separate follow-up, as originally planned.

## Context

LaraKube's product lineup is now CLI + Desktop + Cloud (Console was retired). The CLI already ships a real, working MCP server (`cli/app/Mcp/Servers/LaraKubeCliServer.php`, using `laravel/mcp ^0.9.4`, stdio transport, registered today in `cli/app/Commands/McpRegisterCommand.php`), but it only sees the local filesystem and whatever verbs `OrchestrateVerbTool.php` whitelists.

The user has decided that **Desktop should be the primary place new MCP capability gets built going forward** (Cloud later; the CLI's server stays only as a headless/power-user fallback) — because Desktop already has full visibility into every registered Project, every Server/stack, every installed Cluster Tool, and the actual run history (`App\Models\Run` / `RunStatus`), all driven today through `desktop/app/Services/LaraKube/CliRunner.php` shelling out to the same CLI as a subprocess. This plan designs that first Desktop MCP server: what it exposes, how it's built, and how it's registered — explicit enough to hand to a different agent (Antigravity) with no prior context.

This plan does **not** cover: any HTTP/SSE transport for Desktop (blocked — see below), the OAuth DO/Cloudflare sign-in work (tracked separately in `cli/plans/active/oauth-provider-connect.md`), or wrapping all 100+ existing controller actions (an explicit MVP slice is defined, the rest is deferred with reasons).

## Architectural decision: stdio only, never HTTP

NativePHP wraps every route in Desktop's live `routes/web.php` with a global `PreventRegularBrowserAccess` middleware, keyed by a pre-shared secret regenerated on every app launch (NativePHP's own docs, `desktop/2/digging-deeper/security`: "You MUST NOT bypass this security measure!"). There is no safe way to expose an MCP tool as an HTTP route on that running process. So Desktop's MCP server must be a **separate PHP process spawned over stdio** by the MCP client (Claude Desktop/Claude Code/Antigravity), exactly like the CLI's server today — never a route bound into the GUI's web server. Real multi-user/remote MCP access needs per-user OAuth and is Cloud's job later (same reasoning already applied to oCIS MCP in `cli/plans/active/drive-mcp-server.md`).

## 1. File layout and dependencies

Add to `desktop/composer.json`'s `require`: `"laravel/mcp": "^0.9.4"` (pin the same minor line the CLI uses), then `composer require laravel/mcp:^0.9.4` inside `desktop/`. Verify its service provider auto-registers into `desktop/bootstrap/providers.php` (Desktop uses Laravel 11+'s `bootstrap/app.php` + `bootstrap/providers.php` style, no `app/Console/Kernel.php`).

New files, mirroring the CLI's exact two-directory shape and class/attribute pattern (`cli/app/Mcp/Servers/LaraKubeCliServer.php`, `cli/app/Mcp/Tools/OrchestrateVerbTool.php`, `PatchBlueprintTool.php`):

```
desktop/app/Mcp/Servers/LaraKubeDesktopServer.php
desktop/app/Mcp/Tools/ListProjectsTool.php
desktop/app/Mcp/Tools/ShowProjectTool.php
desktop/app/Mcp/Tools/ListServersTool.php
desktop/app/Mcp/Tools/ShowServerTool.php
desktop/app/Mcp/Tools/ListClusterToolsTool.php
desktop/app/Mcp/Tools/ProjectUpTool.php
desktop/app/Mcp/Tools/ProjectDownTool.php
desktop/app/Mcp/Tools/ProjectHealTool.php
desktop/app/Mcp/Tools/InstallClusterToolTool.php
desktop/app/Mcp/Tools/RemoveClusterToolTool.php
desktop/app/Mcp/Tools/GetRunStatusTool.php
```

- The server class extends `Laravel\Mcp\Server`, decorated `#[Name('LaraKube Desktop Server')]`, `#[Version('0.1.0')]`, `#[Instructions(...)]`, and lists tool classes in `protected array $tools`.
- Each tool extends `Laravel\Mcp\Server\Tool`, decorated `#[Name('kebab-tool-name')]`/`#[Title(...)]`/`#[Description(...)]`, implements `handle(Request $request): Response` (returns `Response::text(...)`/`Response::error(...)`) and `schema(JsonSchema $schema): array`, exactly as in `PatchBlueprintTool::schema()`.

Create `desktop/routes/mcp.php`:

```php
<?php

use App\Mcp\Servers\LaraKubeDesktopServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('desktop', LaraKubeDesktopServer::class);
```

The CLI loads its equivalent (`cli/bootstrap/ai.php`) via a Laravel Zero-specific component Desktop doesn't have. In `desktop/bootstrap/app.php`, wire `routes/mcp.php` in explicitly — add a `then:` callback to the `Application::configure(...)` chain requiring it, the same way Desktop already loads `routes/console.php` via `->withRouting(commands: ...)`. Check `laravel/mcp`'s own service provider once installed for a cleaner config-driven route-file hook before reaching for a bespoke `require`. Once wired, `php artisan mcp:start desktop` runs it over stdio — this artisan command ships with the package automatically; no new command class is needed.

## 2. MVP tool list

### Phase 1 — reads (no confirmation needed)

| Tool | Wraps | Notes |
|---|---|---|
| `list-projects` | `App\Models\Project::query()->get()` | id, path, name, group, framework, environment, web_host — same shape `ProjectController::index()` builds. |
| `show-project` | `App\Services\LaraKube\ProjectInspector::inspect(path, environment)` | Resolve project id/path to `Project`, then inspect — same as `ProjectController::show()`. |
| `list-servers` | `App\Services\LaraKube\StackCatalog::all()` | Every known server/stack with status/context/provider. |
| `show-server` | `StackCatalog::find(name)` | Single-server detail. |
| `list-cluster-tools` | `App\Services\LaraKube\ToolCatalog::forContext(context)` | Installed tools for a server's kube context. |
| `get-run-status` | `App\Models\Run::find($id)` | id, label, kind, status, output, exitCode, startedAt, finishedAt — same shape `RunController::stream()` exposes. This is the handle-based escape hatch (Section 3). |

### Phase 1 — writes (smallest safe slice)

| Tool | Wraps | Confirmation |
|---|---|---|
| `project-up` | `ProjectController::up()` (`desktop/app/Http/Controllers/ProjectController.php:994`) → `['up', $environment, '--no-console', '--no-test']` via `CliRunner::start(..., kind: RunKind::UpProject)` | No — reversible/low-risk. |
| `project-down` | `ProjectController::down()` (`:1009`) → `['down', $environment, '--force']`, `--full` when purge requested | **Yes, only when `purge: true`** — mirrors the UI's own purge-only confirmation gate. |
| `project-heal` | `ProjectController::heal()` (`:1002`) → `['heal', '--force', '--prune']` | No — reconciliation, not destructive; UI has no confirmation either. |
| `install-cluster-tool` | `ClusterToolController::store()` (`desktop/app/Http/Controllers/ClusterToolController.php:199`) → `tool:add` with `--tool=`, `--context=`, `--domain=`, wiring flags, `--force` | No — additive, same risk class as `project-up`. |
| `remove-cluster-tool` | `ClusterToolController::destroy()` (`:269`) → `{tool}:remove`, optional `--domain=`, optional `--purge` | **Yes, only when `purge: true`**. |

Confirmed against the real code: `up()`/`heal()`/`down()` live at `ProjectController.php:994/1002/1009` and all route through the controller's shared `run($runner, $project, $kind, $label, $args, $environment)` helper, which itself calls `CliRunner::start(...)` and returns a `Run`.

### Deferred, with reasons

- **`destroy-project` / `destroy-server`** — irreversible; needs the strongest confirmation pattern. Phase 2, so Phase 1 ships and proves itself first.
- **Backups** (setup/schedule/run/restore/prune) — restore/prune are destructive in ways needing their own careful design. Phase 2.
- **Mail / SSO / OpenBao / Plex Commons** — cross-cutting wire/unwire, lower immediate LLM-workflow value than "is it running / bring it up-down / install-remove a tool." Phase 3.
- **DevBoxController** (SSH remote dev boxes) — separate `CliRunner::start(devBox:...)` argument shape; Phase 3 once the local/VPS path is proven.
- **`QuickActionController::launch()`** — already MCP-shaped (resolves a tool's declared `initFields` into CLI flags dynamically) and a good template for a future generic `launch-tool` fallback, but currently does real one-click deploys with zero confirmation of its own — don't inherit that silently by wrapping it directly. Revisit in Phase 2 under the Section 4 safety model.
- **Cloud accounts/OAuth** — overlaps `oauth-provider-connect.md`; not duplicated here.
- **Companions, Workspaces, Tunnels** — no blocker, just not in the MVP cut.

## 3. Run polling strategy

Default: **synchronous poll-and-block**, since an MCP tool call is expected to return one blocking result, not a fire-and-forget notification.

1. Call `CliRunner::start(...)` (same as the controller) → get a `Run`.
2. Poll `Run::query()->find($run->id)->status` via `Illuminate\Support\Sleep::sleep(1)` (the `Sleep` facade — required so tests can fake it) until `RunStatus::isFinished()` or timeout.
3. Timeout: **120s** for `project-up`/`project-heal`/`install-cluster-tool`/`remove-cluster-tool`; **60s** for `project-down` (teardown is normally fast).
4. On timeout: `Response::error(...)` stating the run is **not** necessarily failed — still running — plus its id, e.g. `"Timed out after 120s waiting for 'Up {project} (local)' to finish. It may still be running — check with get-run-status, run id {id}."`
5. On finish: `Response::text($run->output)` (truncate to the last ~4000 chars if long — don't dump unbounded logs into a model's context) plus `exit_code` and final `status`.

`get-run-status` is the deliberate exception — it returns a handle rather than blocking, and is the escape hatch both for timeouts and for any future genuinely long verb (full cloud `up`, `scaffold`/`clone` — out of MVP scope). When such a verb is added later, give it the same pattern: return `"Started as run {$run->id}. Poll get-run-status with this id."` immediately, no polling.

## 4. Safety/confirmation model

Any tool wrapping a destructive action adds a **required** `confirm` field (no default, not nullable): `$schema->boolean()->description('Must be true to proceed. This action is destructive: <specific consequence>.')`. If `$request->get('confirm') !== true`, `handle()` returns `Response::error(...)` immediately — before calling `CliRunner::start()` — explaining the consequence and that `confirm: true` is required to proceed. The tool's `#[Description(...)]` attribute must also state the consequence plainly, so a calling model sees the risk before constructing arguments, not only after a rejected first attempt.

This is the schema-level equivalent (no human dialog exists for an LLM caller) of the UI's own gates: `ProjectController::down()`'s purge path requires retyping the project's exact name; dedicated `FormRequest` classes (`RemoveClusterToolRequest`, etc.) gate the HTTP routes. For MCP, a plain `confirm: true` is the right bar — a structured tool call can't "fat-finger" a checkbox, and asking an LLM to reproduce a string is friction without safety benefit. Keep "retype the name" as an Inertia-UI-only pattern.

Gated in Phase 1: `project-down` only when `purge: true`; `remove-cluster-tool` only when `purge: true`. In Phase 2: `destroy-project`, `destroy-server`, `restore-backup`, `prune-backups` — gated unconditionally, every call.

## 5. Registration/distribution

Desktop already has the mechanism to extend — don't build a new one. `desktop/app/Services/LaraKube/GlobalSettings.php::bridge(string $agent)` (line 441) already writes an MCP entry for the CLI server into Claude Desktop (`~/Library/Application Support/Claude/claude_desktop_config.json`, `~/.claude/config.json`), Antigravity (`~/.gemini/antigravity.json`), and OpenCode (`~/.config/opencode/mcp.json`):

```php
$mcpConfig = [
    'larakube-cli' => ['command' => $binary, 'args' => ['mcp:start', 'mcp']],
];
```

`desktop/app/Http/Controllers/SettingsController.php::bridge()` is already wired to `POST /settings/bridge/{agent}` with a Settings-page button calling it.

**What to add**: extend `$mcpConfig` with a second entry:

```php
$mcpConfig = [
    'larakube-cli' => ['command' => $binary, 'args' => ['mcp:start', 'mcp']],
    'larakube-desktop' => ['command' => /* see open question below */, 'args' => ['mcp:start', 'desktop']],
];
```

Update `isAgentBridged()` (line 540, currently `str_contains($content, 'larakube-cli')`) to also check for `larakube-desktop` — default to treating "bridged" as **both** present, so partial registration still shows as incomplete. No new controller/route/UI is required; only the array inside `bridge()` grows. Check `desktop/resources/js/pages/settings/index.tsx` for copy implying only the CLI server registers, and update it to mention both.

### Open question — verify before wiring this in

**What is `command` for `larakube-desktop`?** This is foundational: if it can't be answered, stdio-spawn is not achievable for a packaged GUI bundle and the transport choice needs revisiting. NativePHP ships a statically-compiled, directly-executable PHP binary inside the packaged app (confirmed via `desktop/2/digging-deeper/php-binaries`, `desktop/2/digging-deeper/security`, and `desktop/vendor/nativephp/desktop/src/Builder/Concerns/LocatesPhpBinary.php`) — its own docs call external invocation of that binary a *risk* to warn about, which doubles as proof it's invokable. But the exact runtime path inside a finished installed `.app`/`.exe` was not confirmed.

Verify, concretely, before finalizing:
1. Build Desktop (`php artisan native:build` — confirm exact command via `php artisan list native`) and inspect the bundle (macOS: `Contents/Resources`) to find where the bundled `php` binary and the app's own Laravel root (containing `artisan`) land at runtime.
2. Confirm `<bundled-php> <app-root>/artisan mcp:start desktop` run from a plain terminal boots the same service container as the live GUI (same SQLite DB, same config) — check `vendor/nativephp/desktop/src/Drivers/Electron/Traits/ExecuteCommand.php` for how NativePHP's own main process shells out to `php artisan serve` today, as the known-working reference, and mirror its env/working-directory setup, substituting `serve` for `mcp:start desktop`.
3. Check whether NativePHP ships a dedicated CLI entrypoint binary distinct from the raw PHP binary (`php artisan list native`, and `desktop/2/getting-started/commands`) — if so, prefer it over the raw PHP path.
4. Once confirmed, resolve the path the same way `ToolLocator::find('larakube')` (`desktop/app/Services/LaraKube/ToolLocator.php:~124`) resolves the CLI binary — likely a new method there (e.g. `ToolLocator::ownBinary()`), since this resolves the *currently-running app's own* path, not an external tool on PATH.

Do not ship this unverified — build once, run the candidate command by hand, confirm it answers a raw JSON-RPC `initialize` message on stdin/stdout, before wiring it into `bridge()`.

## 6. Follow-up (separate PR): remove the stale `larakube-console` entry

`cli/app/Commands/McpRegisterCommand.php::registerWithClaude()` currently also writes:

```php
'larakube-console' => ['url' => 'https://console.'.GlobalConfigData::load()->getLocalTld().'/mcp'],
```

This references the retired "Console" product (lineup is now CLI+Desktop+Cloud) via a dead HTTP transport. **Not part of this plan's changes** — track as a one-line removal in its own follow-up commit once Desktop's own server exists, since Desktop's `GlobalSettings::bridge('claude')` is now the correct independent registration path and the CLI's command doesn't need to know about Desktop's server at all.

## 7. Verification

**Automated (Pest, one file per tool, per existing convention)**:

```
desktop/tests/Feature/Mcp/ListProjectsToolTest.php
desktop/tests/Feature/Mcp/ShowProjectToolTest.php
desktop/tests/Feature/Mcp/ListServersToolTest.php
desktop/tests/Feature/Mcp/ShowServerToolTest.php
desktop/tests/Feature/Mcp/ListClusterToolsToolTest.php
desktop/tests/Feature/Mcp/ProjectUpToolTest.php
desktop/tests/Feature/Mcp/ProjectDownToolTest.php
desktop/tests/Feature/Mcp/ProjectHealToolTest.php
desktop/tests/Feature/Mcp/InstallClusterToolToolTest.php
desktop/tests/Feature/Mcp/RemoveClusterToolToolTest.php
desktop/tests/Feature/Mcp/GetRunStatusToolTest.php
```

`laravel/mcp` ships testing helpers (`Laravel\Mcp\Server::__callStatic` → `PendingTestResponse`), so tools are testable without a real stdio process:

```php
it('starts a project up and blocks until the run finishes', function () {
    Process::fake([...]); // never hit a real CLI, per cli/CLAUDE.md
    Sleep::fake();        // so the poll loop's Sleep::sleep(1) doesn't actually wait

    $project = Project::factory()->create();

    LaraKubeDesktopServer::tool(ProjectUpTool::class, ['project_id' => $project->id])
        ->assertOk()
        ->assertSee('succeeded');
});
```

Every test reaching `CliRunner::start()` must fake `Process::start()` specifically (not just `Process::run()` — `ToolLocator::start()` uses the async pattern), and simulate run completion directly via `Run::update(['status' => RunStatus::Succeeded, ...])` rather than waiting on NativePHP's real `ChildProcess` event round-trip. Add an explicit confirmation test: `project-down` with `purge: true` and no `confirm` must return `Response::error(...)` and must never call `CliRunner::start()` (assert via `Process::assertDidntRun()` or whatever the installed Laravel version exposes).

**Manual end-to-end**:
1. `php artisan native:run` inside `desktop/` with a real `Project`/`Server` in the local DB.
2. Resolve the Section 5 open question first — nothing past this step is testable without it.
3. Add a `larakube-desktop` entry to a real stdio MCP client config (Claude Desktop/Code, or Antigravity), restart it, confirm the new tools appear with the right names/descriptions.
4. Call `list-projects`/`list-servers` through the real client; cross-check against what the Desktop UI shows for the same rows.
5. Call `project-up` through the real client; confirm a new `Run` appears on the Runs page (`GET /runs`) and reaches `Succeeded`/`Failed` the same way the UI's own "Up" button does.
6. Call `project-down` with `purge: true` and no `confirm` — confirm rejection with no new `Run` row created; retry with `confirm: true` — confirm it proceeds.
7. Temporarily lower the timeout constant, trigger the timeout path on purpose, confirm the error message is useful (states the run may still be running, gives the id) and that `get-run-status` eventually shows `Succeeded`/`Failed` once the real CLI call finishes.
8. Run `composer analyse` and `composer test` inside `desktop/` (confirm exact script names in `desktop/composer.json` match `cli/CLAUDE.md`'s documented ones before assuming parity) to confirm nothing in `app/Mcp/**` breaks static analysis or the existing suite.

## Critical files

- `desktop/app/Services/LaraKube/GlobalSettings.php` — extend `bridge()`'s `$mcpConfig` and `isAgentBridged()`.
- `desktop/app/Services/LaraKube/CliRunner.php` — the `start()` method every write tool calls, and the `Run` every tool polls.
- `desktop/app/Http/Controllers/ProjectController.php` — reference implementations for `up()`/`down()`/`heal()` (lines 994/1002/1009).
- `desktop/app/Http/Controllers/ClusterToolController.php` — reference implementations for `store()`/`destroy()` (lines 199/269).
- `cli/app/Mcp/Tools/PatchBlueprintTool.php`, `cli/app/Mcp/Tools/OrchestrateVerbTool.php` — exact `laravel/mcp` Tool syntax to replicate.
