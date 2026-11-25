# Handoff: move the remaining apps into LaraKube Cloud's template catalog

For Antigravity. Claude reviews the result next session. Read this whole file first.

## Context (what is already done)
- **LaraKube Cloud is THE source of app templates** (hard rule). An app Cloud installs must have
  signed releases in Cloud's catalog and be on the LaraKube CLI's `config('templates.downloadable')`
  list, so the CLI downloads it instead of using a bundled copy.
- Done and live-tested locally (download + signature verified in a runner pod): `external-dns`,
  `openbao`, `plex` (Plex Commons), `n8n`, `pocketbase`, `wordpress`, `erpnext`.
- Pieces that exist:
  - `app/Support/Templates/TemplateApp.php`: wraps a ClusterTool or Plex Commons for publishing/downloading.
  - `app/Support/Templates/ReleaseManifestBuilder.php`: pins every image the app declares (`HasImages`).
  - `app/Commands/Cloud/CloudAppsInstallCommand.php`: `cloud:apps:install`, `APPS` allow-list, runs
    `tool:init --tool=<app>` (Plex: `plex:init`) passing only flags the app's `ToolInitSpec` accepts.
  - `tests/Feature/AppImagePinsTest.php`: asserts each declared image appears literally in the app's
    template folder. **This is the self-check for your work.**
  - Cloud side (`../cloud`): `database/seeders/CatalogAppSeeder.php` (`APPS` const) holds each app's
    name, category, description, `installable`, address `prefix`, `options`.

## Your task: the mechanical part, batch 1 then batch 2
Batch 1 (standalone, simple): kuma, vaultwarden, outline, umami, yopass, planka, kutt, teable, documenso, resume.
Batch 2 (larger, review images carefully): supabase, directus, windmill, twenty, grafana, forgejo, metabase, glitchtip, penpot, chatwoot, sendrec.

For EACH app (CLI repo unless noted):
1. **Declare its images.** Find the app's vendor class (`ClusterTool::<APP>->vendor()`; see
   `app/Vendors/*`, `app/Enums/DataTool.php`, `app/Tools/*`). If it does not implement
   `App\Contracts\HasImages`, add `HasImages`, `use App\Tools\Concerns\PinsImages;`, and an
   `images(): array` returning key => the EXACT image string the templates under
   `resources/views/k8s/<folder>/` already use. Enums with several cases: `match ($this)` with
   `default => []` (see DataTool).
2. **Add it to `tests/Feature/AppImagePinsTest.php`'s dataset.**
3. **Add its slug to `config/templates.php` `downloadable`.**
4. **Add its slug to `CloudAppsInstallCommand::APPS`.**
5. **Cloud repo:** add an entry to `CatalogAppSeeder::APPS` (name, category, one-line description,
   `installable` true, prefix = the subdomain its ToolInitSpec domain help suggests, options null),
   then add a new migration like `2026_11_25_060031_seed_official_catalog_apps.php` that calls the
   seeder again (one migration for the whole batch).

## Hard rules (do not break)
- **Never change an image tag, version or registry.** If an image looks stale or uses an unusual
  registry, leave it and list it in your report. (CLI rule: real latest release must be checked
  before any pin change; a stale-pin incident is documented.)
- **Do not edit any template (`resources/views/k8s/**`).**
- If an app's template builds its image from PHP (e.g. `{{ $image }}` from a variable), so the image
  is not a literal in the folder, **skip the test dataset entry for it and list it in the report**.
  Do not hack the test.
- If an app needs another app to work (bulwark→stalwart, netbird, matrix, headlamp, zitadel,
  stalwart, livekit, ocis), **skip it**; Claude handles those.
- **Do not run PHPStan at all** (it wipes the result cache; the commit hook handles it).
- Do not run the full suite, `./build`, or anything against a cluster. You may run only:
  `./vendor/bin/pest tests/Feature/AppImagePinsTest.php tests/Feature/CloudAppsInstallCommandTest.php`
  and `./vendor/bin/pint <changed files>`.
- **Do not commit, push, or publish releases.** No `templates:publish`.
- No dates or history in code comments. Say "LaraKube CLI", not "LaraKube" alone.

## Report back (write to `plans/active/handoff-cloud-template-catalog-apps.report.md`)
- Per app: done / skipped (+why), images declared.
- Every image you thought was stale or odd (not changed).
- Test output of the two pest files.

## What Claude does next session (not you)
Review diffs; commit via the hook; `templates:publish` each app to local Cloud with the dev key;
`runner/scripts/dev.sh build --without=<apps>` and `dev.sh test-fetch <app>`; dependency apps;
deciding free/paid tiers with the user.

## Other open items (for Claude, not Antigravity)
- Uncommitted LaraKube CLI fix: `build` script now copies `~/.larakube/bin/larakube` via temp file +
  rename (overwriting in place made macOS SIGKILL it).
- Runner repo: 2 local commits unpushed (allow `cloud:apps:install`; dev image uses local entrypoint).
- Cloud `develop` commits to be pushed by the user. ERPNext seeded as free; user to decide tiers.
- Publishing to deployed Clouds needs the user's GitHub env setup (upload tokens, LARAKUBE_CLOUD_URL,
  public keys registered) then the "templates" workflow.
- OrbStack VM has 1.9 GiB; user to raise to 6–8 GB.
- Live install test needs a NEW server from cloud.test (old ones lack install trust).
- ESO template carries upstream `managed-by: Helm` labels; optional fix to relabel `larakube`.
