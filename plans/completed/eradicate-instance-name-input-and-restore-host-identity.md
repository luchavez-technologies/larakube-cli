# Eradicate User-Facing "Instance" Identifier and Restore Pure Host Identity

**Status:** Completed ✅
**Context:** User caught rogue "Instance Name" input field, `instance` query/form parameters, and raw internal slug badge displays in LaraKube Desktop.

## Root Cause Analysis
1. In `desktop/resources/js/pages/tools/index.tsx`, `InstallDialog` had an `instance` state and an input block labeled `Instance Name (Subdomain Prefix)`.
2. When submitted, it passed `name="instance"` to `ClusterToolController::store`, which prepended `$instance` to `$domain` and stored `instance` in run metadata and command labels (`Install Tool [instance]`).
3. In `desktop/resources/js/pages/servers/show.tsx`, an `instance` badge `{tool.instance}` was rendered next to tool names, exposing raw internal Kubernetes slugs (e.g. `pocketbase-blog-example-com`) as if they were user identifiers.
4. Links and forms routed using `?instance=slug` instead of `?domain=host`.
5. This directly violated **ADR 0012: The Host is the only identity; `--instance=` is gone everywhere**. The internal slug is strictly derived from the host via `ClusterTool::instanceSlugFromHost($host)` for RFC 1035 Kubernetes resource naming.

## Completed Actions

### 1. Desktop UI: `desktop/resources/js/pages/tools/index.tsx`
- Removed `const [instance, setInstance] = useState('');` from `InstallDialog`.
- Deleted the "Instance Name (Subdomain Prefix)" input entirely.
- Created `defaultSubdomainForTool()` to derive the clean default subdomain (e.g. `pocket` or `pocket2` for additional instances).
- Enabled seamless host selection:
  - If a connected base domain is chosen (e.g. `luchtech.dev`), operators edit the subdomain in front of `.{selectedBaseDomain}` (e.g. `pocket2.luchtech.dev`).
  - Or toggle "+ Custom domain" to type an arbitrary host directly.
  - The form only sends `domain` (the full target host).
- Live preview updated to `https://${domain}`.
- Removed `[${instance}]` from button labels (`Deploy ${name}` or `Install ${name}`).
- Replaced table & card action button text from "Instance" / "Add Instance" to "Deploy Another".
- Used `tool.host` instead of `tool.instance` for detail URLs, keys, and row identifiers.

### 2. Desktop UI: `desktop/resources/js/pages/servers/show.tsx`
- Removed the `{tool.instance}` badge display next to the tool name.
- Used `tool.host` for query parameters (`domain: tool.host`) and keys (`${tool.tool}-${tool.host}`).

### 3. Desktop UI: `desktop/resources/js/pages/tools/show.tsx`
- In `RemoveForm`, replaced `<input type="hidden" name="instance" value={tool.instance} />` with `<input type="hidden" name="domain" value={tool.host} />`.

### 4. Desktop Backend: `desktop/app/Http/Controllers/ClusterToolController.php`
- `store()`: Dropped `$request->string('instance')` reading, domain concatenation, and label bracket suffixes. Passes `--domain={$domain}`.
- `show()`: Resolves tool by domain/host (`$domain = $request->string('domain')->toString() ?: $request->string('host')->toString() ?: $request->string('instance')->toString()`).
- `destroy()`: Resolves tool by domain/host and invokes `{tool}:remove` with `--domain={$host}`.
- `displayName()`: Strips any bracketed legacy suffix.

### 5. Desktop Backend: `desktop/app/Http/Requests/InstallClusterToolRequest.php` & `RemoveClusterToolRequest.php`
- Dropped `'instance'` validation rule from `InstallClusterToolRequest`.
- In `RemoveClusterToolRequest`, accepted `'domain'` and `'host'` alongside `'confirm'`.

### 6. Desktop Backend: `desktop/app/Services/LaraKube/ToolCatalog.php`
- Updated `find($context, $tool, $domainOrHost)` to check `$row['host'] === $domainOrHost || $row['instance'] === $domainOrHost`.

### 7. Verification & Tests
- Updated `desktop/tests/Feature/ClusterToolsTest.php` to verify domain-based install/removal without `instance`.
- Ran `vendor/bin/pest` in `desktop/`: 121 tests passed (778 assertions).
- Ran `npm run types:check` and `npm run build` in `desktop/`: passed with 0 errors.
- Ran `composer test` in `cli/`: 2,771 tests passed (12,722 assertions).
- Ran `composer format` and `composer analyse` in `cli/`: 0 errors.
