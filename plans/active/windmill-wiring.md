# Plan: `mail:wire` + `sso:wire` for Windmill (instance-settings API)

**Status:** Research COMPLETE (2026-07-26). Implementation not started — blocked on
one 30-second verification (see "The one open question").
**Why it's special:** Windmill reads SMTP and OIDC from **instance settings stored
in its database**, not env vars and not a mounted `oauth.json` (the current
upstream `docker-compose.yml` mounts no oauth file). So the env-based
`mail:wire`/`sso:wire` mechanisms do not apply — Windmill needs an API-driven
path, same bucket as Gitea/NetBird.

Context: `flow` defaults to Windmill; n8n is being removed from prod. Windmill CE
has **free** custom OIDC SSO (Zitadel supported; 50 SSO-user cap) and free SMTP —
that was the whole reason for switching off n8n, whose SSO is Enterprise-gated.

---

## VERIFIED (from upstream source — do not re-research)

### Auth
- `POST /api/auth/login` body `{"email": "...", "password": "..."}` → session
  token returned **as the plaintext response body** (also set as a `token` cookie).
  Use it as `Authorization: Bearer <token>`.

### Settings API
- `GET  /api/settings/global/{key}` → current value
- `POST /api/settings/global/{key}` body `{"value": <anything>}`
- `GET  /api/settings/list_global` → all settings

### Setting keys (verbatim from `backend/windmill-common/src/global_settings.rs`)
| Key | Purpose |
|---|---|
| `oauths` | OAuth/SSO providers ← **SSO wiring writes this** |
| `smtp_settings` | SMTP ← **mail wiring writes this** |
| `base_url` | Instance URL (must match the real host or callbacks break) |
| `auto_login_provider` | Skip the provider-picker, go straight to Zitadel |
| `disable_password_login` | SSO-only mode |
| `require_preexisting_user_for_oauth` | Deny unknown users (authz) |
| `automate_username_creation` | Auto-provision usernames |

### Callback / redirect URI (verified via Authelia's Windmill integration guide)
```
https://<windmill-host>/user/login_callback/<provider_key>
```
`<provider_key>` is the key used in the `oauths` object — so using `zitadel`
gives `https://windmill.<apex>/user/login_callback/zitadel`. Register exactly
that in Zitadel.

### Windmill's own UI asks for
**Config URL** (the issuer base, e.g. `https://sso.luchtech.dev`), **Client ID**,
**Client Secret** — i.e. it supports OIDC **discovery**, so the explicit
auth/token/userinfo URLs may be optional.

---

## The one open question (blocks implementation)

The exact JSON **value shape** for `oauths` and `smtp_settings`. Both are rendered
by custom frontend components (`fieldType: 'smtp_connect'`), so the schema is not
in `openapi.yaml`, `instanceSettings.ts`, or the settings constants. The
documented OAuth shape is roughly:

```json
{ "zitadel": { "id": "<client_id>", "secret": "<client_secret>",
  "login_config": { "auth_url": "...", "token_url": "...",
                    "userinfo_url": "...", "scopes": ["openid","email","profile"] } } }
```

**Do NOT guess and write it.** Writing a wrong shape corrupts live instance
settings, and guessing field names has already caused three separate bugs this
cycle (Vaultwarden `SSO_AUDIENCE_TRUSTED`, Documenso `well_known`, oauth2-proxy
`cookie_secret`).

### How to resolve it safely (30 seconds, zero risk)
On the running instance, configure each once through the superadmin UI
(Instance settings → SSO/OAuth, and → SMTP), then read back **Windmill's own
serialization**:

```bash
kubectl -n larakube-shared exec deploy/flow-windmill -- \
  curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/settings/global/oauths
# and .../smtp_settings
```

Paste those two JSON blobs into this plan, then encode them in the trait below.

---

## Implementation (once the shapes are known)

1. **`app/Traits/InteractsWithWindmillApi.php`**
   - `windmillLogin(string $host, string $email, string $password): ?string`
   - `windmillGetSetting(string $host, string $token, string $key): mixed`
   - `windmillSetSetting(string $host, string $token, string $key, mixed $value): bool`
   - `windmillToken(...)`: read a cached token from the `flow-windmill-admin`
     Secret; on miss, prompt for superadmin email+password, log in, cache the
     token (and email) back into that Secret. Mirrors how mail/sso read
     `machine-pat` / the Stalwart api-key.
2. **`sso:wire --tool=flow`** — branch on engine == windmill (mirror
   `usesForwardAuth()`'s shape; consider `usesApiWiring()`):
   - register the Zitadel app with redirect
     `https://<windmillHost>/user/login_callback/zitadel`
   - `POST /api/settings/global/oauths` with the verified shape
   - optionally set `base_url`, and `auto_login_provider=zitadel`
   - `--remove`: `POST oauths` with `{}` + deregister the Zitadel app
3. **`mail:wire --tool=flow`** — `POST /api/settings/global/smtp_settings`
   pointing at Stalwart (host `stalwart.larakube-shared…`, port 465, implicit
   TLS, sender from `mail:wire --sender`).
4. **Engine awareness** — both wire commands need `--engine=` for `flow` (and
   `drive`, which has the same gap). `smtpEnv(FLOW)` currently returns the **n8n**
   schema; it must return null (or the n8n schema only) when the installed engine
   is Windmill, so the env path can't silently no-op.
5. **Tests** — `Http::fake()` the login + settings POSTs; assert the redirect URI
   and that the correct key is written. (End-to-end correctness still needs a
   live login.)

---

## Also outstanding on Windmill

- **`windmill-lsp` is unpinned** (`:latest`). Server + worker are pinned to
  `1.770.0`; the LSP image may not publish matching tags — verify before pinning.
- **DB architecture conflict (likely a real bug):** the manifest bundles its own
  `postgres:15-alpine` as `flow-windmill-db`, yet `flow:init` *also* creates
  `windmill_user` / `windmill_admin` roles in the Plex Commons. Decide which DB
  Windmill actually uses and remove the other path.
- **Coexistence shipped:** per-engine hosts (`windmill.<tld>`), additive install
  with a redundancy advisory, host prompted the same way on local and cloud.
