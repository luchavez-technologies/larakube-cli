# 0002 — Mail server credentials: API-key daily driver, recovery break-glass, k8s-only

**Status:** Accepted (2026-07-25)

## Context

The `larakube mail:*` commands authenticate to Stalwart's management API (JMAP). The
original scheme pinned `STALWART_RECOVERY_ADMIN` on the Deployment permanently and
had the CLI authenticate as that recovery admin for **every** operation. Two
problems:

- Stalwart's own docs discourage leaving the recovery admin set in production — it's
  a break-glass credential, not a front door.
- It's fragile for a *foundational* service: if the only admin credential is the
  break-glass one, there's no separation between "normal automation" and "emergency
  rescue," and it doesn't survive an external auth directory.

Verified against a live 0.16 server: Stalwart supports **API keys** (`x:ApiKey/set`,
server-generated secret returned once, `API_…` prefix, authenticated as
`Authorization: Bearer <secret>`). They are **management-API only** (can't read
mail — least privilege) and are validated by Stalwart's own credential store, so
they keep working even if an external OIDC directory is later activated.

## Decision

Three credentials, all in the k8s `mail-secrets` Secret:

- **`api-key` — daily driver.** A Stalwart-native Bearer key, minted transparently
  on the first JMAP call, owned by a dedicated `larakube-automation` principal
  (kept separate from the human admin). `stalwartAuthHeader()` prefers it; the CLI
  uses it for all normal operations.
- **`recovery-admin` / `admin-password` — break-glass.** Still pinned via
  `STALWART_RECOVERY_ADMIN`, but used **only** to (a) bootstrap the API key on first
  run and (b) rescue it via `larakube mail:recover`. No longer the daily driver.

**These credentials are deliberately k8s-only — never synced to the secrets backend.** The
mail server is foundational infrastructure other tools depend on; its own
break-glass and automation credentials must stay self-contained rather than gaining
a dependency on the secrets manager being healthy. The API key is also cheaply
regenerable (`mail:recover`), so a backup copy would only ever drift with no
consumer to read it.

**Shared secrets that *other* systems consume do still go to OpenBao** — the Plex
Commons store/S3 creds (`STALWART_STORE_PASSWORD`, `STALWART_S3_*`) and the
`mail:wire` SMTP creds. Those aren't the mail server's own admin credentials.

## Consequences

- Clean separation: normal automation runs least-privilege; the recovery admin is a
  true fire extinguisher.
- OIDC-ready: the API key survives a directory switch, so this doesn't block a
  future re-enable of mail SSO ([0001](0001-mail-sso-shelved.md)).
- `STALWART_RECOVERY_ADMIN` stays pinned deliberately (against Stalwart's general
  advice) so the one-command `mail:recover` rescue path is always available.
- Rejected: making the secrets backend the source of truth for these. `kubectl edit secret` gets
  overwritten by the ExternalSecret controller. True backend→k8s sync would need a secret split +
  first-boot ordering for a credential that shouldn't depend on the backend at all.
