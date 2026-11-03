# Unified Cluster App Wiring Architecture (`*:wire` / `*:unwire` & `cluster_tools`)

## 1. Problem Statement & Root Cause Diagnosis

Currently, wiring and unwiring cluster applications across LaraKube CLI and Desktop feels fragmented because state and actions are split across four disconnected mechanisms:

1. **Duplicate & Divergent Sources of Truth**:
   - The `cluster_tools` SQLite table (`desktop/app/Models/ClusterTool.php`), synced by `SyncClusterToolsJob` from `larakube tool:list --json`, **already tracks every installed app instance** (`tool`, `instance`, `host`, `installed`, `multi_instance`) along with its live wiring columns in `data`: `sso` (`wired`/`unwired`/`N/A`), `mail` (`wired`/`unwired`/`N/A`), `rotation` (`OpenBao`/`unwired`/`N/A`), `sync` (`synced`/`unsynced`/`N/A`), and `vpn` (`mesh`/`public`/`N/A`).
   - Meanwhile, `SsoStatusCommand::wiredToolsSummary()`, `MailStatusCommand::wiredToolsSummary()`, and `SecretsStatusCommand::staticRoleSummary()` each re-implement their own loops over `ClusterTool::shippedCases()` using `$this->getToolHost($kubectl, $tool)` (which **only checks a single default instance**, ignoring multi-instance apps in `getRegisteredTools($kubectl)`) and only return already-wired rows (`wired => true`).
2. **Inconsistent & Incomplete UI Surfaces**:
   - **Identity (`desktop/resources/js/pages/sso/index.tsx`)**: Requires typing raw text (`tool` slug + `domain`) into a manual form to wire a tool, and only displays already-wired tools in the table.
   - **Mail (`desktop/resources/js/pages/mail/settings-tab.tsx`)**: Displays a read-only "Wired Tools" table buried inside the Settings tab with **zero** Wire or Unwire buttons.
   - **Secrets (`desktop/resources/js/pages/openbao/index.tsx`)**: Displays a Rotation table with a "Rotate" button, but **no** Wire or Unwire buttons (and tells users in an empty state to run `larakube secrets:wire` in a terminal).
   - **App Detail Page (`desktop/resources/js/pages/tools/show.tsx`)**: Shows a static, read-only "Integrations" card (`Single sign-on`, `Email`, `VPN`, `Secrets`) where users cannot click to wire or unwire the app they are viewing.
   - **LiveKit Meet (`meet:wire` / `meet:unwire`) & VPN (`vpn:wire` / `vpn:unwire`)**: Have working CLI commands, but `meet` is missing from `tool:list --json` and neither `vpn` nor `meet` can be toggled in the Desktop UI.
3. **Missing Post-Run Cache & Database Invalidation**:
   - `RunKind::changesClusterTools()` does not include `SsoWire`, `SsoUnwire`, or any other `*Wire` / `*Unwire` run kinds, meaning `cluster_tools` is never updated or re-synced when wiring changes occur.

---

## 2. Target Architecture

```mermaid
flowchart TD
    subgraph CLI["LaraKube CLI"]
        TL["tool:list --json\n(Single Registry + Live Probe)"]
        SW["sso:wire / sso:unwire"]
        MW["mail:wire / mail:unwire"]
        SECW["secrets:wire / secrets:unwire"]
        VW["vpn:wire / vpn:unwire"]
        MTW["meet:wire / meet:unwire"]
        DW["data:wire / data:unwire (Project .env)"]
    end

    subgraph DB["Desktop SQLite (Single Source of Truth)"]
        CT[("cluster_tools table\n(tool, instance, host, installed,\nsso, mail, vpn, rotation, sync, meet)")]
        PROV["Provider Metadata Enrichment\n(OpenBao rotationPeriod / lastRotatedAt)"]
    end

    subgraph UI["Bidirectional 1-Click Desktop UI"]
        APP["App Detail Page (tools/show.tsx)\nInteractive Integrations Card\n(SSO, Mail, VPN, Secrets, Meet)"]
        SSO_UI["Identity Page (sso/index.tsx)\nAll SSO-Capable Installed Apps\n(1-Click Wire / Unwire)"]
        MAIL_UI["Mail Page (mail/index.tsx)\nAll Mail-Capable Installed Apps\n(1-Click Wire / Unwire + Wire All)"]
        SEC_UI["Secrets Page (openbao/index.tsx)\nAll Rotatable Installed Apps\n(1-Click Wire / Unwire / Rotate)"]
        PROJ_UI["Project Show Page (projects/show.tsx)\nData / CMS .env Wiring\n(data:wire / data:unwire)"]
    end

    TL -->|SyncClusterToolsJob| CT
    PROV --> CT
    CT --> APP
    CT --> SSO_UI
    CT --> MAIL_UI
    CT --> SEC_UI
    APP -->|POST /servers/{s}/apps/{t}/wire| SW & MW & SECW & VW & MTW
    SSO_UI -->|POST| SW
    MAIL_UI -->|POST| MW
    SEC_UI -->|POST| SECW
    PROJ_UI -->|POST| DW
    SW & MW & SECW & VW & MTW -->|RecordRunOutput:\n1. Optimistic SQLite flip\n2. SyncClusterToolsJob + Provider Sync| CT
```

### Core Design Decisions (Aligned via `/grill-me`)

1. **`cluster_tools` as the Single Source of Truth**:
   - All installed app wiring states (`sso`, `mail`, `vpn`, `rotation`, `sync`, `meet`) are read directly from the `cluster_tools` SQLite table via `ToolCatalog`.
   - Provider pages (`SsoController`, `MailController`, `OpenBaoController`) derive their "Wired Tools" / "App Integrations" list from `cluster_tools` (filtering to `installed === true` and capability `!== 'N/A'`), enriched with provider-specific metadata where applicable (e.g., `rotationPeriod` and `lastRotatedAt` from `openBaoTool->data['status']['staticRoles']`).
2. **Bidirectional 1-Click UI (No Manual Slug/Domain Text Inputs)**:
   - **On Provider Pages (Identity, Mail, Secrets)**: Show **all** capable installed apps on the cluster (both `wired` and `unwired`), with 1-click **Wire** and **Unwire** buttons per row (plus a "Wire All Unwired" bulk action for Mail and Secrets where `--all` is supported). No manual typing of tool slugs or domains.
   - **On App Detail Page (`tools/show.tsx`)**: Transform the static **Integrations** card into an interactive control center where each supported capability (`Single sign-on`, `Email`, `VPN`, `Secrets Rotation`, `LiveKit Meet`) shows whether its provider is installed on the cluster and provides a direct 1-click **Wire** / **Unwire** button.
3. **Harmonized CLI Flag Contract & Multi-Instance Registry Support**:
   - Standardize all 5 cluster-app wire/unwire command pairs (`sso`, `mail`, `secrets`, `vpn`, `meet`) to accept `--tool=<slug>`, `--domain=<host>`, `--context=<ctx>`, and `--force` (non-interactive safe).
   - Add `meet` wiring status (`'wired'` | `'unwired'` | `'N/A'` | `'—'`) to `ToolListCommand` (`tool:list --json`) for `ClusterTool::MATRIX` (`chat`).
   - Update `SsoStatusCommand::wiredToolsSummary()`, `MailStatusCommand::wiredToolsSummary()`, and `SecretsStatusCommand::staticRoleSummary()` to iterate `$this->getRegisteredTools($kubectl)` so multi-instance installations are never dropped when running CLI status commands in the terminal.
   - Add `DataUnwireCommand` (`data:unwire`) alongside `DataWireCommand` (`data:wire`) for project `.env` CMS wiring on Project pages.
4. **Optimistic Post-Run Update + Background Verification**:
   - When any `*Wire` or `*Unwire` `Run` succeeds in `RecordRunOutput`, immediately update the target `ClusterTool` row's `data` JSON in SQLite (`sso`, `mail`, `vpn`, `rotation`/`sync`, `meet`) so the UI badge and action button flip instantaneously on reload, while dispatching `SyncClusterToolsJob` (and the relevant provider sync job: `SyncSsoJob`, `SyncMailJob`, or `SyncOpenBaoJob`) in the background to verify live cluster state.

---

## 3. Phase-by-Phase Implementation Plan

### Phase 1: CLI Harmonization (`cli/`)

#### 1.1 Add `meet` Wiring Column to `ToolListCommand`
- **File**: [ToolListCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Tool/ToolListCommand.php)
- **Changes**:
  - In `--registry-only` mode (line 200), include `'meet' => 'unverified'`.
  - In the live verification loop (after line 301), compute `$r['meet']`:
    - Only `ClusterTool::MATRIX` (`chat`) supports Meet consumer wiring (`$toolEnum === ClusterTool::MATRIX`). For all other tools, set `$r['meet'] = 'N/A'`.
    - When `$toolEnum === ClusterTool::MATRIX`: if `! $installed`, set `'—'`; otherwise check if the Synapse Meet secret (`chat-synapse-meet-{instance}` via `ToolInstance::forInstance(ClusterTool::MATRIX, $instance)->name('meet', 'synapse')`) exists in `$ns`, setting `'wired'` or `'unwired'`.
  - Also include provider availability flags or derive them from installed rows (`zitadel`, `stalwart`, `openbao`, `netbird`, `livekit`).

#### 1.2 Standardize CLI `*WireCommand` & `*UnwireCommand` Non-Interactive Flags
- **Files**:
  - [SsoWireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Sso/SsoWireCommand.php) & [SsoUnwireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Sso/SsoUnwireCommand.php)
  - [MailWireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Mail/MailWireCommand.php) & [MailUnwireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Mail/MailUnwireCommand.php)
  - [SecretsWireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Secrets/SecretsWireCommand.php) & [SecretsUnwireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Secrets/SecretsUnwireCommand.php)
  - [VpnWireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Vpn/VpnWireCommand.php) & [VpnUnwireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Vpn/VpnUnwireCommand.php)
  - [MeetWireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Meet/MeetWireCommand.php) & [MeetUnwireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Meet/MeetUnwireCommand.php)
- **Changes**:
  - Ensure all 10 commands accept `{environment=local}`, `{--tool=}`, `{--domain=}`, `{--context=}`, and `{--force}` (currently `MeetWireCommand` and `MeetUnwireCommand` lack `--domain=` and `--force`, and `MailWireCommand` requires cached sender credentials or auto-provisions/uses default sender when `--no-interaction` / `--force` is passed).
  - In `MailWireCommand::resolveSenderCredentials()`: when running non-interactively (`--no-interaction`) and no cached sender secret exists yet, check if Stalwart admin credentials exist in the cluster and auto-provision/cache the default `noreply@<mailDomain>` sender mailbox & app password (or surface a clear actionable error prompting the operator to configure the sender in Mail -> Wired Tools).

#### 1.3 Fix Multi-Instance Iteration in Status Commands
- **Files**:
  - [SsoStatusCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Sso/SsoStatusCommand.php) (`wiredToolsSummary()`)
  - [MailStatusCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Mail/MailStatusCommand.php) (`wiredToolsSummary()`)
  - [SecretsStatusCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Secrets/SecretsStatusCommand.php) (`staticRoleSummary()`)
- **Changes**:
  - Iterate `$this->getRegisteredTools($kubectl)` (matching `SecretsStatusCommand::customEnvSummary()` and `ToolListCommand`) instead of calling `$this->getToolHost($kubectl, $tool)` once per `ClusterTool::shippedCases()`, so multiple instances of the same tool (e.g., two WordPress or two PocketBase instances) are all reported accurately.

#### 1.4 Add `DataUnwireCommand` (`data:unwire`) for Projects
- **File**: `cli/app/Commands/Data/DataUnwireCommand.php` (new)
- **Changes**:
  - Mirrors [DataWireCommand.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Data/DataWireCommand.php): removes the injected `{PREFIX_}{ENGINE}_URL` and `{ENGINE}_URL` keys from `.env` / `.env.{environment}`.

---

### Phase 2: Desktop Backend & Unified Wiring Controller (`desktop/`)

#### 2.1 Expand `RunKind` for All Wiring Operations
- **File**: [RunKind.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Enums/RunKind.php)
- **Changes**:
  - Add cases:
    - `MailWire = 'mail-wire'`, `MailUnwire = 'mail-unwire'`
    - `OpenBaoWire = 'openbao-wire'`, `OpenBaoUnwire = 'openbao-unwire'`
    - `VpnWire = 'vpn-wire'`, `VpnUnwire = 'vpn-unwire'`
    - `MeetWire = 'meet-wire'`, `MeetUnwire = 'meet-unwire'`
    - `DataWire = 'data-wire'`, `DataUnwire = 'data-unwire'`
  - Update `changesClusterTools()` to return `true` for all cluster-tool wire/unwire kinds (`SsoWire`, `SsoUnwire`, `MailWire`, `MailUnwire`, `OpenBaoWire`, `OpenBaoUnwire`, `VpnWire`, `VpnUnwire`, `MeetWire`, `MeetUnwire`).
  - Update `changesMail()` to include `MailWire`, `MailUnwire`.
  - Update `changesOpenBao()` to include `OpenBaoWire`, `OpenBaoUnwire`.
  - Update `changesSso()` to include `SsoWire`, `SsoUnwire` (already present).

#### 2.2 Optimistic `cluster_tools` Updates in `RecordRunOutput`
- **File**: [RecordRunOutput.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Listeners/RecordRunOutput.php)
- **Changes**:
  - Add helper `$this->optimisticallyUpdateToolWiring(Run $run, Server $server)`:
    - When `$status === RunStatus::Succeeded` and `$run->kind` is one of the wire/unwire kinds:
      - Locate the target `ClusterTool` row(s) on `$server` by `tool` (`$run->meta['tool'] ?? $run->subject`) and optional `host` / `domain` (`$run->meta['domain'] ?? null`). If `meta['all']` is true (e.g. `mail:wire --all` or `secrets:wire --all`), target all installed capable rows.
      - Immediately flip the corresponding key in `$clusterTool->data`:
        - `SsoWire` / `SsoUnwire` -> `data['sso'] = 'wired' | 'unwired'`
        - `MailWire` / `MailUnwire` -> `data['mail'] = 'wired' | 'unwired'`
        - `OpenBaoWire` / `OpenBaoUnwire` -> `data['rotation'] = 'OpenBao' | 'unwired'`, `data['sync'] = 'synced' | 'unsynced'`
        - `VpnWire` / `VpnUnwire` -> `data['vpn'] = 'mesh' | 'public'`
        - `MeetWire` / `MeetUnwire` -> `data['meet'] = 'wired' | 'unwired'`
      - Also mark `$clusterTool->sync_status = 'syncing'` and dispatch `SyncClusterToolsJob::dispatch($server->id)` plus the provider sync job (`SyncSsoJob`, `SyncMailJob`, `SyncOpenBaoJob`).

#### 2.3 Unified `ClusterToolWiringController` (or actions on `ClusterToolController`)
- **Files**:
  - [ClusterToolController.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/ClusterToolController.php)
  - [web.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/routes/web.php)
- **Routes**:
  - `POST /servers/{server}/apps/{tool}/wire` (`servers.apps.wire`)
  - `POST /servers/{server}/apps/{tool}/unwire` (`servers.apps.unwire`)
  - Also keep existing `servers.sso.wire` / `servers.sso.unwire` and add `servers.mail.wire` / `servers.mail.unwire` and `servers.openbao.wire` / `servers.openbao.unwire` (or route them all through the same shared `ClusterToolWiringService` so there is zero duplicated CLI argument building).
- **Request Payload**:
  - `integration`: `'sso' | 'mail' | 'vpn' | 'secrets' | 'meet'`
  - `domain`: `string` (optional — defaults to the tool's registered `host`, or `'all'` when `--all` is requested)
  - Optional integration-specific parameters:
    - For `sso`: `admin_email` (optional), `sso_only` (optional boolean)
    - For `mail`: `sender` (optional), `app_password` (optional), `all` (optional boolean)
    - For `secrets`: `rotation_period` (optional, default `168h`), `all` (optional boolean)

#### 2.4 Unify Provider Controllers Around `cluster_tools`
- **Files**:
  - [ClusterToolController.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/ClusterToolController.php) (`show()`):
    - Pass `installedProviders` prop to `tools/show.tsx`: `{ sso: bool, mail: bool, vpn: bool, secrets: bool, meet: bool }` derived from `ToolCatalog` for the same server context, so the Integrations card knows whether the backing provider (Zitadel, Stalwart, NetBird, OpenBao, LiveKit) is installed.
  - [SsoController.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/SsoController.php) (`index()`):
    - Build `wireableTools` from `ToolCatalog::forContext($context)` (all rows where `installed === true`, `tool !== 'zitadel'`, and `sso !== 'N/A'`), returning each row's `tool`, `name`, `brand`, `instance`, `host`, `logo`, and `wired: $row['sso'] === 'wired'`.
  - [MailController.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/MailController.php) (`index()`):
    - Build `wireableTools` from `ToolCatalog::forContext($context)` (all rows where `installed === true`, `tool !== 'stalwart'`, and `mail !== 'N/A'` or `tool === 'zitadel'`), merging any Zitadel SMTP status from `mailStatus->wiredTools($context)` so every capable installed app (wired and unwired) appears with accurate status.
  - [OpenBaoController.php](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/OpenBaoController.php) (`index()`):
    - Build `rotatableTools` by combining `ToolCatalog::forContext($context)` (all rows where `installed === true` and `rotation !== 'N/A'`) with `openBaoStatus->status($context)['staticRoles']` (which enriches wired rows with `role`, `rotationPeriod`, and `lastRotatedAt`). Unwired rotatable apps now appear in the Rotation table with `wired: false` and a 1-click **Wire** button!

---

### Phase 3: Cohesive Desktop UI (`desktop/resources/js/`)

#### 3.1 Interactive Integrations Card on App Detail Page (`tools/show.tsx`)
- **File**: [show.tsx](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/tools/show.tsx)
- **Changes**:
  - Upgrade the `Integrations` card (lines 212-226) from read-only `WiringPill` rows to interactive rows for every supported integration on `tool`:
    1. **Single Sign-On (Zitadel)**: `tool.sso` (`wired` / `unwired`) -> **Wire SSO** (`Plug` icon) or **Unwire** (`Unlink` icon). If Zitadel is not installed, show muted `Install Zitadel` link to the App Store.
    2. **Outbound Email (Stalwart)**: `tool.mail` (`wired` / `unwired`) -> **Wire Mail** (`Plug` icon) or **Unwire** (`Unlink` icon).
    3. **VPN Mesh (NetBird)**: `tool.vpn` (`mesh` / `public`) -> **Restrict to VPN** (`ShieldCheck` icon) or **Make Public** (`Globe` icon).
    4. **Secrets & DB Rotation (OpenBao)**: `tool.rotation` (`OpenBao` / `unwired`) -> **Wire Rotation** (`RefreshCw` icon) or **Unwire** (`Unlink` icon).
    5. **Video Calling (LiveKit Meet)** (shown when `tool.meet !== 'N/A'` and `tool.meet !== undefined`): `tool.meet` (`wired` / `unwired`) -> **Wire Meet** (`Plug` icon) or **Unwire** (`Unlink` icon).
  - Include lightweight inline confirmation before destructive unwiring (matching the confirmation pattern in `sso/index.tsx`).

#### 3.2 Identity Page (`sso/index.tsx`) — Replace Manual Form with `cluster_tools` Table
- **File**: [index.tsx](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/sso/index.tsx)
- **Changes**:
  - Remove the manual 3-input text form (`Tool name (e.g. forgejo)`, `Domain (e.g. git.example.com)`).
  - In the **Wired Tools** tab (or renamed **App Integrations** / **Wired Apps**), display all SSO-capable installed apps from `cluster_tools`:
    - Connected apps show `<StatusPill tone="ok">Connected</StatusPill>` and an **Unwire** button (`Unlink` icon).
    - Unconnected apps show `<StatusPill tone="muted">Not connected</StatusPill>` and a 1-click **Wire SSO** button (`Plug` icon). For apps that support `--admin-email` (like `drive` / oCIS), clicking **Wire SSO** can optionally allow entering an admin email in a compact inline popover or default cleanly.
  - Also update the **Grant Access** modal (`granting` state) to offer a dropdown `<select>` of wired tools from `cluster_tools` (auto-filling `tool` and `domain` from the selected app instance) instead of forcing the user to type the tool slug and domain manually!

#### 3.3 Mail Page (`mail/index.tsx` & `mail/settings-tab.tsx`) — Interactive Outbound App Wiring
- **Files**:
  - [index.tsx](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/mail/index.tsx)
  - [settings-tab.tsx](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/mail/settings-tab.tsx)
- **Changes**:
  - Upgrade the **Wired Tools** table in `settings-tab.tsx`:
    - List all SMTP-capable installed apps on the server (both wired and unwired) from `cluster_tools`.
    - Add an **Actions** column with 1-click **Wire** (`Plug` icon) and **Unwire** (`Unlink` icon) buttons per app row.
    - Add a **Wire All Apps** (`Plug` icon) header button (`mail:wire --all`) and a **Sender Credentials** configuration drawer/modal (`--sender` / `--app-password`) so operators can set or update the Stalwart sender account used when wiring tools.

#### 3.4 Secrets Page (`openbao/index.tsx`) — Interactive DB Rotation Wiring
- **File**: [index.tsx](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/openbao/index.tsx)
- **Changes**:
  - In the **Rotation** tab:
    - Display all DB-rotatable installed apps from `cluster_tools` (both wired and unwired).
    - For **Wired** apps: show `Rotation Period` (`7d`), `Last Rotated`, a **Rotate Now** button (`RefreshCw` icon), and an **Unwire** button (`Unlink` icon).
    - For **Unwired** apps: show `<StatusPill tone="muted">Not wired</StatusPill>` and a 1-click **Wire Rotation** button (`Plug` icon), plus a **Wire All Rotatable Apps** (`Plug` icon) header button (`secrets:wire --all --force`).
  - Also update the **Grant Access** / **Revoke Access** forms in `openbao/index.tsx` to use a `<select>` dropdown of installed `cluster_tools` instead of a raw text input for `app`.

#### 3.5 Project Show Page (`projects/show.tsx`) — Data / Headless CMS Wiring
- **File**: [show.tsx](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/projects/show.tsx)
- **Changes**:
  - Where a project links to a server that has an installed Data / Headless CMS instance (`pocketbase`, `directus`, `wordpress`) in `cluster_tools`, provide a 1-click **Wire CMS URL (`data:wire`)** / **Unwire (`data:unwire`)** action in the project's Environment / Services section.

---

## 4. Verification & Testing Strategy (TDD)

1. **CLI Unit & Feature Tests (`cli/tests/`)**:
   - `ToolListCommandTest`: Verify `meet` column is emitted in `tool:list --json` (`wired` / `unwired` for `matrix`, `N/A` for others).
   - `SsoStatusCommandTest`, `MailStatusCommandTest`, `SecretsStatusCommandTest`: Verify multi-instance tools registered in `larakube-tools-registry` are all included in status output.
   - `DataUnwireCommandTest`: Verify `data:unwire` cleanly removes `{PREFIX_}{ENGINE}_URL` and `{ENGINE}_URL` keys from `.env`.
   - Run `composer format`, `composer analyse`, and `composer test` (parallel Pest).
2. **Desktop Feature Tests (`desktop/tests/Feature/`)**:
   - `ClusterToolWiringTest`: Verify `POST /servers/{server}/apps/{tool}/wire` and `unwire` dispatch the expected CLI commands (`sso:wire`, `mail:wire`, `secrets:wire`, `vpn:wire`, `meet:wire` and their `:unwire` counterparts) with `--tool`, `--domain`, `--context`, and `--force`.
   - `RecordRunOutputWiringTest`: Verify that when any `*Wire` or `*Unwire` run completes with `RunStatus::Succeeded`, the corresponding `ClusterTool` row in SQLite is immediately updated (`sso`, `mail`, `vpn`, `rotation`, `sync`, `meet`) and `SyncClusterToolsJob` + provider sync jobs are dispatched.
   - Run `composer test`, `./vendor/bin/pint --test`, `./vendor/bin/phpstan analyse`, `tsc --noEmit`, and `vp check`.

