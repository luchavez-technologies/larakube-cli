# 0031 — Capacity verdicts and `--ignore-capacity`

**Status:** Accepted (2026-11-19)

## Context

ADR 0026 introduced the pre-install capacity guard, but three gaps remained:

1. **Pod requests alone understated memory use.** On a fresh 2 GB node, k3s, the OS, and unrequested pods consume nearly half of RAM outside pod `resources.requests`.
2. **`--force` conflated prompt skipping with ignoring capacity.** Desktop and automation callers pass `--force` to avoid interactive prompts, which silently bypassed the capacity guard and let oversized installs schedule onto nodes that could not hold them.
3. **No read-only breakdown or catalog footprint existed.** Operators could not see how much RAM went to System vs Platform vs Apps, nor could Desktop or the CLI suggest pausing running apps or picking a lighter alternative.

## Decision

1. **Effective memory use = `max(pod requests, live node usage)`** read from `metrics.k8s.io` (`basis: 'usage'`), falling back to pod requests (`basis: 'requests'`) when metrics are unavailable.
2. **Manifest-derived footprints (`ToolFootprint`)** render each shipped tool's Blade manifest(s) with placeholder inputs and parse them with `ManifestResourceParser`, exposing `footprint` and `alternatives` in `ClusterTool::toCatalogRow()`.
3. **`cluster:capacity` (`ClusterCapacityAssessor`)** splits consumption into **System**, **Platform**, **Apps**, and **Free**, and classifies candidate tools into four verdicts:
   - `fits`: fits within free headroom with at least 15% of usable memory remaining.
   - `tight`: fits now, but leaves less than 15% of usable memory free; warns and continues.
   - `wont_fit_now`: does not fit now, but pausing running apps would reclaim enough room; refuses and lists `pause_candidates` + `alternatives_that_fit` + `upsize_hint`.
   - `never_fits`: exceeds `usable - (system + platform)`; refuses and lists `alternatives_that_fit` + `upsize_hint`.
4. **`--ignore-capacity` is the sole capacity bypass.** `--force` skips interactive confirmations only and no longer overrides capacity refusals. Desktop never passes `--ignore-capacity`.

## Consequences

- `tool:init` / `*:init` and Desktop Quick Launch / MCP stop oversized installs before pods enter `Pending` or trigger OOM evictions.
- Breaking behavior change from ADR 0026 (`--force` no longer bypasses capacity), released as `feat(capacity)!:` (pre-v1 minor bump).
