# Plan: Configurable Cinny UI Branding & Matrix Synapse SSO Auto-Redirect (`chat:init` & `sso:wire`)

> **Status:** Draft  
> **Created:** 2026-08-05  
> **Target Version:** LaraKube CLI v1.2.0

---

## 1. Goal Description

Support **per-operator / per-deployment customization** for the Matrix Synapse & Cinny web chat stack (`ClusterTool::CHAT` / `chat:init` / `sso:wire --tool=chat`). Different CLI users deploying LaraKube on their own servers can specify their custom App Name, logo, and favicon without modifying LaraKube source code:

1. **Configurable Branding via Flags & Prompts**: Add optional `--app-name` (e.g. `"Acme Chat"`) and `--logo-url` flags to `chat:init`. Persist these in `.larakube.json` so every CLI user gets their own custom-branded Cinny interface.
2. **`--sso-only` Flag Enforcement**: When `sso:wire --tool=chat --sso-only` is executed:
   - Disable local password authentication in Synapse (`password_config.enabled = false` and `localdb_enabled = false`).
   - Automatically redirect unauthenticated visits on `https://chat.<domain>/` straight to Zitadel SSO (`/_matrix/client/v3/login/sso/redirect/zitadel`), bypassing the Cinny login page button click.
3. **Standard `sso:wire` (Without `--sso-only`)**: Keeps local password login enabled alongside SSO for emergency admin access, displaying "Login with SSO" on Cinny's login page.
4. **Rename OIDC Provider Label**: Update Synapse's OIDC `idp_name` from `"Zitadel"` to `"SSO"` so API responses present "SSO" across all Matrix clients.
5. **Cinny Nginx Asset Injection**: Inject the user's custom `$appName` and `$logoUrl` into Cinny's static files via Nginx `sub_filter` inside `chat-cinny-config`.

---

## 2. User Review Required

> [!IMPORTANT]
> **Per-User Configuration**: `chat:init` will prompt for or accept `--app-name="Your Brand Chat"` and save it in `.larakube.json`. Subsequent `sso:wire` or `chat:init` runs will reuse this configuration automatically.

---

## 3. Proposed Changes

### Core Architectural Modifications

#### `cli/app/Commands/Chat/ChatInitCommand.php`
- Add signature flags:
  ```php
  {--app-name= : Custom branding name for Cinny (default: LaraKube Chat)}
  {--logo-url= : Custom logo URL for Cinny UI header}
  ```
- Save `$appName` to `.larakube.json` under `hosts.chat_app_name`.

#### `cli/resources/views/k8s/chat/matrix.blade.php`
- Pass `$appName` (defaulting to `'LaraKube Chat'`) into `matrix.blade.php`.
- In `chat-cinny-config`, use Nginx `sub_filter` to substitute `<title>Cinny</title>` with `<title>{{ $appName }}</title>`.
- Update Synapse `homeserver.yaml` template:
  - Conditionally disable password login when `$ssoOnly` is true:
    ```yaml
    @if(($oidc ?? null) && ($ssoOnly ?? false))
        password_config:
          enabled: false
          localdb_enabled: false
    @endif
    ```
  - Update `oidc_providers` `idp_name` default to `"SSO"`.
  - Add Nginx `rewrite` rule for root `/` to trigger OIDC auto-redirect when `$ssoOnly` is true:
    ```nginx
    @if($ssoOnly ?? false)
        rewrite ^/$ /_matrix/client/v3/login/sso/redirect/zitadel redirect;
    @endif
    ```

---

## 4. Verification Plan

### Automated Tests
- Create `cli/tests/Feature/CinnyBrandingSsoTest.php` to verify:
  - `chat:init --app-name="Acme Chat"` generates `chat-cinny-config` with Nginx `sub_filter` for `"Acme Chat"`.
  - `sso:wire --tool=chat --sso-only` sets `password_config.enabled = false` in `homeserver.yaml` and adds Nginx auto-redirect.
  - `sso:wire --tool=chat --remove` restores password login and removes OIDC integration.
- Run test suite:
  ```bash
  php vendor/bin/pint
  php -d memory_limit=1G vendor/bin/pest tests/Feature/CinnyBrandingSsoTest.php tests/Feature/SsoWireCommandTest.php
  ```
