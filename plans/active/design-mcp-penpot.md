# Component Plan: `design:mcp:init` — Penpot MCP (Claude design editing)

**Status:** 🔴 BLOCKED — deferred until Penpot's multi-user MCP mode ships a real (non-test) auth story and an official publishable artifact. **Revisit no earlier than 2026-09-10.**
**Created:** 2026-08-10
**Related:** `docs/decisions/0013-design-init-idempotent-flags.md` (the incident this investigation grew out of)

---

## Why this exists

`design:init` deploys Penpot (`docs/decisions/0012`-era tool). A follow-up investigation asked whether LaraKube CLI should also ship `design:mcp:init` — deploying a companion so Claude can read/edit designs in a shared, self-hosted Penpot instance, the way this project migrated off Figma + Google Stitch expecting to.

An earlier attempt to just flip Penpot's `enable-mcp` flag on `design:init`'s baseline took down `design.luchtech.dev` in production (the flag assumes a companion container that was never deployed). That incident, and the fix to the flag-reconciliation logic, is documented in ADR 0013. This plan is the *separate* follow-up: is a real, deliberate, opt-in `design:mcp:init` command worth building.

## What's actually required (verified 2026-08-10, ground-truth from the live container + source)

- Penpot's frontend nginx proxies `/mcp/*` to `PENPOT_MCP_URI`/`PENPOT_MCP_URI_WS` — fully overridable env vars (default `http://penpot-mcp:4401`/`:4402`), not a hardcoded dependency. Trivial once a real backend exists.
- "Remote MCP" is the same Node service as "local mode": npm package `@penpot/mcp` (now living in `penpot/penpot`'s own `/mcp` folder; the old standalone `penpot/penpot-mcp` repo is archived as of 2026-02-03). Package version at investigation time: `2.17.0`.
- The published npm package's default bin (`./bin/mcp-local.js`) is **local, single-user only**.
- Genuine multi-user/remote mode (`mcp/docs/multi-user-mode.md`) is real and is the correct mode — "allows multiple Penpot users to connect to the same MCP server instance simultaneously," auth via a per-user `?userToken=` matching the MCP key from Account → Integrations → MCP Server. But it requires **building from source** via the pnpm monorepo (`npm run bootstrap:multi-user`, building two separate packages: the MCP server and a "Penpot MCP plugin server"). No official Docker image. No deployment guidance published anywhere.
- Penpot's own docs: MCP shipped in v2.15.0; "using the MCP server requires running only a single instance of Penpot" (the Node process is stateful — this is not a constraint on Penpot's own backend replica count).

## Why blocked, not built

The multi-user-mode doc itself says per-user auth tokens are **"hardcoded in the plugin for testing... Future versions may auto-generate tokens via Penpot."** That's the maintainers' own words. Building `design:mcp:init` today would mean LaraKube CLI cloning and building an upstream target the maintainers call test-only, with zero deployment guidance, and no OAuth/browser-login auth (the bar set by comparison to Outline's hosted MCP — see `feedback_no_unofficial_integrations` in the operator's memory). That fails this project's standing rule: ship official artifacts with a real auth story, not "I found something that technically works."

## Revisit criteria (all three, before writing any code)

1. Auth token generation for multi-user mode is no longer described as hardcoded/test-only (real per-user token issuance via Penpot itself).
2. An officially published, directly-usable artifact exists for multi-user/remote mode — a real Docker image, or an npm package whose *default* bin supports remote mode (not a from-source monorepo build).
3. Some self-hosting deployment guidance exists (even minimal docker-compose/env-var reference) from the Penpot project itself.

If all three are true: scope `design:mcp:init` as a genuinely opt-in command — deploy the companion, health-check it before ever touching `enable-mcp`, and extend `ReconcilesPenpotFlags::resolveDesignPenpotFlags()` to include `enable-mcp` only when the companion is confirmed healthy (mirroring how OIDC/SMTP flags are already detected from live credential state, not from a static baseline — see ADR 0013). That keeps it self-correcting: tearing the companion down naturally drops the flag on the next reconcile, the same property that incident was missing.

A scheduled check (`https://claude.ai/code/routines/trig_016haZRJrZaoB88UKUAGZEqm`, fires 2026-09-10) independently re-checks Penpot's repo/docs against these three criteria and reports back — this file is the durable plan; that routine is just an automated nudge, not the source of truth.

## Full research trail

`project_penpot_mcp_status.md` in the operator's Claude memory has the complete investigation history, including the earlier (superseded) "deploy a 4th container" theory and why the ground-truth nginx/entrypoint read corrected it.
