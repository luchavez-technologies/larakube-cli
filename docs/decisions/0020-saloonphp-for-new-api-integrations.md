# 0020 — New external API integrations use SaloonPHP, not the `Http` facade

**Status:** Accepted (2026-08-22)

## Context

External API wrappers in this codebase (`InteractsWithCloudflareApi.php`,
`InteractsWithZitadelApi.php`, R2 bucket calls in `InteractsWithBackup.php`, and
others) have grown as ad-hoc `Http::withToken($token)->timeout(15)->get(...)` calls
scattered inline inside trait methods — one string URL, one inline query array, one
inline auth call, repeated at every call site for the same external service. Nothing
ties "here is what a Cloudflare zone-lookup request looks like" to a single,
reusable, typed place; the shape is re-derived by hand everywhere it's needed.

`saloonphp/saloon` (v4) + `saloonphp/laravel-plugin` (v4.3) are now installed.
Saloon gives each external service a **Connector** (base URL, shared auth, shared
config — one per service) and each endpoint a **Request** class (method, path,
query/body shape — one per endpoint), both reusable and independently testable.
Its Laravel integration's `Saloon::fake([RequestClass::class => MockResponse::make(...)])`
mirrors `Http::fake()`'s ergonomics closely enough that it doesn't disrupt this
repo's existing test-fidelity conventions (`docs/decisions/0019-test-fidelity-conventions.md`)
— tests still fake every outbound call, they just key on a Request class instead of
a URL wildcard pattern.

Confirmed live (2026-08-22) while migrating `InteractsWithCloudflareApi.php`'s
`cloudflareZoneId()` to Saloon: a manual client-library swap is easy to get subtly
wrong in a way neither client catches for you. The rewrite dropped Cloudflare's
envelope-level `"success": false` check — Cloudflare's v4 API wraps every response
in `{success, errors, messages, result}` and can return **HTTP 200 with
`success: false`** for certain error conditions, so `$response->failed()` alone
(HTTP status only, true of both Saloon's and Laravel's `Http` client) is not
sufficient on its own; both this repo's pre-existing `cloudflareUpsertTxtRecord()`
and the fixed `cloudflareZoneId()` check `Arr::get($data, 'success') !== true`
explicitly. This is a Cloudflare-API-shape issue, not a Saloon-specific gotcha — but
it's exactly the kind of check a client-library swap silently drops if nothing
forces you to re-derive it, so any future migration of another envelope-wrapped
API (Zitadel's is the same `{success/result}`-free-but-similar shape) needs the same
explicit re-check, not an assumption that porting the HTTP call ports the
correctness with it.

## Decision

1. **New** external API integrations are built with Saloon, not `Http::`. Existing
   `Http::`-based wrappers are migrated incrementally, as they're touched for other
   reasons — this is not a mandate to rewrite `InteractsWithZitadelApi.php` (the
   largest `Http::` consumer, ~20+ calls) or `InteractsWithBackup.php`'s R2 calls in
   one pass. `InteractsWithCloudflareApi.php` is the first, partial example:
   `cloudflareZoneId()` is Saloon-based; `cloudflareListZones()` and
   `cloudflareUpsertTxtRecord()` are next as this session's DNS multi-zone work
   continues, not both already done as of this ADR.
2. **File structure**: `app/Http/Integrations/{ServiceName}/{ServiceName}Connector.php`,
   with request classes under `app/Http/Integrations/{ServiceName}/Requests/` — the
   package's own shipped convention (`vendor/saloonphp/laravel-plugin/resources/boost/skills/saloon-development/SKILL.md`),
   already followed by `app/Http/Integrations/Cloudflare/`.
3. **Generate new classes via the CLI's own binary, not `php artisan`**: this is
   Laravel Zero, not plain Laravel — the equivalent commands are
   `./larakube saloon:connector <Integration> <ConnectorName>` and
   `./larakube saloon:request <Integration> <RequestName>` (confirmed live
   2026-08-22; the package's own SKILL.md defaults to `php artisan`, which does not
   apply here).
4. **Auth**: use Saloon's built-in `Authenticator` implementations
   (`TokenAuthenticator` for Cloudflare's bearer-token API tokens) via a
   Connector's `defaultAuth()` hook, rather than re-adding an `Authorization` header
   by hand per request.
5. **Testing**: `Saloon::fake([RequestClass::class => MockResponse::make($body,
   $status)])`, with `MockClient::destroyGlobal()` in `afterEach()` so a fake
   registered in one test never leaks into the next (parallel-safe, matching
   `pest --parallel`'s existing shared-state discipline). Assert calls happened
   with `Saloon::assertSent(RequestClass::class)` (or the closure form — see
   `docs.saloon.dev/the-basics/testing` before relying on closure-accessible
   request internals; `$request->query()`/`->headers()` accessors did not behave
   as expected against a `defaultQuery()`-populated Request in this session's own
   testing and needed the simpler class-based assertion instead).
6. **Envelope-shape checks migrate explicitly, not implicitly**: any endpoint whose
   API wraps responses in its own success/error envelope (Cloudflare's
   `{success, result, errors}`, Zitadel's own shape if/when that's migrated) must
   check that envelope explicitly in the ported code — `$response->failed()`/
   `->successful()` on any HTTP client only ever reflects the HTTP status code, never
   the API's own envelope semantics.

## Consequences

- `InteractsWithCloudflareApi.php` (and any future Saloon-migrated trait) ends up
  with two call-site shapes side by side during the incremental migration — some
  methods calling `Connector::make($token)->send(Request::make(...))`, others still
  calling `Http::withToken($token)->get(...)` directly — until each is individually
  migrated. This is expected and acceptable, not a "half-finished" state to rush to
  close.
- New tests for Saloon-based code use `Saloon::fake()`/`MockClient`, not
  `Http::fake()` — a test file touching a Saloon-migrated method needs the Saloon
  facade imported, not (only) `Illuminate\Support\Facades\Http`.
- `v3` is explicitly not an option here — the package's own shipped guidance flags
  three published security issues against Saloon v3; this repo is already on v4,
  which has none, so this is a non-issue in practice but worth stating for whoever
  next looks at pinning/upgrading this dependency.
