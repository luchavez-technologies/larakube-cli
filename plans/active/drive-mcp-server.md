# Component Plan: `drive:mcp:*` — oCIS MCP Server (Claude access to Drive)

**Status:** 🟡 DEFERRED — a real MVP could be built today, but not prioritized. The "non-technical teammate, click-to-connect" upgrade is BLOCKED pending upstream OAuth support (see below).
**Created:** 2026-08-24
**Related:** `drive-office-euro-office.md` (separate plan, same tool, deliberately not merged with this one)

---

## Why this exists

Drive (`drive:init`) deploys oCIS. A follow-up question: can LaraKube CLI wire up [owncloud/ocis-mcp-server](https://github.com/owncloud/ocis-mcp-server) so Claude (or any MCP client) can manage the live oCIS instance — list/read/write files, spaces, shares — via natural language. The trigger was a direct comparison to Outline's MCP integration, where non-technical teammates get a one-click "Connect" experience with no local setup.

## What's actually required (researched 2026-08-23/24, from the project's own docs/repo)

- The server is real and official: `github.com/owncloud/ocis-mcp-server`, Apache-2.0, published by ownCloud itself, currently v1.1.0. 80 tools across users/spaces/files/shares/federated OCM.
- Targets oCIS 8.x (tested against 8.0.1/8.1.0-rc.1) — the deployed `owncloud/ocis:8.0.6` (`resources/views/k8s/drive/ocis.blade.php`) is compatible, no version bump needed for this reason.
- Auth: either an oCIS **app token** (`OCIS_MCP_APP_TOKEN_USER`/`_VALUE`, minted via `ocis auth-app create --user-name=<user> --expiration=<dur>` run inside the oCIS pod — same `kubectl exec` mechanism `InteractsWithOcisExtensions.php` already uses against `deploy/drive-ocis`) or a static OIDC access token baked in at server startup. **One identity per running server process** — there is no per-user OAuth/dynamic-client-registration flow the way Claude.ai Connectors or (per the operator's characterization) Outline's integration work. Every client talking to one server instance acts as the same oCIS account.
- Transport: **stdio** is the default and documented primary path — a client spawns the signed release binary locally and talks over stdin/stdout. HTTP transport exists (`OCIS_MCP_TRANSPORT=http`, `OCIS_MCP_HTTP_ADDR`, and — as of v1.1.0 — a required `OCIS_MCP_HTTP_SECRET` bearer secret for any non-loopback bind) but is still just one shared secret in front of one shared identity, not per-user auth.
- **No pre-built Docker image is published** — checked Docker Hub's `owncloud` org directly (only `ocis`, `ocis-accounts`, `ocis-ocs`, `server` exist). Only a signed release binary (GPG key `90CCF130D75586F0`) or build-from-source.

## Why deferred, not built

Two different asks are tangled together here and only one is cheap:

1. **"I want to use oCIS's data from my own Claude Code/Desktop."** Trivial and buildable today — a `drive:mcp:grant`/`revoke`/`show` command family that only brokers the `ocis auth-app` token via `kubectl exec` and prints a ready-to-paste local MCP client config. No new Kubernetes manifests, no image build, no CI/CD (fits `docs/decisions/` ADR 0014 — Cluster Tools get direct `kubectl apply`, never a build pipeline). This is genuinely low-effort and could be picked up independently of the item below.
2. **"My non-technical workmates should be able to connect the way they do with Outline."** This is what's actually blocked. It needs a remote, always-on HTTP server AND per-user auth so each teammate acts as themselves, not one shared admin token. oCIS MCP Server v1.1.0 has the HTTP transport but not the per-user auth — every connecting client would share one identity regardless of who's asking. There's also no published Docker image, so even the always-on part means LaraKube CLI building and hosting its own image for a tool it doesn't otherwise need to build — meaningful infrastructure for a single-operator cluster, for a payoff (shared access) that still wouldn't be per-user.

Per the operator's standing rule (ship official artifacts with a real auth story, not "I found something that technically works" — same bar applied to the Penpot MCP plan, `design-mcp-penpot.md`), item 2 stays blocked until upstream auth changes shape. Item 1 is unblocked but simply hasn't been prioritized yet.

## Revisit criteria

- **For item 1 (local/personal use):** none — buildable whenever it's prioritized. No external blocker.
- **For item 2 (shared/non-technical access):** revisit if/when `owncloud/ocis-mcp-server` adds an OAuth-based per-user auth mode to its HTTP transport (watch their release notes — the v1.1.0 changelog already shows active iteration on the HTTP transport's auth story, so this may not be far off), or if the operator decides one-shared-identity access is an acceptable tradeoff for their team's size.

## If/when item 1 gets built

- New command family `drive:mcp:grant {environment?} {--context=} {--user=admin} {--expiration=8760h}` / `drive:mcp:revoke {environment?} {--context=} {--token-id=}` / `drive:mcp:show {environment?} {--context=}` — the grant/revoke shape mirrors this repo's `cluster:grant`/`cluster:revoke` reference pattern for a real standalone op (`feedback_no_hidden_flag_commands` in the operator's memory), not a flag on `drive:init`.
- Do **not** persist the app token into OpenBao/Infisical or a k8s Secret — it's a personal MCP credential the operator copies once into their own local Claude Desktop/Claude Code config, same trust model as a GitHub personal access token, not Commons secret material.
- Verify live before writing command code: confirm `ocis auth-app create` works out of the box against the deployed `owncloud/ocis:8.0.6` image in its current headless `command: ["ocis"], args: ["server"]` boot mode (the `auth-app` service being on by default in this specific config hasn't been confirmed).

## Full research trail

`project_ocis_mcp_plan.md` in the operator's Claude memory has the complete investigation, including how the unrelated EOSC blog post and `delmarguillen/euro-office-ocis` repo got ruled out along the way.
