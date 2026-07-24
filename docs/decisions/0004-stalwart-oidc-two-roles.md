# 0004 — Stalwart's two OIDC roles (provider vs directory)

**Status:** Accepted (2026-07-25) — reference/gotcha

## Context

Stalwart can speak OIDC in **two opposite roles**, and confusing them cost real
time. Recorded here so nobody re-treads it.

| | **OIDC Directory** (consumer) | **OIDC Provider** |
|---|---|---|
| What it does | Validates tokens issued by an *external* IdP (Zitadel) | Issues Stalwart's *own* tokens for its mail accounts |
| Effect on local auth | **Replaces** primary auth server-wide (admin/IMAP/SMTP/JMAP) | **Coexists** with passwords — nothing breaks |
| Object | `x:Directory` (`@type: "Oidc"`) + `directoryId` on `x:Authentication` | auto-enabled; `x:OidcProvider`, `/.well-known/openid-configuration` |

## Key facts

- **Bulwark SSO needs the *directory* (consumer).** Bulwark presents a Zitadel token
  to Stalwart's JMAP; Stalwart must validate it → OIDC directory → server-wide auth
  replacement. This is why mail SSO breaks the admin console
  ([0001](0001-mail-sso-shelved.md)).
- **Pointing Bulwark at Stalwart's own *provider* is a no-op** for the goal. It
  works and doesn't break passwords, but the user still faces a separate mail login
  — it reduces zero login screens, which was the whole point.
- The OIDC **directory** config has no `client_id`/`secret` field — it only
  validates tokens. `requireAudience` defaults to the literal string `"stalwart"`
  and rejects any token whose `aud` doesn't include it, so it must be set to the
  IdP's client ID. Tokens must be **JWTs** (not opaque) for the `aud`/`scope` checks
  to be readable.
- Stalwart 0.16 moved config off the old `POST /api/settings` REST API onto **JMAP
  objects** (`x:Object/get|set`); read authoritative field names from
  `GET /api/schema/Directory`. The `InteractsWithStalwartApi` trait shows the JMAP
  call pattern (the directory-specific helpers were removed with the SSO wiring —
  see [0001](0001-mail-sso-shelved.md) — so re-derive them from the schema).

## Decision

No action beyond documenting the distinction. Mail SSO is shelved
([0001](0001-mail-sso-shelved.md)); if it's revisited, use the **directory**
(consumer) role — the provider role does not serve the goal.
