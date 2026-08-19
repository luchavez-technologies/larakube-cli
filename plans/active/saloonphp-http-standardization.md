# Plan: Standardize external HTTP calls on SaloonPHP

**Status:** Draft / Proposed
**Created:** 2026-08-19
**Updated:** 2026-08-19
**Target Version:** TBD (post v1.0.0)

---

## Context

As of this session, every external HTTP integration (Zitadel, Cloudflare, Matrix/Synapse admin API, OpenBao, R2, oCIS, NetBird VPN) is a raw `Http::withToken(...)->timeout(15)->post(...)` call, hand-written per method inside a trait (`InteractsWithZitadelApi`, `InteractsWithCloudflareApi`, `InteractsWithMatrixApi`, `InteractsWithSecrets`, `InteractsWithBackup`, `InteractsWithOcisExtensions`, `InteractsWithVpn`). Surveyed today: **76 `Http::` call sites across ~10 files**, ~9 distinct external APIs. No shared base for auth injection, retries, timeouts, or error normalization — every trait repeats the same `Http::withToken($pat)->timeout(15)->...` boilerplate and rolls its own failure handling.

Now that the app is on Laravel 13 (this session's Pest 5 upgrade), the framework's own HTTP client is Guzzle-based and PSR-7 compliant — the same foundation SaloonPHP builds on (`saloonphp/saloon ^4.0` requires `php ^8.2`, `guzzlehttp/guzzle ^7.6`, PSR-7 — no version conflict with the current stack). This makes now a reasonable time to evaluate it, not because Laravel 13 unlocks anything specific, but because it removes any doubt about compatibility.

**Goal:** replace the hand-rolled `Http::` boilerplate with SaloonPHP's `Connector`/`Request` model — one `Connector` per external service (auth, base URL, default headers, retry policy defined once), one `Request` class per endpoint — without regressing behavior or losing test coverage.

## Why this is a real, non-trivial cost — not just a swap

SaloonPHP's testing model (`MockClient`/`MockResponse`, `assertSent()`) is **not** `Http::fake()` — it's a separate mocking system. Every existing test that fakes one of these APIs must be rewritten, not just the production code. This session alone added ~15 tests across `SsoOrgCommandTest.php`, `SsoOrgGrantCommandTest.php`, `ChatUserCommandTest.php`, `ChatRoomCommandTest.php` using `Http::fake()` against Zitadel/Cloudflare/Matrix — all of those would need a second pass under a Saloon migration. This is the main reason this is a **plan**, not a same-session change: it's a real, multi-file, test-touching migration, not a drop-in.

## Current `Http::` surface (surveyed 2026-08-19)

| File | External API | Rough call count |
|---|---|---|
| `app/Traits/InteractsWithZitadelApi.php` | Zitadel (v1 Management API + v2 User Service) | ~30 |
| `app/Traits/InteractsWithCloudflareApi.php` | Cloudflare DNS v4 | ~4 |
| `app/Traits/InteractsWithMatrixApi.php` | Matrix Client-Server + Synapse Admin API | ~7 |
| `app/Traits/InteractsWithSecrets.php` | OpenBao (via port-forward, `http://localhost:{port}`) | ~5 |
| `app/Traits/InteractsWithBackup.php` | Cloudflare R2 | a few |
| `app/Traits/InteractsWithOcisExtensions.php` | oCIS extension registry | a few |
| `app/Traits/InteractsWithVpn.php` | NetBird | a few |
| `app/Commands/Sso/SsoWireCommand.php`, `UpdateCommand.php`, `Vpn/VpnInitCommand.php` | inline, not trait-hosted | a few each |
| `app/Traits/InstallsK3s.php` | file download, not really an "API" — likely stays as raw `Http::` (Saloon's Connector/Request model doesn't add much for a single anonymous GET) |
| `app/Traits/HasConsoleInteraction.php`, `LaraKubeOutput.php` | needs investigation — unexpected to see `Http::` here, check what these actually call before assuming they belong in scope |

Zitadel is both the biggest win (most repeated boilerplate, most auth-header duplication) and the biggest test-rewrite cost (most existing `Http::fake()`-based tests).

## Proposed phased approach

**Phase 0 — spike, no production code changes.** Install `saloonphp/saloon` + `saloonphp/laravel-plugin` in `require-dev` only (not `require`) initially. Build ONE real connector — Cloudflare (smallest surface, only 2 traits, ~4 methods) — as a proof of concept, migrate its 2-3 existing tests to `MockClient`, and confirm the pattern feels right before committing to anything bigger. Bail here if it doesn't pull its weight.

**Phase 1 — Zitadel connector.** The highest-value target: one `ZitadelConnector` (base URL + PAT auth resolved once) with `Request` classes per endpoint group (org, project, grant, user, action/flow). This directly replaces `InteractsWithZitadelApi.php`'s ~30 methods. Rewrite `SsoWireCommandTest.php`, `SsoGrantCommandTest.php`, `SsoOrgCommandTest.php`, `SsoOrgGrantCommandTest.php`, `MailCommandsTest.php`'s SSO-identity tests, and any other Zitadel-touching test to `MockClient`. This is the biggest single phase — budget it as its own dedicated session, not a quick pass.

**Phase 2 — Matrix/Synapse connector.** Second-biggest surface, added this session (`InteractsWithMatrixApi.php`). Includes the shared-secret-registration bootstrap flow (`matrixAdminToken()`), which has real branching logic (register vs. login fallback) worth keeping outside the Connector itself as an orchestration method that calls two Requests.

**Phase 3 — remaining smaller surfaces** (OpenBao, R2, oCIS extensions, NetBird) as time allows, likely combinable into one session given their smaller size individually.

**Explicitly out of scope for now:** `InstallsK3s.php` (single anonymous download, not a real "API"), and anything in `HasConsoleInteraction.php`/`LaraKubeOutput.php` until we understand why they call `Http::` at all — investigate those two first as a 10-minute check before Phase 0, since they may be miscategorized (dead code, a stray import) rather than real Http:: usage.

## Open questions to resolve before Phase 0 starts

- Does Saloon's fixture-recording (`MockResponse::fixture()`) fit this codebase's "no live-instance dependency in CI" testing philosophy, or should every test stay on manual `MockResponse::make()` (mirroring today's `Http::fake()` manual-response style)? Recommend manual-only, at least initially — fixtures recorded against real Zitadel/Cloudflare/Synapse instances risk baking in this session's `luchtech.dev`-specific values into committed test fixtures (see `feedback_no_real_partner_names_in_code` — same category of risk, different data).
- Does `Config::preventStrayRequests()` (Saloon's real-request guard) get enabled globally in `tests/Pest.php`, mirroring the existing `Process::fake()` discipline documented in `CLAUDE.md`'s "Writing Tests" section? Recommend yes — same rationale (a stray real request in a test should fail loudly, not hang or hit a real API).
- Auth token storage: Saloon's `Connector`-level auth (e.g. `TokenAuthenticator`) assumes a token available at Connector-construction time. Several of this codebase's traits currently resolve the auth token from a **live kubectl `get secret` call** at call time (e.g. `readSsoSecret($kubectl, $ns, 'machine-pat')`), not from config — the Connector's constructor needs to accept that resolved token, not try to fetch it itself, to keep the trait's existing `$kubectl`-scoped multi-cluster/multi-context model intact.

## Verification

Each phase: `./vendor/bin/pint && ./vendor/bin/phpstan && ./vendor/bin/pest` must stay green, plus a live smoke-test of at least one real command per migrated connector against the actual cluster (`larakube-159.89.205.239`) before considering that phase done — mirroring the live-verification discipline used for the JMAP/Zitadel/Matrix API shapes discovered this session.
