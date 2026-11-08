# "Sign in with X" for DigitalOcean + Cloudflare (OAuth, no pasted tokens)

## Context

LaraKube currently has one real "Sign in with Google" experience for GCP, but it isn't LaraKube's own OAuth — it's a UX wrapper around `gcloud auth login` (Google's own first-party CLI/OAuth client). Every other provider (DigitalOcean, Cloudflare, AWS, Hetzner, GitHub, GitLab, Docker Hub, Forgejo) requires the user to manually copy-paste an API key/token into a text prompt or form field.

The user is planning LaraKube Cloud and wants connecting a cloud account to feel as easy as a modern SaaS's OAuth connect button, everywhere it's realistically possible. Verified live today (2026-11-09): both **Cloudflare** (self-managed OAuth, launched 2026-06-03) and **DigitalOcean** (long-standing OAuth app model) now support a **secretless, native-app flow** — Authorization Code + PKCE with `token_endpoint_auth_method: none` — which is exactly the shape a CLI/Desktop app needs, with **no LaraKube-run backend/broker required**. (Confirmed there is no existing "LaraKube Cloud" backend in either repo today — that product is still a plan, not shipped code, so this has to be built as a fully local, client-side OAuth flow.)

Decisions already made with the user:
- **Scope**: build DigitalOcean + Cloudflare now. GitHub/GitLab PAT replacement (via Device Flow) and AWS IAM Identity Center/SSO login are documented as future candidates, not designed in detail here.
- **Flow style**: browser loopback-redirect (CLI opens the real browser to the provider's consent screen; a short-lived local HTTP listener catches the redirect automatically) — true one-click, no code to copy/paste, unlike the existing GCP/NetBird device-code pattern.

## Existing patterns to build on

- **UX precedent (not the technical template)**: `cli/app/Commands/Cloud/CloudLoginCommand.php` (`cloud:login --provider=gcp`) — TTY → `runInteractive("{$gcloud} auth login --update-adc")`; `--json`/no-TTY → `runWithStdinLine(...)` fed by `cli/app/Services/Cloud/StdinRelay.php`. Desktop mirrors it via `desktop/app/Services/GcpSignIn.php` (starts the CLI as a background `Run` via `CliRunner`, regexes stdout for the Google auth URL, polls state, `submitCode()` writes the code to the child's stdin) + `desktop/app/Http/Controllers/CloudAuthController.php` + `desktop/resources/js/components/gcp-sign-in.tsx`.
- **Real OAuth Device Grant already built in-house** (different flow shape, but the right reference for request/polling code): `cli/app/Commands/Vpn/VpnSsoLoginCommand.php` + `cli/app/Http/Integrations/NetbirdIdp/Requests/{DeviceCodeRequest,DeviceTokenRequest}.php` — RFC 8628 against the embedded Zitadel/Dex IdP.
- **Browser-open logic exists but is duplicated, not shared**: `app/Commands/ConsoleCommand.php:148-151` and `app/Commands/Traefik/DashboardCommand.php:64-66` both inline the same `match(PHP_OS_FAMILY) { 'Darwin' => 'open', 'Windows' => 'start', default => 'xdg-open' }` → `Process::run(...)`. Worth extracting into a shared helper while touching this area.
- **Token storage chokepoint**: `cli/app/Traits/InteractsWithGlobalConfig.php` — `getDoToken()`/`setDoToken()`, `getCloudflareToken()`/`setCloudflareToken()` (checks transient flag → env var → `GlobalConfigData`, in that order). Tokens flow to OpenTofu via env (`cli/app/Traits/InteractsWithOpenTofu.php` ~338-344: `TF_VAR_do_token`, `HCLOUD_TOKEN`). `ensureDoToken()` in `cli/app/Commands/Cloud/CloudCreateCommand.php` is the interactive prompt call site. Desktop mirrors this in `desktop/app/Services/LaraKube/GlobalSettings.php` (`CLOUD_PROVIDERS` const) and its own account UI, `desktop/resources/js/components/cloud-accounts-modal.tsx` (API-token field for `do`/`hetzner`, ~line 496).
- **Cloudflare entry points**: `cli/app/Traits/InteractsWithCloudflareApi.php` + `ChecksCloudflareProxy.php`/`ReadsStoredCloudflareTokens.php` (verifies via `/user/tokens/verify`); Desktop `CloudflareController::verifyToken()` + `CloudflareStepRequest.php` in onboarding/DNS screens.
- **Later-stage, unaffected by this plan**: `cli/app/Traits/SyncsClusterSecrets.php` (`pushClusterSecret`, `syncClusterSecretToNamespace`) pushes already-obtained secrets into OpenBao/ExternalSecrets once a cluster exists — takes a plain `$key, $value`, so it needs no changes now regardless of how the token was obtained.

## Implementation plan

### 1. Core engine — `OAuthLoopbackClient`

New, provider-agnostic, under `cli/app/Services/Cloud/`:
- `OAuthLoopbackClient.php` — orchestrates the flow (see below).
- `OAuthProviderConfig.php` — small DTO: `authorizeUrl`, `tokenUrl`, `scopes`, `clientId` per provider.
- `Pkce.php` — `verifier()` / `challenge()` (S256), pure functions.
- `LoopbackHttpServerInterface.php` + `LoopbackHttpServer.php` — `listen(): int` (binds `127.0.0.1:0`, a tiny single-request HTTP parser via `stream_socket_server`, no new dependency), `awaitCallback(int $timeoutSeconds): ?array` (returns `['code' => ..., 'state' => ...]` or `null` on timeout, replies with a minimal "you can close this tab" HTML page), `close(): void`. **This interface exists specifically so tests can swap in a fake** — the real socket/browser can't be meaningfully `Process::fake()`'d.
- Per-provider Saloon connectors mirroring `NetbirdIdpConnector`'s shape: `cli/app/Http/Integrations/DigitalOcean/` and `.../Cloudflare/`, each with `TokenExchangeRequest` (`grant_type=authorization_code`, `code`, `redirect_uri`, `client_id`, `code_verifier` — **no client_secret**, that's the point of PKCE) and `RefreshTokenRequest`.

`authorize(OAuthProviderConfig): OAuthTokenResult` flow: generate PKCE verifier/challenge + a random `state` (CSRF, checked via `hash_equals`, never persisted) → start the loopback listener → build the authorize URL → open it in the real browser (reuse the extracted `ConsoleCommand`/`DashboardCommand` open-URL logic) → `$this->withSpin('Waiting for you to finish in the browser...', ...)` around `awaitCallback(120)` → verify `state` → exchange code for tokens → return access/refresh token + expiry. Falls back to printing the URL for manual copy/paste if the OS-level `open`/`xdg-open` call fails, rather than hard-failing.

### 2. CLI command surface

- Extend `CloudLoginCommand.php`: `select()` choices and provider guard grow to `gcp | do | cloudflare`. For `do`/`cloudflare`, call `OAuthLoopbackClient::authorize(...)` via a new trait, persist the result, emit the same `--json` contract (`['success'=>true,'provider'=>'do']`) the gcp branch already uses — this is what lets Desktop drive it identically to `GcpSignIn`, with no code-paste analog needed at all.
- `ensureDoToken()` (and the Cloudflare equivalent) keep the existing manual-token/flag/env path **unchanged** as the explicit fallback for CI/headless/`--no-interaction` contexts (OAuth access tokens are typically short-lived — not a good fit for long-running CI). When interactive with no token/flag/env present, offer `select()`: "Sign in with DigitalOcean (browser)" (default) vs. "Paste an existing API token."

### 3. Desktop integration

Mirror `GcpSignIn.php` → new `DoSignIn.php` / `CloudflareSignIn.php`, simplified (no `submitCode()`/code-paste state, since loopback has nothing for the user to type): states collapse to `starting | waiting-browser | done | failed`. Extend `CloudAuthController.php` with `loginDo`/`doLoginStatus` and `loginCloudflare`/`cloudflareLoginStatus` (exact mirrors of `loginGcp`/`gcpLoginStatus`, skip the `gcpLoginCode` analog). New `do-sign-in.tsx` / `cloudflare-sign-in.tsx` components, trimmed copies of `gcp-sign-in.tsx` with the code-input block removed. Wire into `cloud-accounts-modal.tsx`: OAuth "Connect with DigitalOcean" button as primary CTA for `do`, existing paste-a-token form demoted to a secondary "Use a token instead" disclosure (Hetzner unaffected — no OAuth path exists for it). Same pattern for whatever component backs `CloudflareStepRequest.php`/`verifyToken()` — locate it in `resources/js` at implementation time.

This respects Desktop's existing ADRs as-is (0001: Desktop only drives the CLI as a subprocess, never talks OAuth itself; 0003: reuses the same single-JSON-line run contract) — no ADR changes required, though recording this as a new short ADR is a nice-to-have if the team wants it on record.

### 4. Token storage, multi-account identity, and silent refresh

**OAuth sign-in writes into the existing multi-account registry (`cloudAccounts`), not the single `doToken`/`cloudflareToken` slot.** `setDoToken()` only manages one "default" slot, auto-creating an entry solely when the list is empty — that's the single-token path `ensureDoToken()`'s manual-paste fallback still uses. OAuth needs to support what the manual `cloud:accounts --add` flow already does (multiple named accounts), but without making the user invent a name.

**Dedupe key = the provider's own account identity, not something LaraKube asks for.** Immediately after the token exchange, call the provider's own identity endpoint — DO: `GET /v2/account` (returns `email`, `uuid`); Cloudflare: its account-scoped equivalent — and use that `uuid`/account id as the entry's stable key, with the email as the auto-filled display name (e.g. "DigitalOcean — jamescarlo@..."). Concretely, extend each `cloudAccounts['do'][]` entry with an optional `providerAccountId` field:

```php
/**
 * @var array<string, list<array{
 *   id: string, name: string, token: string, default: bool, createdAt: string,
 *   authMethod?: 'token'|'oauth', providerAccountId?: string,
 *   refreshToken?: string, expiresAt?: string, // only present when authMethod === 'oauth'
 * }>>
 */
```

New method on `GlobalConfigData`, e.g. `upsertOAuthCloudAccount(string $provider, string $providerAccountId, string $name, OAuthTokenResult $result): void`:

- Look up an existing entry in `cloudAccounts[$provider]` by `providerAccountId`.
- Found → update its `token`/`refreshToken`/`expiresAt` in place (re-authenticating the same account refreshes it, never duplicates it).
- Not found → append a new entry (`default: true` only if the list was empty before this call, matching `setDoToken()`'s existing first-account rule).

**Desktop UX implication (for the later Desktop-integration phase, not blocking now):** "Connect with DigitalOcean" becomes "+ Connect another DigitalOcean account" once one exists — same OAuth flow, triggered again. Which DO identity gets authorized is the browser's own logged-in session/account-switcher; LaraKube has no control over that part, same limitation GCP sign-in already has today.

**Refresh stays per-account-entry**, not global: `getDoToken()`/`ensureDoToken()` already resolve through `getDefaultCloudAccount('do')` first. Add the refresh check at that resolution point — if the resolved account entry has `authMethod === 'oauth'` and its `expiresAt` is within 5 minutes of now, silently call `refreshDoToken($account)` (via `RefreshTokenRequest`, using that specific entry's `refreshToken`) and update that same entry in place before returning the token — on refresh failure, fall through to the existing re-prompt/fail behavior unchanged. This keeps every downstream consumer (`tofuEnv()`, API calls) working against "just a token" with no other call-site changes needed, and correctly refreshes only the account actually being used for a given `cloud:create`/`--do-account=` run, not every stored account.

### 5. One-time setup LaraKube's team must do (infra, not code)

| Provider | Action | Note |
|---|---|---|
| Cloudflare | Dashboard → *Manage account → OAuth clients → Create client*, set `token_endpoint_auth_method: none`, complete one-time domain verification to make the client **public** (any Cloudflare customer can use it, not just LaraKube's own account members). | Confirm at implementation time whether Cloudflare's redirect-URI validation accepts a wildcard loopback port, or needs a fixed port/port list. |
| DigitalOcean | Prefer Dynamic Client Registration (`POST /register` against `cloud.digitalocean.com/.well-known/oauth-authorization-server`) for a no-secret public client; fall back to classic control-panel registration (API → OAuth Applications) if DCR doesn't issue a stable reusable `client_id`. | Classic registration historically issues a secret too — unused/discarded under PKCE, but confirm current DO UI exposes a public/no-secret option. |
| Both | Decide the exact scope list Cloudflare's newer OAuth scope model maps to (token verify, zone list, DNS record CRUD, zone settings read) — this surface is only 5 months old and may not 1:1 match classic API-token permission groups. | |

Both resulting `client_id` values are **not secrets** — safe to compile into the shipped CLI binary as constants, centralized for easy rotation.

### 6. Verification

- **Unit**: `Pkce` against RFC 7636 test vectors — no faking needed.
- **`OAuthLoopbackClient`**: bind `LoopbackHttpServerInterface` to a `FakeLoopbackHttpServer` in tests (pre-programmed to return a fixed code/state, a CSRF-mismatch state, or `null` for timeout), combined with `Http::fake()` on the token-exchange endpoint — full `authorize → exchange` pipeline testable with zero real sockets.
- **`cloud:login --provider=do --json`**: `Http::fake()` + the fake loopback server; assert the JSON result and that `setDoToken()`/`setDoRefreshToken()`/`setDoTokenExpiresAt()` landed correctly. Check for an existing `CloudLoginCommandTest.php` to extend before creating a new file.
- **Refresh branch**: `Http::fake()` the refresh endpoint, `Carbon::setTestNow()` to simulate near-expiry, assert silent refresh + persisted new expiry, and the fail-closed path when the refresh call itself fails.
- **Desktop**: `DoSignIn`/`CloudflareSignIn` service tests mirroring whatever `GcpSignIn` already has (locate its test file first); controller tests for the `abort_unless($run->subject === ...)` guard.
- **Real end-to-end (manual, once per provider before shipping)** — the local socket + real OS browser launch can't be meaningfully faked: run `larakube cloud:login --provider=do` for real, confirm browser opens → consent → redirect → success → token/refresh/expiry saved; repeat for Cloudflare; kill the tab mid-flow and confirm a clean timeout; run the same through Desktop's new sign-in drawer; force an expired token and confirm silent refresh; revoke the refresh token on the provider's own dashboard and confirm graceful re-prompt instead of a crash; confirm `--no-interaction` never attempts to open a browser.

## Future candidates (not designed in detail here)

- **GitHub/GitLab**: replace the PAT prompts in `cli/app/Traits/ConfiguresCloudEnvironment.php` (~lines 700-757, covering GHCR/Docker Hub/GitLab/Forgejo registry login) with an OAuth Device Flow — the existing `VpnSsoLoginCommand`/`NetbirdIdp` device-grant code is the direct template to copy (not the loopback mechanism built here; a code-to-type fits a registry-login prompt better than a mid-command browser pop). Docker Hub and self-hosted Forgejo likely keep manual tokens regardless (no broad third-party OAuth app model for either).
- **AWS**: replace the raw Access Key ID/Secret prompts in `cli/app/Traits/InteractsWithAws.php` (~line 208) by wrapping `aws sso login` (IAM Identity Center), the AWS-flavored equivalent of how GCP sign-in already just wraps `gcloud auth login`.
- **Hetzner**: no realistic OAuth path — no user-facing OAuth app model exists. Stays token-only.

## Blocking prerequisite

Section 5's OAuth client registrations with Cloudflare and DigitalOcean are dashboard actions only a human with account access can do — not something an agent can perform. Implementation of the core engine (section 1) can proceed with placeholder `client_id` constants, but end-to-end verification (section 6's manual checklist) is blocked until real `client_id`s exist.

## Progress (2026-11-09)

**CLI-side work for both DigitalOcean and Cloudflare is complete and tested** (desktop integration deliberately held back — Antigravity has concurrent, unrelated work in progress on the `desktop` repo). Built:

- `cli/app/Services/BrowserOpener.php`, `cli/app/Services/Cloud/{Pkce,OAuthProviderConfig,PendingOAuthLogin,OAuthTokenResult,LoopbackHttpServerInterface,LoopbackHttpServer,FakeLoopbackHttpServer,OAuthLoopbackClient}.php`, `cli/app/Exceptions/OAuthStateMismatchException.php`.
- `cli/app/Http/Integrations/DigitalOcean/DigitalOceanOAuthConnector.php` + `Requests/{TokenExchangeRequest,RefreshTokenRequest}.php`, plus `Requests/GetAccountRequest.php` added to the existing `DigitalOceanConnector` integration.
- `cli/app/Http/Integrations/Cloudflare/CloudflareOAuthConnector.php` + `Requests/{TokenExchangeRequest,RefreshTokenRequest}.php`, plus `Requests/GetUserRequest.php` added to the existing `CloudflareConnector` integration.
- `GlobalConfigData::upsertOAuthCloudAccount()` (ADR 0029) — Cloudflare was migrated onto the same `cloudAccounts` multi-account registry DO/Hetzner already use (it previously had none at all; `getCloudflareToken()`/`setCloudflareToken()` now resolve through the registry the same way DO's do).
- `CloudLoginCommand` extended to `--provider=do|cloudflare`, sharing one `performOAuthLogin()` orchestration method parameterized per provider (token exchange + identity-fetch closures) rather than duplicating the flow twice.
- `app/Providers/AppServiceProvider.php` binds `LoopbackHttpServerInterface → LoopbackHttpServer` — the seam that makes the whole command testable without a real socket/browser.
- Tests: `PkceTest`, `LoopbackHttpServerTest` (real-socket integration test — the one piece intentionally not faked), `OAuthLoopbackClientTest`, `DigitalOceanOAuthTest`, `CloudflareOAuthTest`, `GlobalConfigOAuthCloudAccountsTest`, `GlobalConfigCloudflareAccountsTest`, and `CloudLoginCommandTest` extended with both providers' full happy-path/refresh/timeout/CSRF-mismatch cases. Full suite: 3549/3553 passing (4 pre-existing skips), pint clean.

**Not yet done:** the silent-refresh branch in `ensureDoToken()`/an `ensureCloudflareToken()` equivalent (plan §4's refresh-on-resolution logic — storage already supports it via `refreshToken`/`expiresAt` fields, just not wired into the token-resolution call sites yet), and everything in section 3 (Desktop integration).

## How to test this after rebuilding the CLI

The OAuth client IDs are still placeholders (`DO_OAUTH_CLIENT_ID_PLACEHOLDER` / `CLOUDFLARE_OAUTH_CLIENT_ID_PLACEHOLDER` in `CloudLoginCommand.php`), so a real end-to-end browser test will fail at the token-exchange step (the provider will reject an unregistered client) until section 5's registration happens. Until then:

1. **Rebuild**: `./php vendor/bin/pint && ./build` (per your own workflow — this agent doesn't run `./build`).
2. **Dry-run the mechanics without a real provider account** — confirms the browser opens and the local listener works, without needing a registered client:
   ```sh
   larakube cloud:login --provider=do
   ```

   Expect: a browser tab opens to `cloud.digitalocean.com/v1/oauth/authorize?...` with a `client_id` you'll recognize as the placeholder, and the CLI prints "Waiting for you to finish in the browser...". DigitalOcean will show its own "unknown client" error on that page — that confirms the local redirect listener and browser-launch machinery work; it's the expected outcome pre-registration. Ctrl+C to exit, or let it time out after 2 minutes to confirm the timeout path too (`No browser response after 2 minutes...`).
   Same command with `--provider=cloudflare`.
3. **Once section 5's real registration exists**, override the placeholder without rebuilding: export `LARAKUBE_DO_OAUTH_CLIENT_ID=<real id>` (and/or `LARAKUBE_CLOUDFLARE_OAUTH_CLIENT_ID=<real id>`) in your shell, then re-run step 2 — this time the consent screen should be real, approving it should redirect back automatically with no code to paste, and the CLI should print `✅ Signed in to DigitalOcean as <your email>.`
4. **Verify storage**: `cat ~/.larakube/config.json | python3 -m json.tool` (or `jq .cloudAccounts`) — look for a `do`/`cloudflare` entry with `authMethod: "oauth"`, a `providerAccountId` matching your real account, and `refreshToken`/`expiresAt` populated.
5. **Verify multi-account dedup**: run `cloud:login --provider=do` again while signed into the *same* DO account in your browser — confirm the stored entry's `token` changed but no second entry appeared (`jq '.cloudAccounts.do | length'` should stay `1`). If you have a second DO account, sign out of DO in the browser, sign into the other account, re-run, and confirm a *second* entry now exists with a different `providerAccountId`.
6. **Verify it actually provisions**: `larakube cloud:create --provider=do` (or whatever flow calls `ensureDoToken()`) should pick up the OAuth-obtained token with no prompt, since `getDoToken()` already resolves through the same account registry.
7. Once the real client ID is confirmed working, bake it into `CloudLoginCommand.php`'s placeholder constants (replacing the env-var workaround) in a follow-up commit, so a fresh install doesn't need the env var at all.

## Desktop UI placement (planned, not yet built — see "Progress" above for why)

Two separate surfaces need the OAuth option, because the two providers enter the UI through different existing flows:

### DigitalOcean → `cloud-accounts-modal.tsx`

This is the **same modal that already has the GCP OAuth pattern to copy** (`desktop/resources/js/components/cloud-accounts-modal.tsx`, opened from wherever a provider's account list is managed — the DO/Hetzner "Add Account" tab currently starts at its `activeTab === 'add' && (provider.slug === 'do' || provider.slug === 'hetzner')` block, ~line 494-572).

- **Split DO out of the shared DO/Hetzner block.** Hetzner has no OAuth path (ADR 0029 still applies — it just means Hetzner stays single-mechanism), so it keeps the bare token form as-is. DO gets its own block, structured exactly like the GCP one immediately below it (~line 712+): a two-button method switcher header — "DigitalOcean Account (OAuth)" *(default/first, matching the "OAuth over paste" wherever both exist)* vs. "API Token" — toggling between a new `<DoSignIn label="Sign in with DigitalOcean" />` component (direct sibling of `GcpSignIn`, same props shape) and the existing token-paste form, unchanged.
- **New component**: `desktop/resources/js/components/do-sign-in.tsx`, a trimmed copy of `gcp-sign-in.tsx` (629 lines) with the `waiting-code`/`signing-in`/code-paste-input states removed — collapses to `starting | waiting-browser | done | failed` per the implementation plan's §3. Same drawer shell, same polling pattern against new `desktop/app/Http/Controllers/CloudAuthController.php` endpoints (`loginDo`/`doLoginStatus`, mirroring `loginGcp`/`gcpLoginStatus` at `routes/web.php:64-65`).
- Once connected, it shows up in the same accounts list every other DO account does (`activeTab === 'accounts'`) — no separate "connected accounts" list needed; ADR 0029's multi-account model *is* that list already.

### Cloudflare → `dns-tls-fields.tsx` (a shared component, not a single page)

Cloudflare's token field isn't behind one modal — it's a **shared, reusable provider-picker component** (`desktop/resources/js/components/dns-tls-fields.tsx`) embedded in at least five different pages: `pages/servers/create.tsx`, `pages/servers/show.tsx`, `pages/mail/domains-tab.tsx`, `pages/sso/index.tsx`, `pages/backups/index.tsx`. Updating `dns-tls-fields.tsx` once upgrades all five call sites for free — this is the right leverage point, not a per-page change.

- Inside the existing `activeProvider === 'cloudflare'` block (~line 370+), add the same OAuth-vs-token toggle pattern, with a new `<CloudflareSignIn />` component (same shape as `DoSignIn`) as the OAuth option.
- Because this component is reused across both **account-level** contexts (pick which Cloudflare account manages DNS) and **project-level** contexts (server create/show, mail domains), the connected-account identity needs to be selectable the same way DO accounts are — i.e. once one or more Cloudflare accounts are OAuth-connected, `dns-tls-fields.tsx` should offer a dropdown of them (mirroring whatever `ProviderAccount`-selection pattern `cloud-accounts-modal.tsx` already uses for DO/Hetzner's multiple named accounts) rather than assuming a single global Cloudflare token — this is the direct UI consequence of ADR 0029 actually taking effect: Cloudflare multi-account wasn't representable in the UI at all before this plan, so it needs new picker UI, not just a new button.
- The verify-token flow already there (`handleVerify` → `POST /cloudflare/verify-token`) stays as-is for the manual-paste path; the OAuth path doesn't need a separate verify step since the identity call (`GetUserRequest`) already happened server-side during sign-in.

### Not placed anywhere yet (needs a decision, not a default)

Where does a *brand-new* user doing first-time onboarding (before they've created any server/project) first see "Connect DigitalOcean"/"Connect Cloudflare" as an option, versus discovering it only when they reach `cloud-accounts-modal.tsx`/`dns-tls-fields.tsx` deep in a flow? GCP's OAuth button today is only reachable from inside `cloud-accounts-modal.tsx` too (no separate onboarding-screen treatment), so following that same precedent for DO/Cloudflare is the consistent default — flagging this explicitly in case a dedicated onboarding card is wanted instead once Desktop work resumes.
