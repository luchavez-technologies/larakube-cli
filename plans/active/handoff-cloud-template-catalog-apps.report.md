# Report: Cloud Template Catalog Apps (all batches)

For review by Claude. Nothing is pushed. Nothing is published to any Cloud.

## 1. Read this first

**Commits to review**

| Repo | Branch | Commit | What |
|---|---|---|---|
| `cli` | `feat/cloud-installer-trust` | `c0ff49d5` | 21 apps (Batch 1 and 2), unshipped gate removed, Plausible dropped |
| `cli` | same | `6c794493` | final 8 apps, 4 tag pins, 3 registries |
| `cli` | same | next commit | ADR 0037 and this report |
| `cloud` | `develop` | `454bfbf` | 21 catalog entries, migration seeding removed, deploy seeder |
| `cloud` | `develop` | `9df73f4` | final 8 apps, `requires` gate, registries, ERPNext prefix |

(`032a055b` is another agent's backup work. Not part of this task.)

**Deviations from the handoff rules.** Each was requested by the user in this session.

| Handoff rule | What was done | Authorised by |
|---|---|---|
| Never change an image tag, version or registry | Pinned Kuma, Umami, Directus and Penpot to exact tags; allowed 3 more registries | User, "yes" to fixing floating tags |
| Do not edit templates | Edited the Kuma, Umami, Directus and Penpot templates (tag only); Kuma template also got resource requests/limits so `ToolResourceRequestsTest` passes without the shipped gate | User, same |
| Skip the dependency apps | Added all 8: Zitadel, Stalwart, Bulwark, NetBird, Headlamp, LiveKit, oCIS, Matrix | User approved the plan |
| Seed through a migration | Deleted the migration; Cloud now seeds on deploy with `AUTORUN_LARAVEL_MIGRATION_SEED` | User |
| Keep the "unshipped" gate | Removed `RefusesUnshippedTools`, `isShipped()`, `shippedCases()`, and `ClusterTool::PLAUSIBLE` with its commands | User |
| No PHPStan, full suite or commits | All run. The pre-commit hooks require them | User asked for commits |

## 2. What changed, per layer

**CLI**
- `HasImages` + `PinsImages` + `images()` on 27 vendor classes or enums (19 earlier, 8 now: `SsoTool`, `MailTool`, `WebmailTool`, `VpnTool`, `DashboardTool`, `MeetTool`, `OcisTool`, `ChatTool`).
- `config/templates.php` `downloadable` and `CloudAppsInstallCommand::APPS`: 35 apps plus `plex` (36 slugs; `external-dns` is downloadable but not installable).
- `ImageDigestResolver::ALLOWED_REGISTRIES`: added `codeberg.org`, `code.forgejo.org`, `oci.element.io`.
- `ToolFootprint` and the Kuma template: resource requests for Kuma and Umami.
- Removed Plausible and the unshipped gate across commands, traits and tests; `tests/Fixtures/tool-init-signatures.json` updated.
- ADR `0037-catalog-app-prerequisites-and-pinned-images.md`, indexed in the README.

**Cloud**
- `CatalogAppSeeder`: 36 entries, all `AccessTier::Free` on creation (tier is never reset, so the admin UI toggle survives deploys). ERPNext prefix `erp` to `erpnext`. Bulwark has `options.requires = ['stalwart']`.
- `DatabaseSeeder`: only `CatalogAppSeeder`; the `test@example.com` factory user is gone. Idempotent, tested twice-run.
- `Dockerfile.php` and `.infrastructure/k8s/base/laravel.yaml`: `AUTORUN_LARAVEL_MIGRATION_SEED=true`.
- `InstallableApps` exposes `requires`; `ServerController` passes it to the page; `InstallServerAppRequest` rejects an install whose prerequisite is not `installed` on that server; `servers/show.tsx` disables Install and names the missing app.
- `ImageDigestResolver::REGISTRIES`: same 3 registries as the CLI.

## 3. Declared images per app

Source of truth is each vendor's `images()`.

| App | Images |
|---|---|
| kuma | `louislam/uptime-kuma:1.23.17` (pinned, was `:1`) |
| vaultwarden | `vaultwarden/server:1.37.5` |
| outline | `outlinewiki/outline:1.9.2` |
| umami | `ghcr.io/umami-software/umami:postgresql-v2.20.2` (pinned, was `:postgresql-latest`) |
| yopass | `jhaals/yopass:14.8.0` |
| planka | `ghcr.io/plankanban/planka:2.2.1` |
| kutt | `kutt/kutt:v3.2.6` |
| teable | `teableio/teable:release.2026-07-26T01-04-56Z.2377` |
| documenso | `documenso/documenso:v2.16.0` |
| resume | `amruthpillai/reactive-resume:v5.2.5` |
| supabase | studio `2026.11.02-sha-a05e7ec`, envoy `v1.39.6`, gotrue `v2.197.0`, postgrest `v14.18`, realtime `v2.148.3`, storage-api `v1.89.12`, imgproxy `v4.0.21`, postgres-meta `v0.100.0`, edge-runtime `v1.77.9`, postgres `17.6.1.136`, supavisor `2.9.13` |
| directus | `directus/directus:12.7.0` (pinned, was `:12`) |
| windmill | windmill `1.770.0`, lsp `1.134.1`, `postgres:15-alpine` |
| twenty | `twentycrm/twenty:v2.35.0` |
| grafana | grafana `10.4.2`, prometheus `v2.51.2`, loki `3.0.0`, promtail `3.0.0`, tempo `2.10.7`, kube-state-metrics `v2.12.0` |
| forgejo | forgejo `16.0.4` (codeberg.org), runner `13.1.0` (code.forgejo.org), podman `v5.8.4`, job `node:24-trixie` |
| metabase | `metabase/metabase:v0.63.1` |
| glitchtip | glitchtip `6.2.2`, `postgres:15-alpine`, `valkey/valkey:8.0-alpine` |
| penpot | backend, frontend, exporter `2.17.2` (pinned, was `2.17`) |
| chatwoot | `chatwoot/chatwoot:v4.16.2` |
| sendrec | `ghcr.io/sendrec/sendrec:v1.89.1` |
| zitadel | zitadel `v4.16.1`, oauth2-proxy `v7.15.3`, `postgres:15-alpine`, `busybox:1.36` |
| stalwart | `stalwartlabs/stalwart:v0.16.14` |
| bulwark | `ghcr.io/bulwarkmail/webmail:1.7.8` |
| netbird | management, signal, relay, client `0.77.1`; dashboard `v2.91.1`; `alpine/socat:1.8.1.3`; `coredns:1.14.6` |
| headlamp | `ghcr.io/headlamp-k8s/headlamp:v0.29.0` |
| livekit | `livekit/livekit-server:v1.13.5`, `ghcr.io/element-hq/lk-jwt-service:0.5.0` |
| ocis | `owncloud/ocis:8.0.6`, `collabora/code:26.04.3.2.1` |
| matrix | synapse `v1.159.0`, element-web `v1.12.26`, coturn `4.17.2-alpine`, MAS `1.23.0`, element-admin `0.1.12` (oci.element.io), `postgres:15-alpine` |

## 4. Not covered by `AppImagePinsTest` (images are not literals in the templates)

`supabase`, `windmill` (images via `$tool->image()`), `forgejo` (versions from `ForgejoInitCommand` constants), `zitadel` (`proxy.blade.php` uses `{{ $image ?? 'v7.15.3' }}`), `ocis` (`$ocisImage`, `$codeImage`), `matrix` (`$masImage`). For these the declared images in section 3 were taken from the vendor class and the init command constants, and checked against their registries (section 5), not against the template text. **Worth a manual look.**

## 5. Image verification

Ran `ImageDigestResolver::resolve()` on every declared image of all 36 slugs against the live registries: **91 images, 0 failures**, including the three newly allowed registries. Floating-to-exact pins were chosen by comparing digests: `uptime-kuma:1` is a floating tag whose digest matched no listed 1.23.x patch in the last 8 (1.23.17 is the newest 1.23 tag; `:1` may point to a newer 1.x or 2.x line, so **Claude should confirm `1.23.17` is what we want, since it was not an exact digest match**). Exact digest matches: Directus `12.7.0`, Penpot `2.17.2`. Umami: `postgresql-latest` also did not exactly match a listed v2 tag in the last 8; `postgresql-v2.20.2` is the newest v2 tag listed, so it is a best guess, not a proven match. Both need a review decision.

## 6. Still floating or old (left as is)

`postgres:15-alpine` (windmill, glitchtip, zitadel, matrix), `busybox:1.36` (wordpress, zitadel), `node:24-trixie` (forgejo job), `postgrest/postgrest:v14.18`, Plex `mysql:8.4`, `mariadb:11.8`, `meilisearch:v1.12`, `seaweedfs:4.20`. Grafana, Prometheus, Loki and Promtail are 2024-era pins next to Tempo `2.10.7`. Teable uses a dated build tag. Several semver tags were not checked against the upstream release lists, only that the manifest exists: vaultwarden `1.37.5`, metabase `v0.63.1`, twenty `v2.35.0`, documenso `v2.16.0`, reactive-resume `v5.2.5`, chatwoot `v4.16.2`, sendrec `v1.89.1`, outline `1.9.2`.

## 7. Known gaps and risks

- **Not published.** No `templates:publish`, no runner `test-fetch`. It needs the dev signing secret, which was not available. Cloud's local DB has keys `dev-local`, `dev-local-2`, `e2e-local`, `local-dev`. Local Cloud has published releases only for the 7 earlier apps.
- **Bulwark gate seen only in tests.** Backend rule is covered by a Pest test (`ServerAppInstallTest`). The disabled button and message were checked by `tsc` and lint only, not in a browser.
- **`requires` counts only Cloud's own record** of installed apps on that server.
- **Documenso** calls `ensureCommons(['postgres', 'seaweedfs', 'headless-chrome'])`, but `CloudAppsInstallCommand::PLEX_SERVICES` lists only `postgres, redis, mariadb, seaweedfs`. An install on a server with no Commons may not provision headless Chrome. Not tested.
- **Several apps need Commons first** (Stalwart, oCIS, Matrix, NetBird and others on Commons Postgres or SeaweedFS). Cloud's Apps tab does not require Plex Commons to be installed before them. Not handled in this task.
- **Matrix** deploys MAS and Element Admin only once Zitadel is present, so the catalog entry works without Zitadel but with fewer parts.
- **Scratch scripts** (outside the repos): `audit_images.php` re-checks every declared image; `find_exact_tags.php` finds exact tags for floating ones.

## 8. Test results

- CLI `6c794493`: pre-commit hook, Rector and Pint clean, PHPStan 0 errors, Pest 4,122 tests, 4,118 passed, 4 skipped.
- Cloud `9df73f4`: `vp check`, `tsc --noEmit`, Pint, PHPStan, Pest 286 passed.
- Added tests: `AppImagePinsTest` (26 datasets), `TemplateImageDigestResolverTest` (3 registries), Cloud `ServerAppInstallTest` (Bulwark requires Stalwart), Cloud `AppReleasePipelineTest` (registries; deploy seeder idempotent twice with an admin tier change kept).

## 9. Suggested review order

1. Section 1 deviations, then section 5 (`kuma` and `umami` pin guesses).
2. `ClusterTool::templateContracts()` unchanged: all new apps use contract `[1]`.
3. Cloud `InstallServerAppRequest` and `show.tsx` for the `requires` gate.
4. ADR 0037.
5. Then publish locally with the dev key and run `dev.sh test-fetch` for the 8 apps without literal images first (section 4).
