# 0001 — Mail (Stalwart/Bulwark) stays on passwords; full-OIDC SSO is shelved

**Status:** Accepted (2026-07-25)

## Context

The goal was to reduce the number of login screens by making Zitadel the single
sign-on for the whole tool suite, including webmail. The natural target was
Bulwark (the webmail UI in front of the Stalwart mail server).

Investigation turned up hard constraints:

- **Bulwark SSO is inseparable from Stalwart full-OIDC.** Bulwark authenticates the
  user against Zitadel, then presents that token to Stalwart's JMAP over
  OAUTHBEARER. Stalwart only accepts it if it has an **OIDC directory** trusting
  Zitadel. There is no "Bulwark-only" SSO. (Pointing Bulwark at Stalwart's *own*
  OIDC provider is a no-op — it's still a separate mail login, so it reduces zero
  screens. See [0004](0004-stalwart-oidc-two-roles.md).)
- **Stalwart's OIDC directory is server-wide.** Activating it (`directoryId` on the
  Authentication singleton) *replaces* local password authentication for the admin
  UI, IMAP, SMTP, and JMAP all at once. There is no per-user split.
- **It breaks the Stalwart web admin console.** The webadmin redirects to Zitadel
  with a fixed `client_id=stalwart-webui` that Zitadel can't host (Zitadel
  auto-generates client IDs). Even the break-glass recovery admin is buggy in the
  web UI under OIDC — upstream `stalwartlabs/webadmin` issue #52.
- **The win would be partial anyway.** Only webmail can use SSO; desktop clients
  (Thunderbird, Apple Mail) over IMAP/SMTP require Stalwart **App Passwords**
  regardless.

The deciding constraint: **a human operator administers email through the Stalwart
web admin console**, so that UI must remain usable. Server-wide OIDC would break it.

## Decision

**Do not put Stalwart into full-OIDC mode. Mail stays on password authentication.**

Instead, close the gap with **credential parity**: `mail:create` and
`mail:password` provision/rotate the Stalwart mailbox and the matching Zitadel
identity on the *same* password (`--no-sso` opts out). A user therefore has **one
credential** for mail and for everything else behind Zitadel — one password to
remember and rotate, even though mail login isn't a one-click redirect.

SSO for non-mail tools (Grafana, Vaultwarden, etc.) via Zitadel is unaffected.

## Consequences

- ✅ Admin web console stays intact; desktop mail clients keep working with normal
  passwords (no App Password workflow forced on anyone).
- ✅ "One password everywhere" via the sync — most of the login-friction win with
  zero breakage.
- ❌ No one-click Zitadel redirect for webmail; users type email + password into
  Bulwark.
- The Bulwark SSO wiring was **removed**, not just gated: `sso:wire` now refuses
  webmail via `ClusterTool::hasSsoWire()` (which returns `false` for it), and the
  destructive `wireStalwartOidc()` + the Stalwart OIDC-directory JMAP helpers
  (`stalwartUpsertOidcDirectory` etc.) were deleted. Dead, footgun-shaped code is
  worse than a clean re-derivation later.
- **Revisit if** upstream fixes webadmin #52. The credential model in
  [0002](0002-mail-credential-model.md) stays OIDC-ready (the automation key
  survives a directory switch), so re-enabling means re-deriving the wiring — and
  we can do it better next time — not reworking the foundation.
