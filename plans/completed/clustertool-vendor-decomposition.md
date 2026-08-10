# ClusterTool Vendor Decomposition — COMPLETE

**All 7 stages DONE and verified 2026-08-10.** `app/Enums/ClusterTool.php` shrank from 2058 to 1200 lines. All 29 categories now dispatch through `vendor()` (now total/non-nullable) to either one of 7 vendor enums (`app/Enums/`) or 22 vendor classes (`app/Vendors/`). Final verification: `./php vendor/bin/phpstan analyse app/` → `[OK] No errors`; full `./php vendor/bin/pest --parallel` suite passing (only the pre-existing, unrelated `ServerManifestTest`/`ServicesManifestTest`/`FrontendManifestTest` parallel-temp-dir race flakes, documented in §7 below, intermittently fail — confirmed harmless by re-running serially); zero pre-existing test files modified across the entire 7-stage effort (only the pre-dating baseline list in §8 shows as modified, and that predates this effort entirely); a manual grep sweep of every method in the file confirms only the deliberately-kept category-level methods (§4 Decision 1) retain any per-case `self::CATEGORY =>` arms. Nothing has been committed — this sits in the working tree for the repo owner to review and commit themselves, per their hard rule.

This file was originally written as a resumable handoff for a fresh agent (e.g. Antigravity) with zero prior context, mid-effort when the session hit its usage limit. It's kept below in full for reference/audit — the architecture, decisions, and gotchas documented remain accurate as a record of what was built and why, even though there is no more work left to resume.

## 1. What this project is

`app/Enums/ClusterTool.php` is a 29-case backed PHP enum — one case per cluster-wide SaaS tool category the LaraKube CLI manages (DATA, FLOW, GIT, CHAT, MAIL, SSO, ... 29 total). Historically every method on it was one giant `match ($this) { ... }` block covering all 29 cases — e.g. `productName()`, `smtpEnv()`, `oidcEnv()`, `commonsDatabaseList()`, `components()`, etc. The file had grown to ~2058 lines and was unmaintainable.

**The approved architecture** (mirrors `app/Enums/DatabaseDriver.php`'s existing style): every category gets its own small "vendor" that owns ONLY that category's product knowledge:

- **7 categories with a real or plausible second vendor** become their own PHP **enum** under `app/Enums/`, one case per vendor:
  - `DataTool` (POCKETBASE, DIRECTUS) — DONE
  - `FlowTool` (N8N, WINDMILL) — DONE
  - `GitForgeTool` (FORGEJO only) — DONE
  - `ChatTool` (MATRIX only) — DONE
  - `DesignTool` (PENPOT only) — DONE
  - `TaskTool` (PLANKA only — Plane dropped, see §4) — DONE
  - `DeskTool` (FREESCOUT only) — DONE
- **22 categories with exactly one vendor and no plausible alternative** become a plain, stateless, no-constructor PHP **class** under `app/Vendors/` (note: **singular** names — `PasswordTool` not `PasswordsTool`, `SecretTool` not `SecretsTool`, `TaskTool` not `TasksTool`, `NoteTool` not `NotesTool`, `SheetTool` not `SheetsTool`, `InsightTool` not `InsightsTool`, `ErrorTool` not `ErrorsTool`. Everything else is already singular.):
  - `MailTool`, `SecretTool`, `DriveTool`, `PasswordTool` — DONE (Stage 4)
  - `SignTool`, `RecordTool`, `SsoTool`, `LinkTool`, `WebmailTool` — DONE (Stage 5)
  - `NoteTool`, `SheetTool`, `MonitorTool`, `CrmTool`, `SupportTool`, `InsightTool`, `ErrorTool` — **TODO (Stage 6)**
  - `AnalyticsTool`, `MeetTool`, `DnsTool`, `UptimeTool`, `VpnTool`, `DashboardTool` — **TODO (Stage 7)**

`ClusterTool::vendor(?string $engine = null): ?ClusterToolVendor` is the dispatch method (currently nullable during the staged rollout; becomes non-nullable/total once Stage 7 finishes — see §6 final cleanup). Every dispatched method (`productName()`, `smtpEnv()`, `oidcEnv()`, `dbSecretRef()`, `commonsDatabaseList()`, `commonsBucketList()`, `whiteLabel()`, `baselineFlags()`, `commonsRedisKeys()`, `clusterSecretDbKey()`, `openbaoSyncConfig()`, `components()`, `baseDeploymentName()`, `hasMeetWire()`, `usesCliOidc()`, `usesForwardAuth()`, `configuresViaConfigFile()`, `ssoLicenseCaveat()`) now follows this pattern:

```php
public function someMethod(...): ReturnType
{
    $vendor = $this->vendor($engine);
    if ($vendor instanceof SomeContract) {
        return $vendor->someMethod(...); // + ClusterTool injects namespace/instance-suffix uniformly
    }

    return match ($this) {
        // only the NOT-YET-MIGRATED categories still have arms here
        self::NOTES => [...],
        ...
        default => null,
    };
}
```

As each category migrates, its arm is deleted from the legacy `match` and its logic moves verbatim into the new vendor file. **The file shrinks every stage.** Current size: see `wc -l app/Enums/ClusterTool.php` (was 2058 before Stage 1; ~1436 lines after Stage 5).

## 2. Contracts (already ALL exist — do not recreate)

All 17 contracts are already built in `app/Contracts/`. Do not touch these — just implement them on new vendor classes:

`ClusterToolVendor` (mandatory marker extending `HasLabel`), `HasSmtpWiring`, `HasOidcWiring`, `HasCommonsDatabases`, `HasCommonsBuckets`, `HasDbSecretRef`, `HasOpenbaoSync`, `HasDeploymentBaseName`, `HasSsoLicenseCaveat`, `HasWhiteLabel`, `HasBaselineFlags`, `HasCommonsRedisKeys`, `HasClusterSecretDbKey`, `ConfiguresViaConfigFile` (empty marker), `HasMeetBridge` (empty marker), `UsesCliOidc` (empty marker), `UsesForwardAuth` (empty marker). Also reused unchanged from before this whole effort: `HasLabel`, `HasWorkloadComponents` (from `app/Contracts/`), `ClusterToolComponentData`/`ClusterToolComponentRole` (from `app/Data/` and `app/Enums/`).

Read any of the Stage 4/5 vendor classes (`app/Vendors/MailTool.php`, `app/Vendors/SignTool.php`, etc.) to see the exact style: no constructor, methods return their fixed answer directly (no `match ($this)` needed — there's only one implicit case per plain class).

**IMPORTANT — a discovered gotcha, applies to every new file you write:** there's a test, `tests/Unit/EnumImportResolutionTest.php`, that scans every non-`Enums/`-directory PHP file for a **bare literal string** `SomeEnumName::` in ANY text including doc comments, and fails if that enum isn't imported. If you write a docblock comment like `/** Only Penpot (DesignTool::PENPOT) does this */` inside a file under `app/Contracts/` or `app/Vendors/`, that trips the test even though it's just a comment, because those directories are not exempted. **Avoid writing `ClusterTool::`, `DataTool::`, `FlowTool::`, `GitForgeTool::`, `ChatTool::`, `DesignTool::`, `TaskTool::`, `DeskTool::`, `MailTool::`, `SecretTool::`, `DriveTool::`, `PasswordTool::`, `SignTool::`, `RecordTool::`, `SsoTool::`, `LinkTool::`, `WebmailTool::` as literal text in any comment inside `app/Contracts/*.php` or `app/Vendors/*.php`.** Reword instead (e.g. "Only Penpot (Design category)" instead of "Only DesignTool::PENPOT"). Files under `app/Enums/*.php` ARE exempted so this doesn't matter there. Run `./php vendor/bin/pest tests/Unit/EnumImportResolutionTest.php` after writing new files to catch this immediately.

## 3. The `vendor()` dispatch table (current state — extend this)

In `app/Enums/ClusterTool.php`, near the top of the class:

```php
public function vendor(?string $engine = null): ?ClusterToolVendor
{
    return match ($this) {
        self::DATA => DataTool::tryFrom((string) $engine) ?? DataTool::DIRECTUS,
        self::FLOW => FlowTool::tryFrom((string) $engine) ?? FlowTool::N8N,
        self::GIT => GitForgeTool::FORGEJO,
        self::CHAT => ChatTool::MATRIX,
        self::DESIGN => DesignTool::PENPOT,
        self::TASKS => TaskTool::PLANKA,
        self::DESK => DeskTool::FREESCOUT,
        self::MAIL => new MailTool(),
        self::SECRETS => new SecretTool(),
        self::DRIVE => new DriveTool(),
        self::PASSWORDS => new PasswordTool(),
        self::SIGN => new SignTool(),
        self::RECORD => new RecordTool(),
        self::SSO => new SsoTool(),
        self::LINK => new LinkTool(),
        self::WEBMAIL => new WebmailTool(),
        default => null,
    };
}
```

For Stage 6, add 7 more arms (`self::NOTES => new NoteTool()`, etc.) and their `use App\Vendors\NoteTool;` imports (imports are alphabetically sorted, one per line, near the top of the file next to the existing `use App\Vendors\...` lines). For Stage 7, add the remaining 6 and then do the final cleanup (§6).

Single-case enum vendors (GIT/CHAT/DESIGN/TASKS/DESK) are dispatched with a **hardcoded case reference, no `tryFrom`** — this is deliberate: the `$engine` argument is irrelevant for a category with only one real vendor, and this exactly matches today's behavior (today's code ignores `$engine` for these categories too, always resolving the one real product).

## 4. Decisions already made — do not re-litigate these

1. **`rbacRoles()`, `ssoAdminRoles()`, `grantableRoles()`, `vpnMiddlewareTargetBase()`, `oidcRedirectUris()`, `oidcPostLogoutRedirectUris()`, `engines()`, `defaultEngine()`, `supportsNoPlex()`, `supportsMultipleInstances()`, `namespace()`, `removesNamespace()`, `service()`, `getLabel()` (the category-level description), `icon()`, `brandName()`** — **all stay 100% on `ClusterTool`, untouched, for every one of the 29 categories, forever.** These are category-level or RBAC/cluster-topology concerns, not "which vendor" concerns. Do not try to move them into vendor classes.
2. **TASKS' dead `PLANE` engine was fully dropped** — `TaskTool` only has a `PLANKA` case. `engines()` for TASKS was also trimmed to `['planka' => 'Planka']` (Plane removed from the selectable list too). This closed a real bug: `productName()` used to hardcode `'Plane'` unconditionally even on real Planka-only installs.
3. **`FLOW`'s cross-case aggregation stays on `ClusterTool`**, not on `FlowTool`. The private `commonsDatabaseList()` method has a special-case check `if ($this === self::FLOW && $engine === null) { return array_merge(...array_map(fn (FlowTool $c) => $c->commonsDatabaseList(), FlowTool::cases())); }` that must run BEFORE the generic vendor dispatch, because a null `$engine` for teardown must report BOTH n8n and windmill tenants (drop-whichever-exists safety), not just N8N's (the vendor-dispatch default).
4. **`ssoLicenseCaveat()` has a special, deliberately-NOT-generic implementation** because of a genuine pre-existing inconsistency in the original code: every other DATA method treats a `null` `$engine` as "not pocketbase" (falls to Directus), but `ssoLicenseCaveat()` specifically treated `null` as "use `defaultEngine()`" (= pocketbase, i.e. no caveat). This is preserved exactly:
   ```php
   public function ssoLicenseCaveat(?string $engine = null): ?string
   {
       if ($this !== self::DATA) {
           return null;
       }
       $vendor = DataTool::tryFrom($engine ?? $this->defaultEngine() ?? '') ?? DataTool::DIRECTUS;
       return $vendor instanceof HasSsoLicenseCaveat ? $vendor->ssoLicenseCaveat() : null;
   }
   ```
   Do not touch this method for Stage 6/7 — no other category has this quirk, it's permanently DATA-only.
5. **`hasSsoWire()`'s `self::WEBMAIL => false` explicit override stays untouched** — it's already redundant with the generic rule (`default => $this->oidcEnv() !== null`) since `WebmailTool` correctly implements no `HasOidcWiring`, but removing the explicit arm costs nothing to leave and documents the Stalwart-admin-console-conflict policy rationale in place. Leave it exactly as-is.
6. **A vendor implements a contract ONLY if it has real, non-vacuous content.** E.g. `DeskTool` implements no `HasSmtpWiring`/`HasOidcWiring` at all (rather than implementing it and returning `null`) because DESK never had those arms in the original code. Match this discipline for every new vendor in Stage 6/7 — check the ACTUAL current arms in `ClusterTool.php` (grep for `self::CATEGORY =>` across every method) rather than assuming from memory/a summary. **A prior 22-category research pass got one category (`DRIVE`) wrong** — it claimed `DriveTool` should implement `HasWhiteLabel`, but the live code's `whiteLabel()` never had a DRIVE arm at all. This was caught only by re-grepping the actual file before writing the class. **Always re-verify against the live file, never trust a summary of what a category "should" have.**
7. **Every non-compound vendor (i.e. one that doesn't implement `HasWorkloadComponents`) MUST implement `HasDeploymentBaseName`** — `components()`'s generic fallback path needs it to build the single PRIMARY component. Every Stage 6/7 vendor is non-compound (none of them are multi-Deployment), so every one of the 13 remaining classes needs `HasDeploymentBaseName`.

## 5. Stage 6 — exact content to move (NOTES/SHEETS/MONITOR/CRM/SUPPORT/INSIGHTS/ERRORS)

Grep `app/Enums/ClusterTool.php` for `self::NOTES =>`, `self::SHEETS =>`, `self::MONITOR =>`, `self::CRM =>`, `self::SUPPORT =>`, `self::INSIGHTS =>`, `self::ERRORS =>` to find every remaining arm (the line numbers below will have shifted by the time you read this — always re-grep fresh, don't trust these numbers). As of this handoff, the arms to migrate are:

- **`NoteTool`** (getLabel `'Outline'`, baseDeploymentName `'notes-outline'`): `HasSmtpWiring` (deployment `notes-outline`, secret `notes-outline-smtp`, static `SMTP_SECURE=true`, vars host/port/user/password/from → `SMTP_HOST`/`SMTP_PORT`/`SMTP_USERNAME`/`SMTP_PASSWORD`/`SMTP_FROM_EMAIL`), `HasOidcWiring` (deployment `notes-outline`, secret `notes-outline-oidc`, static `FORCE_HTTPS=true`, vars client_id/client_secret/auth_url/token_url/userinfo_url → `OIDC_CLIENT_ID`/`OIDC_CLIENT_SECRET`/`OIDC_AUTH_URI`/`OIDC_TOKEN_URI`/`OIDC_USERINFO_URI`, redirect_path `/auth/oidc.callback`), `HasCommonsDatabases` (`['outline']`), `HasCommonsBuckets` (`['notes-storage']`), `HasCommonsRedisKeys` (`['outline']`).
- **`SheetTool`** (getLabel `'Teable'`, baseDeploymentName `'sheet-teable'`): `HasSmtpWiring` (deployment `sheet-teable`, secret `sheet-teable-smtp`, static `BACKEND_MAIL_SECURE=true`, vars → `BACKEND_MAIL_HOST`/`BACKEND_MAIL_PORT`/`BACKEND_MAIL_AUTH_USER`/`BACKEND_MAIL_AUTH_PASS`/`BACKEND_MAIL_SENDER`), `HasOidcWiring` (deployment `sheet-teable`, secret `sheet-teable-oidc`, static `SOCIAL_AUTH_PROVIDERS=oidc` + `BACKEND_OIDC_OTHER={"scope":["email","profile"]}` — keep the comment explaining why the email/profile scope is needed, it documents a real production bug that already happened, vars incl. a `callback_url` key → `BACKEND_OIDC_CALLBACK_URL`, redirect_path `/api/auth/oidc/callback`), `HasCommonsDatabases` (`['teable']`), `HasCommonsBuckets` (`['sheet-public', 'sheet-private']`), `HasCommonsRedisKeys` (`['teable']`).
- **`MonitorTool`** (getLabel `'Grafana'`, baseDeploymentName `'grafana'`): `HasSmtpWiring` (deployment `grafana`, secret `grafana-smtp`, static `GF_SMTP_ENABLED=true`, vars — **note: NO `port` key in `vars`, this is a real pre-existing quirk, preserve exactly** — host/user/password/from → `GF_SMTP_HOST`/`GF_SMTP_USER`/`GF_SMTP_PASSWORD`/`GF_SMTP_FROM_ADDRESS`), `HasOidcWiring` (the long one — deployment `grafana`, secret `grafana-oidc`; copy the `static`/`sso_only_vars`/`vars`/`redirect_path` array VERBATIM including every comment, it encodes real incident-derived behavior about `GF_AUTH_GENERIC_OAUTH_ROLE_ATTRIBUTE_PATH` JMESPath priority and the `ALLOW_ASSIGN_GRAFANA_ADMIN=false` safety default), `HasWhiteLabel` (`['app_name_key' => 'GF_BRANDING_APP_TITLE', 'logo_url_key' => 'GF_BRANDING_FAV_ICON']`). **Do NOT add `rbacRoles()`/`ssoAdminRoles()` content to `MonitorTool`** — those stay on `ClusterTool` per Decision 1 above, even though MONITOR has real rbacRoles content; only the wiring-shape methods move.
- **`CrmTool`** (getLabel `'Twenty'`, baseDeploymentName `'crm-twenty'`): `HasSmtpWiring` (deployment `crm-twenty`, secret `crm-twenty-smtp`, static `EMAIL_DRIVER=smtp`, vars → `EMAIL_SMTP_HOST`/`EMAIL_SMTP_PORT`/`EMAIL_SMTP_USER`/`EMAIL_SMTP_PASSWORD`/`EMAIL_FROM_ADDRESS`), `HasCommonsDatabases` (`['crm_twenty']`). No OIDC (CRM never had an oidcEnv arm).
- **`SupportTool`** (getLabel `'Chatwoot'`, baseDeploymentName `'support-chatwoot'`): `HasSmtpWiring` (deployment `support-chatwoot`, secret `support-chatwoot-smtp`, static `SMTP_ENABLE_STARTTLS_AUTO=true`, vars host/port/user/password/from → `SMTP_ADDRESS`/`SMTP_PORT`/`SMTP_USERNAME`/`SMTP_PASSWORD`/`MAILER_SENDER_EMAIL`), `HasWhiteLabel` (`['app_name_key' => 'INSTALLATION_NAME', 'logo_url_key' => 'LOGO_URL']`), `HasCommonsDatabases` (`['support_chatwoot']`). No OIDC.
- **`InsightTool`** (getLabel `'Metabase'`, baseDeploymentName `'insights-metabase'`): `HasWhiteLabel` (`['app_name_key' => 'MB_SITE_NAME', 'logo_url_key' => 'MB_APPLICATION_LOGO_URL']`), `HasCommonsDatabases` (`['metabase']`). No SMTP, no OIDC — verify via grep, don't assume.
- **`ErrorTool`** (getLabel `'GlitchTip'`, baseDeploymentName `'glitchtip-web'`): `HasWhiteLabel` (`['app_name_key' => 'GLITCHTIP_INSTANCE_NAME']`), `HasCommonsDatabases` (`['glitchtip']`). No SMTP, no OIDC.

After writing all 7 classes under `app/Vendors/`, in `ClusterTool.php`:
1. Add 7 `use App\Vendors\...;` imports + 7 `vendor()` dispatch arms.
2. Remove the now-redundant arms from: `productName()`'s legacy match (delete `self::NOTES/SHEETS/MONITOR/CRM/SUPPORT/INSIGHTS/ERRORS` lines — wait, check first: by Stage 5 end, `productName()`'s legacy match had already lost DATA/FLOW/GIT/CHAT/DESIGN/TASKS/DESK/MAIL/SECRETS/DRIVE/PASSWORDS/SIGN/RECORD/SSO/LINK/WEBMAIL arms; NOTES/SHEETS/MONITOR/CRM/SUPPORT/INSIGHTS/ERRORS arms are NOT literally present in `productName()`'s match because `productName()` only lists entries that differ from `getLabel()`... **actually just grep and check** — do not assume, `productName()`'s exact remaining arm list must be re-verified live), `whiteLabel()` (remove SUPPORT/ERRORS/INSIGHTS/MONITOR arms), `smtpEnv()` (remove SHEETS/NOTES/SUPPORT/CRM/MONITOR arms), `oidcEnv()` (remove MONITOR/NOTES/SHEETS arms), `commonsRedisKeys()` (remove NOTES/SHEETS arms — **the method already has a generic `instanceof HasCommonsRedisKeys` dispatch from Stage 3, added when GIT migrated; just delete the remaining match arms and check if the `match` becomes fully empty/collapsible**), private `baseDeploymentName()` (remove all 7 arms), private `commonsDatabaseList()` (remove all 7 arms), private `commonsBucketList()` (remove NOTES/SHEETS arms).
3. Run `./php -l app/Enums/ClusterTool.php` + all 7 new vendor files.
4. Run the targeted tests (see §7) then the full suite + phpstan (see §8).

## 6. Stage 7 — exact content to move (ANALYTICS/MEET/DNS/UPTIME/VPN/DASHBOARD) + FINAL CLEANUP

- **`AnalyticsTool`** (getLabel `'Umami'`, baseDeploymentName `'analytics-umami'`): `HasCommonsDatabases` (`['umami']`). Nothing else.
- **`MeetTool`** (getLabel `'LiveKit'`, baseDeploymentName `'meet-livekit'`): nothing beyond `ClusterToolVendor`+`HasDeploymentBaseName`.
- **`DnsTool`** (getLabel `'ExternalDNS'`, baseDeploymentName `'external-dns'`): nothing beyond `ClusterToolVendor`+`HasDeploymentBaseName`. (DNS's `service()` returns `null` — untouched, category-level, don't worry about it.)
- **`UptimeTool`** (getLabel `'Uptime Kuma'`, baseDeploymentName `'uptime-kuma'`): nothing beyond `ClusterToolVendor`+`HasDeploymentBaseName`.
- **`VpnTool`** (getLabel `'NetBird'`, baseDeploymentName `'netbird-management'`): nothing beyond `ClusterToolVendor`+`HasDeploymentBaseName`.
- **`DashboardTool`** (getLabel `'Headlamp'`, baseDeploymentName `'dashboard-headlamp'`): `HasOidcWiring` — grep the current `oidcEnv()` for the `self::DASHBOARD =>` arm and copy verbatim (deployment `dashboard-headlamp`, secret `dashboard-headlamp-oidc`; keep the koanf/`HEADLAMP_CONFIG_` comment, it documents a real silent-no-op bug already hit in production; static `HEADLAMP_CONFIG_OIDC_SCOPES`, vars client_id/client_secret/issuer → `HEADLAMP_CONFIG_OIDC_CLIENT_ID`/`HEADLAMP_CONFIG_OIDC_CLIENT_SECRET`/`HEADLAMP_CONFIG_OIDC_IDP_ISSUER_URL`, redirect_path `/oidc-callback`). **Do NOT add `rbacRoles()` content** (DASHBOARD's cluster-admin-gating role stays on `ClusterTool` per Decision 1).

After writing these 6, add their `vendor()` dispatch arms + imports as usual, remove their remaining legacy match arms (re-grep first, don't assume which methods still have entries for them).

### Final cleanup (do this ONLY after all 29 categories have a vendor)

1. In `vendor()`: delete the `default => null,` arm and change the return type from `?ClusterToolVendor` to `ClusterToolVendor` (now total — PHP's `match` without a `default` throws `UnhandledMatchError` if a case is missed, which is fine/desired since it can't happen once all 29 arms exist).
2. Every dispatched method's `if ($vendor instanceof X) { ... } return match ($this) { ... default => null/[] };` fallback becomes **dead code** once `vendor()` is total for a contract that every migrated vendor either has or doesn't (the fallback match will have zero remaining arms, just `default => null` or `default => []`). Simplify every such method to remove the now-empty `match` entirely and just return the default value directly, OR keep the instanceof-guard-then-fallback shape if you prefer explicitness — but the private `baseDeploymentName()` and its two siblings (`commonsDatabaseList()`, `commonsBucketList()`) should have their `match` bodies **fully deleted** since every arm will be gone; collapse them to just `return match ($this) {} ;` — no wait, an empty match with no `default` throws for ANY call, which is correct once EVERY method-with-content case is dispatched through the vendor path (a category with no content for that method just isn't `instanceof` the contract, so its class-level fallback of `null`/`[]` after the `instanceof` check is what applies — the private legacy methods become 100% dead and should be **deleted outright**, not left as empty matches). Check each of the 13 "was this method's whole match now empty" cases individually via grep before deleting — do not blanket-delete without checking.
3. In `components()`: once every one of the 29 vendors implements either `HasWorkloadComponents` or `HasDeploymentBaseName`, the `$base = $vendor instanceof HasDeploymentBaseName ? $vendor->baseDeploymentName() : $this->baseDeploymentName($engine);` ternary's false branch (`$this->baseDeploymentName($engine)`) becomes unreachable. Simplify to `$base = $vendor->baseDeploymentName();` (this requires `$vendor` to be typed as definitely non-null AND definitely `HasDeploymentBaseName` at that point — since the `HasWorkloadComponents` case already returned earlier, and every remaining vendor at this point in the method is required-by-design to implement `HasDeploymentBaseName`; add a defensive check or trust the design — your call, but this is the last line of real work) and delete the now-unused private `baseDeploymentName()` method entirely.
4. Grep the whole file one more time for every `self::{ANY_OF_THE_29}` you can find inside `productName()`, `smtpEnv()`, `oidcEnv()`, `dbSecretRef()`, `commonsDatabaseList()`, `commonsBucketList()`, `whiteLabel()`, `baseDeploymentName()`/`components()`, `openbaoSyncConfig()`, `clusterSecretDbKey()`, `commonsRedisKeys()`, `hasMeetWire()`, `usesCliOidc()`, `usesForwardAuth()`, `configuresViaConfigFile()`, `ssoLicenseCaveat()` — there should be **zero** remaining (every category-specific fact has moved out; only the explicitly-decided-to-stay methods from §4 Decision 1 keep per-case arms).
5. Confirm final `wc -l app/Enums/ClusterTool.php` lands roughly 500–700 lines (down from the original 2058).
6. Run the FULL verification one final time (§8) and report the final line count + confirm zero pre-existing test files were modified across the ENTIRE 7-stage effort (only additions).

## 7. Testing discipline — read `cli/CLAUDE.md` first

This project requires `Process::fake()`/`Http::fake()` for all shell/HTTP in tests — never let a test hit a real cluster. All PHP commands go through the `./php` wrapper script (runs inside Docker), never the raw `php` binary — same for `./composer`. **Never run `./build` yourself** — that's the user's job.

**Per-stage gate: zero modifications to any pre-existing test file, only additions.** If making a stage's change requires editing an existing test's assertion, that signals the stage broke something — stop and re-examine, don't just update the assertion to match.

Before touching any category in Stage 6/7, find its existing tests first:
```bash
grep -rln "ClusterTool::NOTES\|ClusterTool::SHEETS\|ClusterTool::MONITOR\|ClusterTool::CRM\|ClusterTool::SUPPORT\|ClusterTool::INSIGHTS\|ClusterTool::ERRORS" tests/
```
(swap in the Stage 7 category names for that stage). Run those specific test files after each category's migration, THEN the full suite.

**Known pre-existing flaky tests, unrelated to this work — do not chase these:** `tests/Feature/ServerManifestTest.php`, `tests/Feature/ServicesManifestTest.php`, `tests/Feature/FrontendManifestTest.php` occasionally fail under `--parallel` with `mkdir(): File exists` or snapshot-content-mismatch errors — this is a shared-temp-dir race between parallel Pest workers (`tests/Pest.php`'s `sys_get_temp_dir().'/larakube-snapshot-stable-test'`), completely unrelated to `ClusterTool`. If you see ONLY these files fail, re-run them serially (`./php vendor/bin/pest tests/Feature/ServerManifestTest.php` etc., no `--parallel`) to confirm they pass — if they do, the parallel run's failure was the pre-existing race, not your change.

Also **before writing any new file**, re-read §2's `EnumImportResolutionTest` gotcha — it's already bitten this effort twice (once in `app/Contracts/ClusterToolVendor.php`'s own docblock, once in `app/Contracts/HasBaselineFlags.php`'s docblock).

## 8. Verification checklist per stage

```bash
./php -l app/Enums/ClusterTool.php
./php -l app/Vendors/<EachNewFile>.php   # repeat per new file
./php vendor/bin/pest <targeted test files for this stage's categories>
./php vendor/bin/pest --parallel          # full suite — expect only the known-flaky manifest tests above, if any
./php vendor/bin/phpstan analyse app/     # level 0 per phpstan.neon — must be "[OK] No errors"
git status --porcelain | grep "^ M tests"  # must show ONLY this pre-existing baseline list, nothing new:
#   tests/Feature/BackupCommandTest.php
#   tests/Feature/MailUnwireCommandTest.php
#   tests/Feature/MailWireCommandTest.php
#   tests/Feature/SecretsWireCommandTest.php
#   tests/Feature/SsoUnwireCommandTest.php
#   tests/Feature/SsoWireCommandTest.php
#   tests/Feature/VpnWireCommandTest.php
# (these 7 predate this whole effort — a much earlier, separately-approved
# HasWorkloadComponents/compound-tool-components pass touched them and was
# never committed. If Stage 6/7 adds any NEW file to this list, that is a
# real regression — stop and investigate.)
```

The user (repo owner) has a **hard rule**: never commit unless explicitly asked. Do not commit any of this work — leave it in the working tree for the user to review and commit themselves, exactly as Stages 1–5 were left.

Also a **hard rule** for this specific repo: never write "firearmland" anywhere (unrelated to this task, but scan before any commit if one is ever requested), and Pint must be run as `larakube php vendor/bin/pint` NOT `larakube artpint`. Also: **never** name a vendor class with the "obvious" plural form — see the singular-naming note in §1, it was an explicit correction mid-effort.

## 9. Task tracking

If your tool has a task list, mirror this:
- [x] Stages 1–5 (DataTool, FlowTool, the 5-enum batch, the first 4-class batch, the second 5-class batch)
- [ ] Stage 6: NoteTool, SheetTool, MonitorTool, CrmTool, SupportTool, InsightTool, ErrorTool classes
- [ ] Stage 7: AnalyticsTool, MeetTool, DnsTool, UptimeTool, VpnTool, DashboardTool classes + final cleanup (§6)
