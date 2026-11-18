# App page: configuration transparency, live logs, and a slimmer Manage drawer

## Context

The Apps page's Manage drawer (`desktop/resources/js/components/manage-tool-drawer.tsx`) is a fixed 500px column with seven stacked cards:
- Access
- Integrations
- Custom env vars (`ToolEnvCard`)
- Components
- Images
- Plex Commons backing
- Multi-instance and Remove

It is already crowded, and the user wants more: full transparency about each app's ConfigMaps and Secrets, and live logs from all its components.

A dedicated app page already exists (`pages/tools/show.tsx`, route `servers.apps.show`, `ClusterToolController::show`). Nothing links to it.

**User decisions:**
- **Layout:** a slim drawer plus a full app page with tabs.
- **Secrets:** shown masked, with a Reveal per value.
- **Logs:** one merged live stream, each line tagged with its component, with a filter per component.

**What the LaraKube CLI is missing** (CLI-first, so Desktop never shells `kubectl` itself):
- **No command lists an app's ConfigMaps and Secrets.** Only `tool:env --json` exists, and it covers the user's custom overlay only.
- **`{tool}:logs` can't serve the merged stream.** It follows the primary Deployment only, with no `--component`, `--since` or `--json`, and prints a header first.
- **Labels can't be relied on to find resources.** Many wire-written Secrets carry no `luchtech.dev/*` labels, so a label selector alone misses them.

## Part 1: LaraKube CLI

### 1a. `tool:resources`: list an app's ConfigMaps and Secrets
New command `app/Commands/Tool/ToolResourcesCommand.php`.
- **Signature:** `tool:resources {environment} --tool= --domain= --context= --json`.
  - Instance resolution follows `ToolEnvCommand`.
- **Discovery, so it doesn't depend on labels:**
  - Read the instance's workloads (Deployments, StatefulSets and DaemonSets via `ToolInstance::selector()`). Collect every ConfigMap and Secret they reference:
    - `envFrom`
    - `env[].valueFrom.{configMapKeyRef,secretKeyRef}`
    - `volumes[].{configMap,secret,projected}`
  - Union that with `get configmap,secret -l <selector>`.
  - This lists what the app actually uses, whatever the resource is named.
- **Each resource:** `{kind, name, keys:[{key, owner, value?}]}`.
  - ConfigMap values are included.
  - Secret values are **never** included; the list carries keys and owners only.
- **`owner` per key:**
  - `rotated`: an ExternalSecret targets that Secret and maps the key, so OpenBao rewrites it. Read `get externalsecret -n <ns> -o json` and match `spec.target.name` with `spec.data[].secretKey`.
  - `custom`: the key sits in the instance's `configMap('custom')` or `secret(SecretKind::CUSTOM)` (reuse `ManagesToolCustomEnv`). Editable through the existing `tool:env:set`.
  - `managed`: everything else, written by `:init` or `:wire`. Read-only.
- **Without `--json`:** a Laravel Prompts `table()`.

### 1b. `tool:secret`: reveal one value
New standalone command `app/Commands/Tool/ToolSecretCommand.php`. Revealing is a distinct operation, so it gets its own command rather than a hidden flag.
- **Signature:** `tool:secret {environment} --tool= --domain= --name= --key= --context= --json`.
- **Output:** `{name, key, value}`.
- **Scope check:** it refuses any Secret that 1a would not list for this instance, so it can't be used to read arbitrary Secrets.

### 1c. Merged, machine-readable logs
Extend `app/Commands/Tool/AbstractToolLogsCommand.php`, which is shared by all `{tool}:logs`.
- **New flags:**
  - `--component=`
  - `--since=` (a duration)
  - `--since-time=` (RFC3339)
  - `--json`
- **With `--json`:**
  - No header.
  - Runs `kubectl logs -l <ToolInstance::selector(component?)> --prefix --all-containers --timestamps --max-log-requests=20 --tail=N` plus since.
    - This follows the project `LogsCommand --all` pattern (`app/Commands/LogsCommand.php`).
  - Each line is parsed into `{time, pod, container, component, line}`.
    - `component` comes from the pod's `luchtech.dev/component` label, via one `get pods -l <selector>`.
  - Emits a JSON array sorted by time.
- **Unchanged:** the existing non-JSON follow behaviour.

### Tests (ADR 0019)
- `ToolResourcesCommandTest`:
  - discovery via workload references, labels, and their union;
  - owner tags `rotated`, `custom` and `managed`;
  - no Secret values appear in the output.
- `ToolSecretCommandTest`:
  - a value is revealed;
  - a Secret outside the app is refused.
- Logs: a `--json` test for parsing, the component tag, and `--since-time`.

## Part 2: Desktop

### 2a. Services and endpoints
- **`app/Services/LaraKube/ToolResources.php`:** wraps `tool:resources --json` and `tool:secret --json`. Synchronous CLI calls, following `ClusterToolEnv`.
- **`app/Services/LaraKube/ToolLogs.php`:** wraps `{tool}:logs --json`.
- **New `ClusterToolController` actions and routes** under `servers/{server}/apps/{tool}`:
  - `GET resources?domain=`
  - `POST resources/reveal {domain, name, key}` (POST, so values never land in URLs or access logs)
  - `GET logs?domain=&component=&since_time=`

### 2b. App page (`pages/tools/show.tsx`), with tabs
The active tab is kept in the query string.

- **Overview:**
  - Access (URL, DNS/HTTPS).
  - Integrations with Re-apply/Connect/Disconnect, as now.
  - Components with ready/replicas/paused.
  - Backing services (existing `BackingServicesCard`).
  - Multi-instance "Deploy another".
  - Danger zone.
- **Configuration:**
  - One card per ConfigMap or Secret, with key rows.
  - ConfigMap values are shown.
  - Secret values are masked (`••••`), with a per-row **Reveal** (`Eye`) and **Copy** (`CopyButton`).
  - Badges per key: "Managed by LaraKube", "Rotated by OpenBao", "Custom".
  - Custom keys keep the existing `ToolEnvCard` editing.
  - A note that managed and rotated values change through Re-apply or rotation, not by hand.
- **Logs:**
  - One merged live stream, reusing `components/log-panel.tsx`. Each line is prefixed `[component]`.
  - Component filter chips.
  - Pause/Resume (`Pause`/`Play`).
  - Tail size and "since" controls.
  - Polls the logs endpoint every 2s with `since_time` set to the last line's timestamp, appending only new lines. Polling runs only while the Logs tab is visible.
  - Same mechanics as `workloads-pods-card.tsx`.
- **Images:** the existing `ImageRef` and `PulledDigests` rows.
- **Realtime:** `useServerDataReload(server.name, { tools: [...] })` (ADR 0021).

### 2c. Slim Manage drawer
- **Keeps:**
  - Header and status.
  - Access (URL, QR, DNS/HTTPS pills, host change).
  - A compact Integrations row of pills.
  - Update / Pause / Resume.
- **Gains:** a primary **Open app page** button, linking to `servers.apps.show` with `domain`.
- **Moves to the page:**
  - Custom env vars
  - Components
  - Images
  - Plex Commons backing
  - Multi-instance
  - Remove
- **Width:** unchanged at 500px.
- The app card's name also links to the page.

### 2d. ADR
Desktop ADR `0024-app-configuration-transparency.md`:
- An app's full configuration is visible in Desktop.
- Secret values are masked and revealed one at a time, through `tool:secret`, scoped to that app.
- Managed and rotated keys are read-only in the UI.

Add it to the README index.

### Tests (Pest)
- **Endpoints:** resources, reveal and logs. Each runs the right CLI command with `--json`. Reveal refuses a missing name or key.
- **Page:** renders with the tabs' props.
- **Drawer:** no longer renders the moved sections.

## Housekeeping
- First step of implementation: save this plan to `cli/plans/active/app-page-config-and-logs.md`, as AGENTS.md requires.
- The relabel run (Re-apply buttons, Heal/Deploy) stays separate and can happen any time. It doesn't depend on this work.

## Verification
- **LaraKube CLI:**
  - Targeted tests, then the full suite (via the pre-commit hook).
  - After `./build`, against luchtech-vps (read-only):
    - `tool:resources production --tool=matrix --json` lists the Synapse, MAS and Element ConfigMaps and Secrets, with owners.
    - `tool:secret` reveals one key.
    - `matrix:logs production --json --since=2m` returns lines tagged synapse, mas and element.
- **Desktop:**
  - The full suite.
  - In the app:
    - open Matrix → **Open app page**;
    - Configuration shows masked Secrets, and Reveal/Copy work;
    - Logs streams merged lines, the chips filter by component, Pause stops polling;
    - the drawer shows only the slim set.
- **Commits:** conventional; CLI first (`feat(tool): …`), then Desktop (`feat(apps): …`), then the ADR (`docs(adr): …`).
