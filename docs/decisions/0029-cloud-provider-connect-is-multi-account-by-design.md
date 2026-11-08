# 0029 — Connecting a cloud provider account is multi-account by design, keyed by the provider's own identity

**Status:** Accepted (2026-11-09)

## Context

LaraKube is building "Sign in with X" OAuth flows (DigitalOcean, Cloudflare — see `cli/plans/active/oauth-provider-connect.md`) to replace manually pasting an API token. A naive implementation of "sign in" writes into a single per-provider slot — exactly what `GlobalConfigData::$doToken`/`$cloudflareToken` and their `setDoToken()`/`setCloudflareToken()` setters already are: one token, one slot, "sign in again" silently overwrites it.

That model is already wrong for how LaraKube is actually used, independent of OAuth:

- An agency runs client infrastructure on the client's own DigitalOcean account and its own on a separate account.
- A freelancer has a personal Cloudflare account and manages DNS for multiple customer domains living on *their* Cloudflare accounts.
- `GlobalConfigData::$cloudAccounts` (`cloud:accounts --add`, surfaced in Desktop's `cloud-accounts-modal.tsx`) already exists specifically because one token was never enough — but it's a secondary, manually-named, easy-to-forget path bolted onto a primary single-slot one. Any new feature that writes through `setDoToken()`/`setCloudflareToken()` as its primary path repeats the same single-account mistake those fields encode.

OAuth sign-in makes the single-slot trap *more* likely to recur, not less: it's tempting to wire a "Sign in with X" button straight to the same setter the old "paste a token" field used, because it's the obvious existing call site. Doing that would ship a brand-new feature that regresses behind the multi-account support the product already has.

## Decision

**Every cloud-provider connect flow — OAuth or manual token — is multi-account by default. There is no single-account mode to fall back to; `cloudAccounts` is the only model.**

1. **The provider's own account identity is the dedupe key, never a name the user has to invent.** For OAuth specifically: immediately after the token exchange, call the provider's own identity endpoint (DO: `GET /v2/account` → `uuid`/`email`; Cloudflare: its account-scoped equivalent) and key the stored entry on that id. Signing in again with the *same* provider account updates that entry in place (refreshes its token); signing in with a *different* provider account (a different login in the browser) creates a new, separate entry. LaraKube never asks "what do you want to call this account?" for an OAuth-connected one — the provider already knows who it is.
2. **A manually-pasted token still gets a user-chosen name** (`cloud:accounts --add`'s existing behavior is correct and unchanged) because a bare API token carries no identity LaraKube can look up on its own.
3. **"Default account" is a convenience, not a ceiling.** The first account ever added for a provider becomes `default` (unchanged existing rule); every command that needs a token for that provider must accept `--do-account=`/`--cloudflare-account=` (name or id) to target a non-default one explicitly, the same way `ensureDoToken()` already honors `--do-account=` today.
4. **Token refresh is scoped to the specific account entry being used for a given run**, never global. A silent-refresh check (OAuth-connected accounts only — manually pasted tokens don't expire the same way) happens at token-resolution time for the account actually resolved for that command, not as a sweep across every stored account.
5. **Desktop surfaces this as "connect another X account"**, not a single "connect/disconnect" toggle — the UI affordance must always leave room for N accounts per provider, for every provider this pattern is extended to (see the future-candidates list in the same plan doc: GitHub, GitLab, AWS).

## Consequences

- Any new "connect to {provider}" feature — OAuth now, Device Flow for GitHub/GitLab later, AWS SSO later — writes into `cloudAccounts[$provider]`, keyed by provider identity where the provider can supply one. **Never introduce a second single-value slot field on `GlobalConfigData` for a provider that already has an entry in `cloudAccounts`.**
- A command resolving "the token for this provider" must always go through the account-registry lookup (`getDefaultCloudAccount()` or an explicit `--{provider}-account=`), never assume there is exactly one.
- This does not retroactively fix every existing single-slot field (`gcpAccount`, `awsProfile`, etc.) — it binds **new** connect work going forward. A provider migrated to multi-account support should get its single-slot fields deprecated in the same change, not left as a second, inconsistent path.
- Explicitly rejected: a single "connected account" per provider with a manual "switch account" step that discards the previous one's stored credentials. That is strictly worse than what `cloud:accounts --add` already does today and would be a regression shipped under the banner of a UX improvement.
