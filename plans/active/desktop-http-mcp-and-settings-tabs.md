# HTTP MCP server for Desktop + tabbed Settings page

## Context

Desktop already has a working stdio MCP server (`app/Mcp/Servers/LaraKubeDesktopServer.php`, shipped earlier this session), registered per-client via `GlobalSettings::bridge()` writing each MCP client's own config file. The user saw OpenScreen's "AI settings" panel — a toggleable, persistent HTTP MCP server on a fixed port with a bearer token, one static copy-paste setup command per client — and asked for the same ease of use. Separately, Settings (`resources/js/pages/settings/index.tsx`, 1918 lines, ~17 stacked cards, no grouping) is getting crowded enough that the user wants it reorganized into tabs, the way Mail and SSO already are.

This plan covers both, since the new HTTP MCP controls would otherwise just be more stacked cards on an already-long page.

## Part A: persistent HTTP MCP server

### Why this can't just be `laravel/mcp`'s own `Mcp::web()`

Confirmed by reading the installed package and NativePHP source directly:
- `Mcp::web($route, $serverClass)` (`vendor/laravel/mcp/src/Server/Registrar.php:38-60`) just registers ordinary `Router::post/get/delete` routes with three protocol-framing middleware (`ReorderJsonAccept`, `ValidateMcpHeaders`, `AddWwwAuthenticateHeader`) — **no built-in bearer-token/static-auth mechanism**. Its only real auth story is full OAuth via `laravel/passport` (`Mcp::oauthRoutes()`), which doesn't fit a toggleable single-token Settings model.
- NativePHP's `PreventRegularBrowserAccess` (`vendor/nativephp/desktop/src/Http/Middleware/PreventRegularBrowserAccess.php`) is pushed onto the **shared `Illuminate\Foundation\Http\Kernel` singleton** in `NativeServiceProvider::boot()` (`$kernel->pushMiddleware(...)`), gated only on `config('nativephp-internal.running')` — i.e. once true, it applies to **every request through this one Laravel app instance's kernel**, regardless of which routes file registered the matched route. There is no route-group or route-file escape from it. A `Mcp::web()` route added to `routes/ai.php` or `routes/web.php` would still 403 without the NativePHP-internal secret.

So a second MCP transport must run **outside this app's existing HTTP kernel entirely** — a genuinely separate listener, exactly like OpenScreen's own architecture, not a second route file.

### Design: a hand-rolled persistent socket server, not a second `artisan serve`

Investigated whether a second `ChildProcess::artisan('serve', ..., env: [...])` without `NATIVEPHP_RUNNING`/`NATIVEPHP_SECRET` in its environment would dodge `PreventRegularBrowserAccess` (since `config('nativephp-internal.running')` reads `env('NATIVEPHP_RUNNING', false)` — confirmed in `vendor/nativephp/desktop/config/nativephp-internal.php`). This is unverified and risky: if Electron's main process sets `NATIVEPHP_RUNNING`/`NATIVEPHP_SECRET` as real OS-level environment variables before launching, a child process spawned via `Native\Desktop\Facades\ChildProcess` may inherit them regardless of what env array we pass, depending on exactly how NativePHP's `ChildProcess::start()` builds the child's environment (merge vs. replace — not confirmed). Rather than depend on that being replace-not-merge, build the listener so the question is moot by construction.

**Build on the existing one-shot pattern, extended to persistent/multi-request.** `cli/app/Services/Cloud/LoopbackHttpServer.php` already has the core primitive this needs: `stream_socket_server('tcp://127.0.0.1:0', ...)`, `stream_select`/`stream_socket_accept`, manual request-line + header parsing, manual response writing — it just serves exactly one request then is discarded. Mirror it into a new, Desktop-side, **persistent** version:

- New `desktop/app/Services/Mcp/McpHttpServer.php`: binds a *configured* port (not `:0` — the user needs a stable port to put in their one-time setup command, like OpenScreen's `47821`), then loops `accept → parse one HTTP request → dispatch → respond → close connection` indefinitely until told to stop (a `$running` flag flipped by a `stop()` method, checked each loop iteration — same shape as any long-running daemon loop in this codebase).
- Per accepted connection: parse the request line + headers (reuse `LoopbackHttpServer::handleConnection()`'s parsing approach), check `Authorization: Bearer <token>` against the stored token (constant-time compare, `hash_equals()`), read the JSON-RPC body (`Content-Length`-bounded read), then **do not go through Laravel's Router/Kernel at all** — construct `Laravel\Mcp\Server\Transport\HttpTransport` directly (the same class `Registrar::web()` constructs: `new HttpTransport(request())`) against a manually-built `Illuminate\Http\Request` (`Request::create($uri, 'POST', [], [], [], [], $body)` with headers set via `->headers->replace(...)`), feed it into `LaraKubeDesktopServer`'s dispatch the same way the package's own `startServer()` closure does (read `Registrar::startServer()` in `vendor/laravel/mcp/src/Server/Registrar.php` for the exact call shape to mirror), and write the resulting JSON back to the socket. Since this never touches `Illuminate\Foundation\Http\Kernel`, `PreventRegularBrowserAccess` structurally cannot apply — not because of an environment-variable guess, but because that middleware only exists on a pipeline this code never runs through.
- A wrong/missing bearer token gets a plain `401` written directly to the socket, no Laravel response object needed for that path.

This reuses the real `LaraKubeDesktopServer`/tool classes already shipped — no duplicate tool definitions. Read/write distinction (below) is enforced as a check inside this dispatch step, before invoking the tool.

### Read vs. write parity with OpenScreen's "MCP edits" toggle

OpenScreen's screenshot has two separate toggles: "Enable MCP server" and "Allow MCP clients to edit..." (off by default). Our stdio server today has no such split — any stdio client can already call `project-up`/`project-down`/etc (gated only by the tool-level `confirm: true` schema flag). For the HTTP surface specifically — more exposure than a client-spawned stdio subprocess, since it's a standing listener reachable by anything that has the token — mirror this directly:
- Store an `mcpHttpAllowEdits` boolean in `GlobalConfigData`-equivalent (`GlobalSettings`, see Part A's storage section below), default `false`.
- `McpHttpServer`'s dispatch step checks this flag before calling any of the 5 write tools (`project-up`, `project-down`, `project-heal`, `install-cluster-tool`, `remove-cluster-tool`); if false, return a JSON-RPC error for those tool names specifically (read tools always work). This is a second, coarser gate layered on top of each write tool's own existing `confirm: true` requirement from the stdio design — not a replacement for it.
- This flag only applies to the HTTP transport. The stdio server (`larakube-desktop` in `GlobalSettings::bridge()`) is unaffected — it's spawned per-session by a client the user already explicitly chose to connect, a materially different trust model than a standing network listener.

### Storage: new `GlobalSettings` fields

Extend `GlobalSettings` (`app/Services/LaraKube/GlobalSettings.php`) alongside its existing settings (`theme`, `localTld`, etc. — read `get()`/`update()` for the exact pattern to mirror) with:
- `mcpHttpEnabled: bool` (default `false`)
- `mcpHttpAllowEdits: bool` (default `false`)
- `mcpHttpPort: int` (default e.g. `47821` — match OpenScreen's port only coincidentally; pick any free-ish high port, document it)
- `mcpHttpToken: string` — generated once (`Str::random(40)`-style, mirror whatever token-generation convention `GlobalSettings`/`GlobalConfigData` already uses elsewhere, e.g. `shareToken`) on first enable, persisted, regenerable via a dedicated action (**must disconnect all existing clients on regenerate** — the OpenScreen copy says this explicitly; mirror it in our own UI copy).

### Lifecycle: start/stop tied to the app, not per-request

Mirror the two existing precedents found in this codebase exactly:
- **Start**: in `App\Providers\NativeAppServiceProvider::boot()` (the "app is ready" hook — already runs `startScheduler()` here for the one existing precedent of a persistent `ChildProcess`), add `startMcpHttpServerIfEnabled()`: if `GlobalSettings::get()['mcpHttpEnabled']` is true, construct `McpHttpServer` and start it. Since this is pure PHP (no NativePHP binary spawn needed — it's a socket loop, not a subprocess), it needs its own run loop; the simplest robust shape is **running it as its own `ChildProcess::php(...)` subprocess** (not inline in the main Electron-embedded PHP process — running an indefinite blocking `accept()` loop inline would stall the main app's own request handling). Use `ChildProcess::php(['artisan', 'mcp:serve-http'], alias: 'mcp-http', persistent: true)` — a new, small artisan command (`desktop/app/Commands/McpServeHttpCommand.php` — check whether Desktop even has an `app/Commands/` convention or if commands live elsewhere; mirror whatever Desktop's existing custom-command convention is) that just constructs and runs `McpHttpServer` to completion. This mirrors `startScheduler()`'s own `ChildProcess::artisan('schedule:work', ..., persistent: true)` almost exactly — reuse that whole pattern, including its try/catch-and-log-warning-on-failure shape.
- **Stop**: extend the existing `App\Listeners\ShutdownWslOnWindowClose` class (already listens to both `WindowClosed` and `PowerMonitor\Shutdown`, already has a `performShutdown()` private method doing cleanup) — or add a small sibling listener alongside it, whichever keeps that class's single responsibility cleaner — to call `ChildProcess::stop('mcp-http')` in the same shutdown path.
- **Toggling at runtime** (user flips "Enable MCP server" in Settings without restarting the app): the Settings controller action that updates `mcpHttpEnabled` should itself start/stop the `mcp-http` `ChildProcess` immediately (same `ChildProcess::get('mcp-http')`/`start`/`stop` calls), not just persist the setting for next launch — this matches OpenScreen's own instant "Running" badge behavior in the screenshot.

### Settings UI for this (within the new MCP tab — see Part B)

A card matching OpenScreen's layout: "Enable MCP server" toggle, "Allow MCP clients to edit..." toggle (with the same off-by-default explanatory copy style), a Port field, an Access token field (masked, eye-icon reveal, copy button, regenerate button with a confirmation given it disconnects existing clients — reuse this repo's existing confirm-destructive-action UI pattern, e.g. however the uninstall card or purge-on-down confirms), and copy-paste setup snippets per client:
```
claude mcp add --transport http larakube-desktop http://127.0.0.1:{port}/mcp --header "Authorization: Bearer {token}"
```
and the Codex-style env-var variant, mirroring OpenScreen's own two examples. Keep the existing stdio "AI Agent Bridge" card (per-client Connect/Re-link buttons) as-is below/beside this — they're complementary, not a replacement for each other.

## Part B: tabbed Settings page

### Pattern to follow: Mail's split-file model, not SSO's monolith

Confirmed via direct reading: neither Mail nor SSO uses a shared `<Tabs>` component — both hand-roll the same tab-bar JSX (local `useState<TabType>`, a row of buttons with an animated `absolute bottom-0 h-0.5 bg-brand` underline). The difference is content organization: Mail (`resources/js/pages/mail/index.tsx` + `mailboxes-tab.tsx`/`domains-tab.tsx`/`relay-tab.tsx`/`settings-tab.tsx`) splits each tab's content into its own file; SSO keeps all four tabs' content inline in one 1758-line file, which the research explicitly flagged as harder to navigate. **Follow Mail's split-file model** for Settings, since it's by far the larger page (1918 lines, ~17 cards) — inlining would produce an unmanageable single file.

No shared `<Tabs>` component exists anywhere in the codebase today. This plan does not introduce one either — two hand-rolled tab bars (Mail's and Settings' new one) is already the codebase's established pattern, and extracting a shared component is a separate, un-asked-for refactor. Copy Mail's exact tab-bar JSX shape (`resources/js/pages/mail/index.tsx` around lines 249-277/374-410) verbatim for Settings' new tab bar.

### Proposed tab grouping

Map the existing ~17 cards (per the earlier inventory of `resources/js/pages/settings/index.tsx`) into tabs by theme, keeping each tab's card-to-card order unchanged from today:

1. **General** — Appearance & Theme, Default Code Editor & AI Agent, Local Domain & TLD, Workspace Navigation Mode, General & SSL.
2. **AI & MCP** — AI Agent Bridge (stdio, existing) + the new HTTP MCP server card from Part A. (New tab this plan's work directly motivates.)
3. **Advanced** — Experimental features, Demo Mode (+ its three checklists), Default Cloud Provider, Release Channel (Desktop & CLI).
4. **Kubernetes** — Kubernetes Contexts card.
5. **Updates & Diagnostics** — App updates, Diagnostics, and (Windows-only, conditionally rendered within this tab) WSL stale check + LaraKube Linux card.
6. **Danger Zone** — Uninstall card. Kept as its own tab deliberately (mirrors Mail/SSO's own pattern of isolating destructive/access-control actions into their last tab — "Settings & Access" in Mail, "Users & Cleanup" in SSO) so it's never one accidental click away from a benign tab.

"Support LaraKube" (sponsorship card) — low-stakes, no natural home; put it in **General** as the last card, or make it a persistent small footer below the tab bar rather than inside any tab (matches it not being a "setting" at all). Decide at implementation time based on how it looks; not worth a 7th tab for one card.

### File layout

```
resources/js/pages/settings/
  index.tsx              (shell: header, tab bar, tab switch — mirrors mail/index.tsx's shell role)
  general-tab.tsx
  ai-mcp-tab.tsx          (existing AI Agent Bridge card + new HTTP MCP card from Part A)
  advanced-tab.tsx
  kubernetes-tab.tsx
  updates-diagnostics-tab.tsx
  danger-zone-tab.tsx
```

Each `*-tab.tsx` receives exactly the props its cards need (mirror how `MailboxesTab`/`DomainsTab`/etc. are typed in `mail/*.tsx` — narrow, specific prop types per tab, not one giant shared props blob). `index.tsx` keeps the `useState<TabType>` tab state, the main `<form>` wrapper if cards inside different tabs still need to share one submit (check at implementation time whether the current single `<form>` wrapping the left-column cards can be preserved across tabs, e.g. by keeping the form element in `index.tsx` and having tabs render their fields into it via children, or whether each settings-changing action already posts individually per-card — re-check `submit()`'s exact scope in the current file before deciding; several cards, per the inventory, already post immediately on change rather than waiting for a Save button, which simplifies this).

No backend route changes needed — `SettingsController`'s six existing endpoints (`show`, `update`, `setTheme`, `toggleDemoMode`, `syncCli`, `bridge`) stay as-is; this is a frontend-only reorganization, plus Part A's new endpoints (enable/disable HTTP MCP, regenerate token) added to the same controller.

## Verification

- **Part A**: unit/feature tests for `McpHttpServer` mirroring `cli/tests/Unit/Cloud/LoopbackHttpServerTest.php`'s structure (bind a real ephemeral port in tests — not the configured production port — issue real `fsockopen`/`stream_socket_client` requests with/without a valid bearer token, assert 401 vs a real MCP `initialize` JSON-RPC response; assert the edits-disabled path rejects write-tool calls with a clear JSON-RPC error). A `FakeMcpHttpServer` is not needed the way `FakeLoopbackHttpServer` was — this server has no external redirect/browser dependency, a real loopback socket in tests is cheap and deterministic. Manual end-to-end: enable the toggle in a running `native:run` session, run the exact `claude mcp add --transport http ...` snippet the UI shows against a real Claude Code session, confirm tools list and a read call works; flip "Allow MCP clients to edit" off, confirm a write-tool call is rejected; on, confirm it succeeds; quit the app, confirm the `mcp-http` child process is gone (`ps aux | grep mcp:serve-http` shows nothing).
- **Part B**: existing Pest feature tests touching `SettingsController`/`settings/index.tsx` Inertia props should still pass unchanged (no prop shape changes, pure frontend reorg) — run the full suite per this repo's hard rule. Manually click through all six tabs in a running `native:run` session, confirm every card's existing action (theme switch, demo mode toggles, bridge buttons, uninstall flow) still works exactly as before, confirm Danger Zone requires its own tab click (not reachable from General by accident), confirm `npm run types:check` passes.

## Critical files

- `cli/app/Services/Cloud/LoopbackHttpServer.php` — the one-shot socket-server pattern to generalize into `McpHttpServer`.
- `vendor/laravel/mcp/src/Server/Registrar.php` (`web()`/`startServer()`) — exact `HttpTransport` construction/dispatch shape to mirror without going through `Mcp::web()`'s routing.
- `app/Providers/NativeAppServiceProvider.php` (`startScheduler()`) — the persistent-`ChildProcess` pattern to copy for `mcp-http`.
- `app/Listeners/ShutdownWslOnWindowClose.php` — the app-quit cleanup pattern to extend.
- `app/Services/LaraKube/GlobalSettings.php` — where the new `mcpHttp*` fields and their getters/setters belong, alongside existing fields like `shareToken`.
- `resources/js/pages/mail/index.tsx` + `mail/*-tab.tsx` — the tab-bar JSX and split-file model to replicate for Settings.
- `resources/js/pages/settings/index.tsx` — the 1918-line file being split.
