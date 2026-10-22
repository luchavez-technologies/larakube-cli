# Implementation Plan: Server Name, Polished Actions, Right-Aligned QR, and Default Editor/Agent Preference

## 🎯 Objectives
Address the user feedback across projects, servers, quick actions, and settings:
1. **Server Name over IP**: Display the server name (e.g., `luchtech-vps`) instead of the IP address, since managed Kubernetes clusters (DOKS, EKS, AKS) do not have a single static node IP address.
2. **Server 404 Graceful Handling**: Prevent the stark white `404 | Not Found` screen when clicking a missing server by redirecting to `/servers` with a flash message.
3. **Right-Aligned QR Codes**: Align the QR code preview button to the right of the `Host` column across all rows.
4. **Enhanced Quick Actions**: Align table and card quick actions with the Show Project page:
   - Up (Build & Run): `ArrowUpCircle`
   - Start (Resume): `Play`
   - Stop (Pause): `Square`
   - Down (Teardown): `PowerOff`
   - Status-aware disabled states and variant indicators.
5. **"Open with" Action**: Provide an editor/agent launcher (`<EditorMenu />`) directly on projects table and cards.
6. **Default Editor / AI Agent Setting**:
   - Add a configuration for `defaultEditor` in `GlobalSettings` (`claude`, `antigravity`, `cursor`, `vscode`, etc.).
   - Expose in Desktop `Settings` page with rich icon selector.
   - Update Menu Bar Tray (`tray/index.tsx`) to open the user's selected default editor/agent instead of hardcoded VS Code.

---

## 🏗️ Architecture & Component Design

### 1. Backend: Server Name Resolution & Fallback
- In `ProjectController::index`:
  - Eagerly resolve `serverName` and `serverProvider` using `$project->server` and `$stacks` catalog.
  - Return `serverName` and `serverProvider` in the Inertia payload.
- In `ServerController::show`:
  - If a server name or IP isn't found in `StackCatalog`, attempt to find it in `Server` model.
  - If still not found, return `to_route('servers.index')->with('error', "Server '{$server}' not found.")` instead of raw `abort(404)`.

### 2. Frontend: Table Host Column & QR Code Right-Alignment
- In `projects/index.tsx`:
  - Line 1: `https://${project.webHost}` on left (`min-w-0 truncate`), `<QrCodePopover />` pushed to right via `justify-between`.
  - Line 2: `<ProviderLogo />` + Server Name (clickable `<Link href={showServer(serverTarget).url}>`).

### 3. Quick Actions Component Upgrade (`ProjectActions.tsx`)
- Update `ProjectActions.tsx` icons to match `show.tsx`:
  - `ArrowUpCircle` (Up)
  - `Play` (Start)
  - `Square` (Stop)
  - `PowerOff` (Down)
- Synchronize lifecycle detection:
  - `canUp = !busy`
  - `canStart = !busy && (state === 'paused' || state === 'stopped' || state === 'down')`
  - `canStop = !busy && (state === 'running' || state === 'starting')`
  - `canDown = !busy && state !== 'down'`

### 4. "Open with" Launcher
- Export `EditorMenu` into a reusable component `desktop/resources/js/components/editor-menu.tsx`.
- Pass available editors and `defaultEditor` to `projects/index.tsx`.
- Include compact `<EditorMenu />` in the table actions and card footer.

### 5. Settings: Default Code Editor / AI Coding Agent
- In `GlobalSettings.php`:
  - Add `getDefaultEditor(): string` (defaults to `'vscode'`).
  - Add `setDefaultEditor(string $editor): void`.
  - Add validation in `SettingsController::update`.
- In `settings/index.tsx`:
  - Render selectable editor/agent cards or dropdown with `EditorLogo` for all available editors and agents.
- In `tray/index.tsx`:
  - Read `data.defaultEditor ?? 'vscode'` and render `<EditorLogo slug={defaultEditor} size="xs" />`.
  - Trigger `/tray/open-editor/{id}` with `{ editor: defaultEditor }`.

---

## 🧪 Testing & Verification Plan
- `npm --prefix desktop run types:check`: Verify all TypeScript types pass.
- `cd desktop && ./vendor/bin/pint --test`: Lint styling.
- `cd desktop && ./vendor/bin/phpstan analyse --memory-limit=1G`: Static analysis.
- `cd desktop && ./vendor/bin/pest --parallel`: Ensure full test suite passes.

---

## ✅ Implementation Summary & Results
- **Server Name Display**: Replaced raw IP address with `{project.serverName ?? project.serverIp}` paired with `ProviderLogo` (DigitalOcean, AWS, GCP, Hetzner, etc.) and deep linked to `/servers/{serverTarget}`.
- **Graceful 404 Experience**: Built custom `desktop/resources/views/errors/404.blade.php` styled with dark/light mode theme matching LaraKube, clear explanation, and navigation buttons to return to Servers or Projects. Added database model fallback in `ServerController::show`.
- **Right-Aligned QR Codes**: Table cells now use `justify-between` so the `QrCodePopover` button is consistently pinned to the right of each row.
- **Polished Quick Actions**: Aligned `ProjectActions.tsx` icons with `show.tsx` (`ArrowUpCircle`, `Play`, `Square`, `PowerOff`) and harmonized lifecycle state guards (`canUp`, `canStart`, `canStop`, `canDown`).
- **Reusable `<EditorMenu />`**: Extracted into `desktop/resources/js/components/editor-menu.tsx`, mounted as compact launcher in table action column and card footers, and reused in project detail view.
- **Default Editor / AI Agent Preference**:
  - Implemented `defaultEditor` in `GlobalSettings` (`'vscode'`, `'claude'`, `'antigravity'`, `'cursor'`, `'windsurf'`, etc.) with validation in `SettingsController`.
  - Added selectable card grid with `EditorLogo` in `desktop/resources/js/pages/settings/index.tsx`.
  - Updated Menu Bar Tray (`tray/index.tsx`) to open the user's default editor (with matching dynamic `EditorLogo`) instead of hardcoded VS Code.
- **Verification**: `npm run types:check`, `pint --test`, `phpstan analyse`, and full 652 Pest tests in parallel are all green.
