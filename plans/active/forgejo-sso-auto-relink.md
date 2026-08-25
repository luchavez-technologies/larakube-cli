# Forgejo SSO Auto-Re-Link (`ACCOUNT_LINKING = auto`)

**Date:** 2026-08-24
**Cluster:** larakube-159.89.205.239 (git.luchtech.dev, Forgejo 16.0.1+gitea-1.22.0)
**Symptom:** eman@luchtech.dev is not signed in directly via Zitadel SSO — Forgejo
shows the "Sign In to Link Account" page demanding a local username/password the
SSO-only user never had.

## Root Cause

Forgejo matches an incoming OIDC login in two steps
(gitea v1.22 `routers/web/auth/oauth.go`, `oAuth2UserLoginCallback`):

1. `user` table by `(login_name = sub, login_type = 6, login_source)`
2. `external_login_user` table by `(external_id = sub, login_source_id)`

Live data found:

| Forgejo user | email | `user.login_name` (stale) | `external_login_user` row |
| --- | --- | --- | --- |
| inv3ntor01 (id 4) | eman@luchtech.dev | `383631075936567438` | **missing** |
| luchtech (id 2) | james@luchtech.dev | `383040574070652982` (stale) | `384568973850575018` |

Zitadel's live API reports eman's current user id (sub) as
`384568958281318570` — his Zitadel user was recreated on **2026-08-03**
(creationDate), so neither lookup matches anymore. james evidently re-paired
through the password page at some point; eman never did and cannot (no password).

With no match, Forgejo falls into the auto-registration branch
(`ENABLE_AUTO_REGISTRATION = true` was already live), attempts
`CreateUser`, gets `ErrEmailAlreadyUsed` (inv3ntor01 owns that email), and —
because `ACCOUNT_LINKING` defaults to `login` — renders `showLinkingLogin()`,
the password-prompt page.

## Fix (template-only, command-driven)

`cli/resources/views/k8s/git/forgejo.blade.php` adds two env vars next to the
existing `ENABLE_AUTO_REGISTRATION`:

- `FORGEJO__oauth2_client__ACCOUNT_LINKING=auto` — turns the
  email-already-used collision into a silent `linkAccount()` sign-in.
  Verified against v1.22 source: this branch only executes inside the
  auto-registration path (`createUserInContext`), so it must ship together
  with `ENABLE_AUTO_REGISTRATION` (a known upstream gotcha,
  go-gitea/gitea#26940).
- `FORGEJO__oauth2_client__USERNAME=preferred_username` — default `nickname`
  works the same way but relies on goth's mapping; `preferred_username`
  reads the OIDC userinfo claim directly and handles the edge case where
  the value contains `@` (Forgejo splits at `@` for this type too,
  confirmed v16.0 `auth.go:406`). The `email` type is wrong here:
  `getUserName` (`auth.go:411`) truncates at `@`, so `admin@nexa-web.site`
  → `"admin"` → Forgejo reserved name → 500 on first registration
  (live 2026-08-25, root cause #4 below).

Security posture: auto-linking trusts the IdP's email claim. Acceptable here
— the claim comes from our own Zitadel org (emails verified), anonymous
sign-ups stay closed via `DISABLE_REGISTRATION=true`, and this mirrors how
every other LaraKube tool provisions by verified email.

## Self-Healing Behavior After Deploy

Any future Zitadel user recreation (new `sub`) re-links automatically on the
next SSO login: create-attempt → email collision → auto-link to the existing
account + fresh `external_login_user` row. No operator intervention.

## Rollout

1. `./build` (rebuild the CLI phar)
2. `larakube git:init` — re-applies the manifest idempotently (secrets are
   read-back before regenerate; admin creation is guarded by
   `admin user list`); the env change rolls the pod.
3. eman logs in via SSO once — account links, lands on inv3ntor01.

## Test

`tests/Unit/GitForgeManifestYamlTest.php` asserts all four oauth2_client env
vars render with the exact values above.

---

# Follow-up 2026-08-24: env fix was necessary but not sufficient

## Symptom after deploy

eman still landed on `/user/link_account` with the auto-linking env vars live.

## Second root cause

The `login_source` cfg had `"Scopes":[]` — `sso:wire`'s CLI-OIDC path never
passed `--scopes`, so the source requested only the implicit `openid` scope.
Zitadel's userinfo response then carried nothing but `sub`: **no email claim**.
Forgejo v16's callback (`routers/web/auth/oauth.go`) treats a missing email as
a missing field and routes to `showLinkingLogin()` — silently, at TRACE level
(stock gitea v1.22 logs it at Error; the hard fork diverged, which is why
nothing appeared in pod logs). No email in the payload = nothing to auto-link
by, regardless of `ACCOUNT_LINKING=auto`.

Verified against Forgejo's own v16.0 tree (the `+gitea-1.22.0` suffix is frozen
hard-fork branding, not actual code lineage):
- `oauth.go`: missing fields → `showLinkingLogin()`
- `auth.go` line ~573: `OAuth2AccountLinkingAuto` → lookup by name, fallback by
  email → `linkAccount()` (auto-link branch intact)

## Fix

`SsoWireCommand::applyCliOidc()` now appends `--scopes profile --scopes email`
to both the `add-oauth` and `update-oauth` invocations (`openid` is added
implicitly by the forgejo CLI). Re-running `sso:wire --tool=git` updates the
existing source in place (idempotent) and restarts Forgejo to flush its
in-memory login-source cache.

Regression guards: `SsoWireCommandTest.php` now asserts both commands include
`--scopes profile --scopes email`.

---

# Follow-up 2026-08-25: root cause #4 — `USERNAME=email` causes 500 on reserved names

## Symptom

admin@nexa-web.site (partner-org user, nexa-web.site Zitadel org) gets 500
Internal Server Error on first SSO login. james@luchtech.dev works fine.

## Root cause

Forgejo v16's `getUserName` (`auth.go:410-411`):

```go
case setting.OAuth2UsernameEmail:
    return user_model.NormalizeUserName(strings.Split(gothUser.Email, "@")[0])
```

`admin@nexa-web.site` → split at `@` → `"admin"` → Forgejo reserved name
→ `createUserInContext` error → 500. james works because `"james"` is not
reserved.

## Fix

`cli/resources/views/k8s/git/forgejo.blade.php` changed from `email` to
`preferred_username`. Zitadel returns `preferred_username` under the `profile`
scope (already requested by `sso:wire`). For admin@nexa-web.site the value is
`"nexa"` — not reserved, registration succeeds.

`cli/tests/Unit/GitForgeManifestYamlTest.php` assertion updated to match.
