# Plan: Eliminate Stale `<Category>Tool` Classes & Consolidate to Concrete Tools

**File:** `cli/plans/active/eliminate-stale-category-tools.md`  
**Status:** Planned / Ready to Execute  
**Related Plans:** `cli/plans/active/remove-category-abstraction.md`, `cli/plans/active/overhaul-tool-categories-to-individual-tools.md`

---

## 🎯 Executive Summary

LaraKube transitioned from category-based commands (`crm:*`, `passwords:*`, `secrets:*`, `drive:*`) to first-class concrete tool verbs (`twenty:*`, `vaultwarden:*`, `openbao:*`, `ocis:*`). However, the underlying implementation classes in `cli/app/Vendors/` and several single-case enums in `cli/app/Enums/` still retain the legacy `<Category>Tool` naming pattern (e.g. `PasswordTool`, `SecretTool`, `CrmTool`, `ChatTool`).

Recently, `DriveTool` was renamed to `OcisTool` following `YopassTool`'s concrete precedent. This plan establishes the roadmap to rename all remaining 21 single-vendor classes and 4 single-target enums into their canonical concrete names, completely eliminating the legacy category abstraction from the tool vendor layer.

---

## 🔍 Inventory & Target Mappings

### 1. Single-Vendor Classes in `cli/app/Vendors/` (21 classes to rename)

| Current File & Class | Backed `ClusterTool` | Concrete Brand | New Class & File Name |
| :--- | :--- | :--- | :--- |
| `PasswordTool.php` | `ClusterTool::VAULTWARDEN` | Vaultwarden | `VaultwardenTool.php` (`VaultwardenTool`) |
| `SecretTool.php` | `ClusterTool::OPENBAO` | OpenBao | `OpenbaoTool.php` (`OpenbaoTool`) |
| `CrmTool.php` | `ClusterTool::TWENTY` | Twenty CRM | `TwentyTool.php` (`TwentyTool`) |
| `MonitorTool.php` | `ClusterTool::GRAFANA` | Grafana | `GrafanaTool.php` (`GrafanaTool`) |
| `MailTool.php` | `ClusterTool::STALWART` | Stalwart Mail | `StalwartTool.php` (`StalwartTool`) |
| `SignTool.php` | `ClusterTool::DOCUMENSO` | Documenso | `DocumensoTool.php` (`DocumensoTool`) |
| `SsoTool.php` | `ClusterTool::ZITADEL` | Zitadel | `ZitadelTool.php` (`ZitadelTool`) |
| `VpnTool.php` | `ClusterTool::NETBIRD` | NetBird | `NetbirdTool.php` (`NetbirdTool`) |
| `UptimeTool.php` | `ClusterTool::KUMA` | Uptime Kuma | `KumaTool.php` (`KumaTool`) |
| `NoteTool.php` | `ClusterTool::OUTLINE` | Outline | `OutlineTool.php` (`OutlineTool`) |
| `SheetTool.php` | `ClusterTool::TEABLE` | Teable | `TeableTool.php` (`TeableTool`) |
| `SupportTool.php` | `ClusterTool::CHATWOOT` | Chatwoot | `ChatwootTool.php` (`ChatwootTool`) |
| `InsightTool.php` | `ClusterTool::METABASE` | Metabase | `MetabaseTool.php` (`MetabaseTool`) |
| `ErrorTool.php` | `ClusterTool::GLITCHTIP` | GlitchTip | `GlitchtipTool.php` (`GlitchtipTool`) |
| `MeetTool.php` | `ClusterTool::LIVEKIT` | LiveKit | `LivekitTool.php` (`LivekitTool`) |
| `DnsTool.php` | `ClusterTool::EXTERNAL_DNS` | ExternalDNS | `ExternalDnsTool.php` (`ExternalDnsTool`) |
| `DashboardTool.php` | `ClusterTool::HEADLAMP` | Headlamp | `HeadlampTool.php` (`HeadlampTool`) |
| `WebmailTool.php` | `ClusterTool::BULWARK` | Bulwark | `BulwarkTool.php` (`BulwarkTool`) |
| `RecordTool.php` | `ClusterTool::SENDREC` | Sendrec | `SendrecTool.php` (`SendrecTool`) |
| `ResumeTool.php` | `ClusterTool::RESUME` | Reactive Resume | `ReactiveResumeTool.php` (`ReactiveResumeTool`) |
| `LinkTool.php` | `ClusterTool::KUTT` | Kutt | `KuttTool.php` (`KuttTool`) |

*Already concrete:*
- `OcisTool.php` (`ClusterTool::OCIS`)
- `YopassTool.php` (`ClusterTool::YOPASS`)

---

### 2. Single-Case Enums in `cli/app/Enums/` (4 enums to consolidate)

These 4 enums contain only a single case because the category only ever supported one tool:

| Current Enum File | Only Case | Target Tool | Target Architecture |
| :--- | :--- | :--- | :--- |
| `ChatTool.php` | `MATRIX = 'matrix'` | Matrix | Convert to `MatrixTool.php` in `app/Vendors/` |
| `DesignTool.php` | `PENPOT = 'penpot'` | Penpot | Convert to `PenpotTool.php` in `app/Vendors/` |
| `GitForgeTool.php` | `FORGEJO = 'forgejo'` | Forgejo | Convert to `ForgejoTool.php` in `app/Vendors/` |
| `TaskTool.php` | `PLANKA = 'planka'` | Planka | Convert to `PlankaTool.php` in `app/Vendors/` |

---

### 3. Multi-Vendor Families (To be addressed in Phase 3)

- `AnalyticsTool.php` (shared vendor implementation for `UMAMI` and `PLAUSIBLE`).
- `DataTool.php` enum (`POCKETBASE`, `DIRECTUS`, `WORDPRESS`).
- `FlowTool.php` enum (`N8N`, `WINDMILL` delegating to `App\Tools\N8n` and `App\Tools\Windmill`).

---

## 🛠️ Implementation Phases

### Phase 1: High-Priority Security & Infrastructure Vendor Renames
Rename the core infrastructure tool vendor classes:
- `PasswordTool` -> `VaultwardenTool`
- `SecretTool` -> `OpenbaoTool`
- `SsoTool` -> `ZitadelTool`
- `VpnTool` -> `NetbirdTool`
- `MailTool` -> `StalwartTool`
- `UptimeTool` -> `KumaTool`
- `MonitorTool` -> `GrafanaTool`

Steps:
1. `git mv app/Vendors/<Old>.php app/Vendors/<New>.php`
2. Update class definition in file.
3. Update import and `vendor()` match in `app/Enums/ClusterTool.php`.
4. Update any test references.
5. Verify with `composer format`, `composer analyse`, and `composer test`.

### Phase 2: Remaining Single-Vendor Classes
Rename the remaining 14 vendor classes:
- `CrmTool` -> `TwentyTool`
- `SignTool` -> `DocumensoTool`
- `NoteTool` -> `OutlineTool`
- `SheetTool` -> `TeableTool`
- `SupportTool` -> `ChatwootTool`
- `InsightTool` -> `MetabaseTool`
- `ErrorTool` -> `GlitchtipTool`
- `MeetTool` -> `LivekitTool`
- `DnsTool` -> `ExternalDnsTool`
- `DashboardTool` -> `HeadlampTool`
- `WebmailTool` -> `BulwarkTool`
- `RecordTool` -> `SendrecTool`
- `ResumeTool` -> `ReactiveResumeTool`
- `LinkTool` -> `KuttTool`

### Phase 3: Single-Case Enum Collapse
Refactor the 4 single-case enums into vendor classes:
- `ChatTool` -> `MatrixTool`
- `DesignTool` -> `PenpotTool`
- `GitForgeTool` -> `ForgejoTool`
- `TaskTool` -> `PlankaTool`
Delete the redundant enum files and update `ClusterTool::vendor()`.

---

## 🧪 Verification & Acceptance Criteria
- Zero `<Category>Tool` single-vendor classes remain in `cli/app/Vendors/`.
- `ClusterTool::vendor()` cleanly instantiates concrete tool classes (`new VaultwardenTool`, `new OpenbaoTool`, etc.).
- Static analysis passes with 0 errors (`composer analyse`).
- Pest test suite passes 100% (`composer test`).
