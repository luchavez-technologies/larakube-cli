# App fit and capacity

## Status: A1 IN PROGRESS (approved 2026-11-16). Part A of 3; Supabase plan depends on it.

### Progress
- **A1 done (uncommitted):**
  - `ClusterCapacitySnapshot` counts used memory as max(pod requests, live node usage), read from `metrics.k8s.io` nodes; `basis()` reports 'usage' or 'requests'. CPU stays request-based.
  - `cpuQuantityToMillicores` now accepts nano/microcores (`n`/`u`).
  - Requests added, with no limits, from a single luchtech-vps reading: memory = idle × 1.25 rounded up to 16Mi, CPU = live × 2 rounded up to 10m. Applies to Traefik (3 manifests, 50m/64Mi with a 256Mi limit) and ExternalDNS (10m/48Mi with a 128Mi limit), plus OpenBao, reloader, ESO cert-controller/webhook, Bulwark, Headlamp, oCIS, Stalwart, Zitadel (+ pat-reader), Vaultwarden, and the NetBird client/proxy/management/signal/relay.
- **A1 remaining:**
  - Tools with no live reading still lack requests; the guard counts them at its 50m/64Mi floor. These are GlitchTip, Metabase, Windmill (server/worker/lsp), Uptime Kuma, Mailpit, SendRec, Yopass, the oCIS collaboration container, the Grafana exporters, the companion consoles, and the bundled `--no-plex` Postgres/Valkey in Zitadel, Matrix, Windmill and GlitchTip.
  - Size each from upstream's documented figures (verify live), then add the catalog-wide "every container declares requests" matrix test. That test needs the A2 render hook to reach every tool.
  - Phase 3 leftovers: wire the guard into the deferred installs.
- **Flagged, not changed:** `ConfigData::TRAEFIK_IMAGE` is `v3.7.13` (latest stable `v3.7.19`); ExternalDNS is pinned at `v0.21.0` (latest `v0.24.0`, so check its changelog for flag changes before bumping).


## Context

In a demo, the user's friend wanted **numbers**, and was shocked that a fresh server already showed about half its memory in use before any apps were installed. Separately, PocketBase drew less interest than expected on the dashboard, and Supabase should take its place. On a small server, though, Supabase (about 4 GB) can't fit at all.

Findings from reading the code:

1. **The "half used" is real usage, not requests.** The Desktop server page reads `kubectl top nodes` (`desktop/app/Services/LaraKube/ClusterMetrics.php:20`). Traefik (`cli/resources/views/k8s/traefik-*.blade.php`) and ExternalDNS (`k8s/external-dns/*`) declare **no** `resources:` at all. On a 2 GB node most of the usage is the k3s control plane plus the OS, which run outside pods.
2. **The capacity guard is optimistic.** `ClusterCapacitySnapshot` (`cli/app/Data/ClusterCapacitySnapshot.php`) computes free memory as allocatable minus *pod requests*, so unrequested pods and non-pod k3s memory count as free.
3. **Desktop skips the guard.** `QuickActionController::launch()` (`desktop/app/Http/Controllers/QuickActionController.php:~101`) and `Mcp/Tools/InstallClusterToolTool.php:171` always pass `--force`, which is also the guard's bypass (`AbstractToolInitCommand::guardClusterCapacity()`, :68).
4. **There is no footprint catalog.** The capacity-guard plan (`cli/plans/active/pre-install-cluster-capacity-guard.md`, Phases 0–2 shipped) settled on deriving numbers from the rendered manifest rather than hand-writing a second figure. Only Forgejo, the monitoring stack and n8n have real `resources:` blocks, and `tool:catalog --json` exposes no sizes.

**Goal:** tell users plainly what each app needs, what their server has, and when an app won't fit, offer three ways out: **pause unused apps, upsize the server, or pick a lighter alternative**. Desktop must refuse an install that won't fit, such as Supabase on a 2 GB droplet.

**Build order:**
- **Part A, capacity and fit.** Done first, because the other parts depend on it. Saved as `cli/plans/active/app-fit-and-capacity.md`.
- **Part B, Supabase tool.** Saved as `cli/plans/active/supabase-cluster-tool.md`.
- **Part C, Desktop flip card.** Saved in the same Supabase plan file.

---

## Part A: App fit and capacity (CLI + Desktop)

### A1. Make the numbers honest (CLI)
- **Effective use:** in `ClusterCapacitySnapshot` / `InteractsWithClusterCapacity::clusterCapacitySnapshot()`, used memory becomes **max(sum of pod requests, live node usage)**, read from the metrics API (`kubectl top nodes`, metrics-server ships with k3s). This counts k3s, the OS and unrequested pods.
  - If metrics are unavailable (K9s showed `n/a` on one cluster), it falls back to requests and marks the result `"basis": "requests"` so the UI can say "estimate".
- **Traefik and ExternalDNS requests:** add real `resources:` requests to both, using `kubectl top pod` readings from `luchtech-vps` as Phase 0 did, not guesses.
- **Every container declares requests:** add a catalog-wide matrix test that renders every shipped tool's manifest and fails when a container has no `resources.requests`.
  - Retrofit the tools that lack them, with live readings for whatever is installed and upstream-documented figures otherwise.
  - This is the "finish Phase 3" item, and it is the biggest chunk of work.
- **Phase 3 leftovers:** wire the guard into the deferred installs (Plex Commons, ExternalDNS, Office, Errors, Secrets, NetBird server).

### A2. Footprint catalog (CLI, derived and never hand-curated)
- **Rendering:** a new `app/Services/K8s/ToolFootprint.php` renders each tool's manifest(s) with placeholder inputs (host `footprint.invalid`, default options), then runs `ManifestResourceParser` (`app/Services/K8s/ManifestResourceParser.php`). Per-component totals come from `ClusterTool::components()`.
  - Each vendor gets a small `footprintManifests(): array` hook returning its rendered views. The render code is shared with each `*ManifestYamlTest`, which already render these.
- **Catalog field:** `ClusterTool::toCatalogRow()` (:1107) adds
  `footprint: {cpu_millicores, memory_bytes, components: [{name, optional, cpu_millicores, memory_bytes}]}`.
  - Optional components (Grafana logs/traces, Supabase functions/realtime) are listed separately, so the UI can show "2.1 GB, or 1.4 GB without Edge Functions".
- **Alternatives:** a new `ClusterTool::alternatives(): array<ClusterTool>` lists lighter substitutes, e.g. SUPABASE → [POCKETBASE]. Seed only the obvious pairs; it is emitted in the catalog row.

### A3. `cluster:capacity` read-out (new standalone read-only CLI command)
`cluster:capacity --context=<ctx> [--tool=<tool>] [--json]`. It prints a Laravel Prompts `table()` by default, or JSON with `--json`:
- **`node`:** allocatable, used, free (after the 10% margin) and `basis`.
- **`breakdown`:** memory and CPU split into four groups:
  - **System:** k3s plus the OS, i.e. node usage minus the sum of pod usage.
  - **Platform:** `kube-system`, `traefik`, ExternalDNS, OpenBao/ESO, Plex Commons.
  - **Apps:** one row per installed Cluster Tool, by the `larakube-tool` label, with a `paused` flag.
  - **Free.**
- **With `--tool`:**
  - `demand`: from A2's footprint, honouring flags like `--no-functions`.
  - `verdict`, one of:

    | Verdict | Meaning |
    |---|---|
    | `fits` | Fits now. |
    | `tight` | Fits, but less than 15% would be left free. |
    | `wont_fit_now` | Pausing apps would make room. |
    | `never_fits` | Larger than the node's total capacity minus System and Platform. |
  - `pause_candidates`: installed, running apps, largest first, picking the fewest whose combined size covers the shortfall.
  - `alternatives_that_fit`.
  - `upsize_hint`: the smallest provider size that fits, when `cloud:scale` knows the provider's sizes. Otherwise just "upsize via `cloud:scale`".

### A4. Guard semantics (CLI), per the user's decision
- **New flag:** `--ignore-capacity` on every `*:init` / `tool:add`, defined once in `ToolInitSpec`. It is the only capacity bypass. **`--force` no longer bypasses capacity**; it still skips confirmation prompts.
  - This is a behaviour change, released as `feat(capacity)!:` (pre-v1, so a minor bump).
- **Verdict handling:**
  - `wont_fit_now` and `never_fits` → refuse. The message lists Needs / Free and the three options: the pause candidates with their `tool:pause` commands, upsize, and the alternatives.
  - `tight` → warn, then continue.
  - A failed live read still steps aside, as it does today.
- **Desktop never passes `--ignore-capacity`.**

### A5. Desktop UI
- **`SyncServerCapacityJob`** (`app/Jobs/Sync/`, same pattern as `SyncServer*Job`) runs `cluster:capacity --context=… --json` for each Ready server and caches the result on the server row.
  - Needs a migration, plus both `php artisan migrate` and `php artisan native:migrate`.
- **Server page** (`resources/js/pages/servers/show.tsx`, the memory section around :900): a stacked bar for System / Platform / Apps / Free, with numbers and a hover breakdown. This answers "why is half already used?". A short caption explains: "k3s and the OS reserve ~X before any app runs".
- **Apps catalog and Quick Launch** (`pages/tools/index.tsx`, `components/quick-launch-modal.tsx`):
  - Each app shows "Needs ~X GB RAM", taken from `footprint`.
  - Once a server is selected, a fit badge appears: Fits (green), Tight (amber) or Won't fit (red).
  - Before launch, the modal calls a new `GET /quick-actions/fit?tool=&server=&options=` endpoint, which runs `cluster:capacity --tool=… --json` live with the chosen options.
  - When the verdict is `wont_fit_now` or `never_fits`, the Launch button is **disabled** and a **"Won't fit on <server>"** panel shows Needs vs Free with three actions:
    1. **Pause apps:** lists the pause candidates, each with a Pause button using the `Pause` icon (runs `tool:pause`). The fit check runs again afterwards.
    2. **Upsize server:** an `ArrowUpCircle` button linking to the server's scale flow.
    3. **Try an alternative:** one card per alternative that fits, each with its own Quick Launch button (`Zap` icon).
- **MCP `install-cluster-tool`:** returns the same verdict and options instead of starting a run that would fail.

### A6. ADR
Record a new CLI ADR (next free number) titled "Capacity verdicts and `--ignore-capacity`". It covers effective use = max(requests, live usage), the four verdicts, the split from `--force`, and footprints derived from manifests. It finishes the ADR 0026 item left open in Phase 3.

## Verification
- **CLI:**
  - targeted tests first, then `composer test` (the full suite, zero failures);
  - the new matrix test proves every shipped tool declares requests;
  - the user runs `./build`, then `larakube cluster:capacity --context=<droplet> --json` and `--tool=supabase` against `luchtech-vps` and a 2 GB droplet;
  - the breakdown roughly matches `kubectl top nodes`;
  - on the 2 GB droplet the Supabase verdict is `never_fits`;
  - `supabase:init` on that droplet refuses with Needs / Free and the three options;
  - `--force` alone still refuses, and `--ignore-capacity` proceeds.
