# Wire self-hosted NetBird (VPN) to Zitadel SSO

## Context

LaraKube's VPN tool (`ClusterTool::VPN`, self-hosted NetBird) currently only
supports joining via a shared setup key (`vpn:join`, `vpn:grant`/`vpn:revoke`
for issuing/revoking those keys). Every teammate who joins shares the same
non-identity-based bootstrap mechanism — there's no per-person audit trail
tied to a real identity, and offboarding requires remembering a separate
`vpn:revoke` step instead of just disabling someone in Zitadel like every
other tool in this cluster.

This was explicitly scoped and deliberately deferred in the original SSO
work (`plans/completed/sso-identity-provider.md`): NetBird was one of three
tools (alongside Gitea and GlitchTip) whose OIDC wiring needs CLI- or
API-driven registration instead of plain env vars, and was left as "a
separate follow-up, not a loop over the same mechanism" — `VpnTool::oidcEnv()`
doesn't exist yet (the class doesn't implement `HasOidcWiring` at all). This
plan completes that deferred follow-up.

**Verified live against the actual pinned `netbird-management:0.74.4` image**
(not assumed from possibly-mismatched docs — NetBird's docs describe a newer
"unified `config.yaml`" mode that does NOT apply here): this version still
uses the classic `management.json`, and its REST API already exposes
`GET/POST/PUT/DELETE /api/identity-providers` (confirmed live:
`GET https://vpn.luchtech.dev/api/identity-providers` → `200 []`,
authenticated with the same PAT already stored in `vpn-secrets`). This is
NetBird's modern "Local Users with Optional IdP Integration" model — adding
an external IdP is **additive**, confirmed via NetBird's own Zitadel guide:
*"Adding external IdPs does not disable or replace local authentication."*
This means `EmbeddedIdP`/`bootstrapVpnAuth()`/the existing setup-key flow are
architecturally untouched by this work — a materially safer integration than
Synapse's or OpenBao's SSO wiring, which do rewrite live auth config.

The redirect URI is a **fixed, static path** — confirmed by reading NetBird's
dashboard source directly (`IdentityProviderModal.tsx`):
`{apiOrigin}/oauth2/callback` (logout: `/oauth2/logout/callback`) — not
per-registration-ID-dependent, so there's no chicken-and-egg registration
order problem.

## Scope for v1

**In scope**: `VpnTool implements HasOidcWiring`; `sso:wire vpn` /
`sso:unwire vpn` register/deregister NetBird as a Zitadel-type identity
provider via its REST API; `vpn:join --sso` as an **additive opt-in**
alternative to the existing setup-key flow.

**Explicitly deferred, do not build**: anything that would disable local
auth or setup-key issuance (`--sso-only`-equivalent). There is no
EmbeddedIdP-disabling toggle to even consider in NetBird's multi-IdP model —
local auth has no "off" switch here, so there's genuinely nothing risky to
accidentally wire in. `--sso-only` on `sso:wire` should simply not apply to
VPN (no `sso_only_vars` in its schema).

**Added during implementation**: `ClusterTool::rbacRoles()` gains a
`self::VPN => ['vpn-user' => 'Can join the VPN via SSO']` entry — VPN grants
private network access, not just a web login, a materially higher-stakes gap
than the open-to-org tools (Outline, Kutt, etc.) that only got RBAC gating
reactively after a real 2026-08-20 partner-org-over-access incident. This is
fully generic via `requiresRbacGating()`/`ensureRbacGating()` — no changes to
`wire()`'s control flow, just enum data. Confirmed via `rbacProjectName()`
that VPN's own project name resolves to `'netbird-management'` (its
deployment name), same pattern as every other per-tool RBAC project.

**Default behavior unchanged**: `vpn:join` with no flags keeps working
exactly as today. `--sso` is opt-in, never auto-selected even once wired —
VPN is the access-of-last-resort layer, and a wrong default here risks
confusing failures precisely when someone most needs connectivity.

## Implementation

### 1. New NetBird Saloon request classes (`app/Http/Integrations/Netbird/Requests/`)

Mirror the existing `ListSetupKeysRequest.php` / `CreateSetupKeyRequest.php` /
`UpdateSetupKeyRequest.php` patterns exactly (same `HasTimeout`,
`connectTimeout=60`/`requestTimeout=120`, `HasJsonBody` for POST/PUT). Auth
is already handled by `NetbirdConnector`'s `TokenAuthenticator($pat, 'Token')`
(`Authorization: Token {pat}`, NOT `Bearer` — already correct in the existing
connector, no changes needed there).

- **`ListIdentityProvidersRequest.php`** — `GET api/identity-providers`.
- **`CreateIdentityProviderRequest.php`** — `POST api/identity-providers`,
  constructor `(string $type, string $name, string $issuer, string $clientId, string $clientSecret)`,
  body `{type, name, issuer, client_id, client_secret}`.
- **`UpdateIdentityProviderRequest.php`** — `PUT api/identity-providers/{id}`,
  same body shape. Re-send every field on update — this codebase already
  learned the hard way (see `UpdateSetupKeyRequest.php`'s own docblock) that
  NetBird's PUT endpoints reject partial bodies; verify this empirically for
  identity-providers too rather than assuming it's identical, since the two
  endpoints are different resources.
- **`DeleteIdentityProviderRequest.php`** — `DELETE api/identity-providers/{id}`.

### 2. `app/Vendors/VpnTool.php` — add `HasOidcWiring`

```php
public function oidcEnv(?string $instance = null): ?array
{
    return [
        'deployment' => 'netbird-management',
        'secret' => 'netbird-oidc',
        'vars' => [],
        'redirect_path' => '/oauth2/callback',
    ];
}
```

No `public_client` key (defaults to `false`/confidential) — NetBird's own
Zitadel connector requires a client secret; `zitadelCreateOidcApp()` already
supports this via its existing `$publicClient = false` default parameter
(`app/Traits/InteractsWithZitadelApi.php:272`), no changes needed there.

`vars`/`static` are empty for the same reason `SecretTool` (OpenBao)'s are —
NetBird's OIDC config isn't set via env vars at all, so this schema exists
only to supply `redirect_path` (for `oidcRedirectUris()`) and mark the tool
SSO-capable; the real wiring is hand-written (see §4).

### 3. `app/Enums/ClusterTool.php` — post-logout redirect + stale comment cleanup

Add a `VPN` arm to `oidcPostLogoutRedirectUris()` (currently only handles
`DRIVE`, defaults to `[]` — around line 1037):
```php
self::VPN => ["https://{$toolHost}/oauth2/logout/callback"],
```

Also update the now-stale docblock above `ClusterTool::oidcEnv()` (~line
961-976) that still lists "Gitea/NetBird/GlitchTip need CLI- or API-driven
OIDC registration... aren't wired by this mechanism yet" and references
`plans/active/sso-identity-provider.md` (that file doesn't exist — it's at
`plans/completed/sso-identity-provider.md`) — drop NetBird from that
deferred list now that it's wired, and fix the stale path.

### 4. `app/Commands/Sso/SsoWireCommand.php` — hand-written wire/unwire, mirroring `wireOpenBaoOidc()`/`unwireOpenBaoOidc()`

In `wire()`'s dispatch (~line 291-299), add a third branch alongside the
existing `chat-synapse`/`openbao-backend` ones:
```php
} elseif ($schema['deployment'] === 'netbird-management') {
    $ok = $this->wireNetbirdOidc($kubectl, $schema['namespace'], $ssoHost, $clientId, $clientSecret, $env);
}
```

New `protected function wireNetbirdOidc(string $kubectl, string $ns, string $ssoHost, string $clientId, string $clientSecret, string $env): bool`:
1. Read the PAT via `readClusterSecretKey($kubectl, $ns, 'vpn-secrets', 'pat')`
   (already available — this method is already called elsewhere in this same
   file, e.g. `unwire()` line 477-478).
2. `GET api/identity-providers` via `NetbirdConnector::make($vpnHost, $pat)`,
   find an existing entry with `type === 'zitadel'`.
3. `PUT` to update if found, else `POST` to create — body
   `{type: 'zitadel', name: 'Zitadel', issuer: "https://{$ssoHost}", client_id, client_secret}`.
4. On success, write the `netbird-oidc` marker Secret
   (`--from-literal=client-id=... --from-literal=client-secret=...`) —
   **required**, matching the exact pattern `wireOpenBaoOidc()` (lines
   1431-1444) and `applyCliOidc()` (Forgejo, lines 586-599) both already use:
   `tool:list`'s SSO-wired detection probes for the `{tool}-oidc` Secret, and
   a config-file/API-driven tool is the one case `applyToolEnv()` never
   creates it for automatically.
5. Return the overall success bool.

Mirror in `unwire()`'s dispatch (~line 491-504), matching the
`openbao-backend` branch's shape exactly (early-return after success
message):
```php
if ($schema['deployment'] === 'netbird-management') {
    $this->unwireNetbirdOidc($kubectl, $schema['namespace'], $ssoHost);
    $this->laraKubeInfo("✅ {$tool->getLabel()} no longer uses Zitadel SSO.");
    return 0;
}
```
New `protected function unwireNetbirdOidc(...): void` — read the PAT, `GET`,
find the `zitadel` entry, `DELETE` it. The generic
`Process::run("{$kubectl} delete secret {$schema['secret']}...")` already at
the top of `unwire()` (line 489) handles removing the `netbird-oidc` marker
Secret — don't duplicate that.

### 5. `app/Commands/Vpn/VpnJoinCommand.php` — additive `--sso` flag

Add to the signature:
```
{--sso : Authenticate via Zitadel SSO instead of the shared setup key (run `larakube sso:wire vpn` first)}
```

Branch before the existing setup-key block in `handle()`:
```php
if ($this->option('sso')) {
    if (! Process::run("{$kubectl} get secret netbird-oidc -n {$ns}")->successful()) {
        $this->laraKubeError("NetBird isn't wired to SSO yet — run `larakube sso:wire vpn {$env}` first.");
        return 1;
    }
    if (! $this->installNetBirdClient()) {
        $this->laraKubeError('NetBird client install failed — see the output above.');
        return 1;
    }
    $this->laraKubeInfo('Opening a browser to sign in via Zitadel SSO...');
    passthru('sudo netbird up --management-url https://'.escapeshellarg($host), $exitCode);
    if ($exitCode !== 0) {
        $this->laraKubeError('`netbird up` failed — see the output above.');
        return 1;
    }
    // same success output as the existing path
    return 0;
}
// existing setup-key path, unchanged below
```

Omitting `--setup-key` entirely (not a new subcommand, not `netbird login`)
is what triggers NetBird's own automatic browser-based SSO login on
`netbird up` when the management server has an OIDC provider registered —
confirmed via NetBird's CLI docs. `installNetBirdClient()` is reused
unchanged in both branches.

**Do not touch** the other 3 places that repeat the setup-key
`netbird up --setup-key ...` incantation
(`app/Traits/InteractsWithServerHardening.php:167`,
`app/Commands/Vpn/VpnGrantCommand.php:125`,
`resources/views/k8s/cloud-pilot-deploy.blade.php:325`) — these are
automated/headless contexts (server hardening, cloud-init) that structurally
cannot do an interactive browser SSO login and must stay on the setup-key
flow.

### 6. Tests (ADR 0019: one file per command, extend existing files)

- **`tests/Feature/SsoWireCommandTest.php`** — add a VPN case alongside the
  existing Grafana/Vaultwarden/OpenBao/Synapse/Forgejo cases already there.
  `Http::fake()` both Zitadel's `CreateOidcAppRequest`/`GetProjectAppRequest`
  calls AND NetBird's `GET/POST/PUT api/identity-providers` calls — every
  endpoint hit needs an explicit fake per this repo's own documented
  discipline (CLAUDE.md's ADR 0019 section), or the test silently makes a
  real HTTP call.
- **`tests/Feature/SsoUnwireCommandTest.php`** (if `sso:unwire` is a separate
  command file from `sso:wire`'s `--remove`/unwire path — confirm which
  during implementation) — add the matching VPN teardown case.
- **`tests/Feature/VpnJoinCommandTest.php`** — add `--sso` happy path
  (`netbird-oidc` secret present → `netbird up` runs without `--setup-key`)
  and error path (secret absent → clear error, no `netbird up` attempted).

## Unchanged — verified, do not touch

- `resources/views/k8s/vpn/management-config.blade.php` — no `HttpConfig`
  block needed; `EmbeddedIdP` stays exactly as-is.
- `tests/Unit/VpnManagementConfigTest.php` — its existing
  `->not->toHaveKey('HttpConfig')` assertion is correct and should NOT
  change; that's the legacy/deprecated config path this integration
  deliberately avoids (NetBird's own compiled binary logs
  `"HttpConfig is ignored when EmbeddedIdP is enabled"` — confirmed by
  inspecting the binary at the pinned tag).
- `app/Traits/InteractsWithZitadelApi.php` — `zitadelCreateOidcApp()`
  already supports everything needed (confidential client by default).

## Verification-first steps (do these before writing/relying on final wiring code)

Already confirmed live/via source this planning session — do not re-derive:
- `management.json` format confirmed (no `config.yaml`) via
  `kubectl exec` into the live `netbird-management` pod.
- `/api/identity-providers` reachable and returns `200 []` against the real
  production install, authenticated with the existing PAT.
- Exact request/response schema and the `zitadel` provider-type enum value,
  confirmed via NetBird's OpenAPI spec and Go source at the exact pinned
  tag `v0.74.4` (not a newer/older version's docs).
- Static redirect URI, confirmed via NetBird's dashboard frontend source.
- `netbird up` (no `--setup-key`) auto-triggers browser SSO; `--no-browser`
  exists as a fallback flag — confirmed via NetBird's CLI reference docs.

Still needs a real end-to-end pass on **local (OrbStack)** before touching
production, in this order:
1. `vpn:init local` → `sso:init local` → `sso:wire vpn local`.
2. Confirm via `curl -H "Authorization: Token $PAT" https://vpn.<local-tld>/api/identity-providers`
   that the new `zitadel`-type entry actually appears with `200`, not a
   validation `400` — the OpenAPI spec marks `client_secret` required but
   the Go `Validate()` may not enforce it; confirm empirically rather than
   trusting the spec literally.
3. Confirm re-wiring (running `sso:wire vpn local` a second time) correctly
   updates the existing entry via `PUT`, not duplicates it via `POST`.
4. Run `netbird up` (no setup-key) from a second, never-before-seen Zitadel
   user and observe whether they're JIT-provisioned into the one NetBird
   account or need an explicit invite — this determines what `vpn:join --sso`'s
   error/success messaging should say, and whether any doc update is needed
   for teammates.
5. Confirm plain `vpn:join local` (setup-key, no `--sso`) still works
   completely unaffected after an IdP is registered — expected to be a
   non-event by architecture (setup-key auth bypasses IdP resolution
   entirely in NetBird's own source), but confirm rather than assume.

Only after all 5 pass locally: run `sso:wire vpn` against the real
`larakube-159.89.205.239` production cluster (`vpn.luchtech.dev`).
`sso:unwire vpn` (a `DELETE` call) is the rollback path — safe by
construction since it cannot touch `EmbeddedIdP`/setup-keys.

## Verification (after implementation)

- `./vendor/bin/pest --parallel` — full suite, plus the specific new/extended
  test files individually.
- `./vendor/bin/pint` / `./vendor/bin/phpstan analyse` clean.
- The 5-step local end-to-end pass above, actually executed (not just
  planned) — this is the part that turns "should work per the API docs"
  into "confirmed working," matching how this session's MAS work only
  became trustworthy after live verification against the real cluster.
- Only after local passes: live `sso:wire vpn` + `vpn:join --sso` against
  `larakube-159.89.205.239`, with a real second-person login test if
  possible (to settle the JIT-provisioning question for real, not just for
  one already-existing account).

## Critical files

- `app/Vendors/VpnTool.php`
- `app/Commands/Sso/SsoWireCommand.php` (`wire()`/`unwire()` dispatch +
  new `wireNetbirdOidc()`/`unwireNetbirdOidc()`)
- `app/Enums/ClusterTool.php` (`oidcPostLogoutRedirectUris()`, stale
  docblock cleanup)
- `app/Http/Integrations/Netbird/Requests/` (4 new request classes)
- `app/Commands/Vpn/VpnJoinCommand.php` (`--sso` flag)
- `tests/Feature/SsoWireCommandTest.php`, `tests/Feature/VpnJoinCommandTest.php`
