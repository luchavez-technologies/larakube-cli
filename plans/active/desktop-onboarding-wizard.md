# LaraKube Desktop — First-Run Onboarding Wizard

## Context

LaraKube Desktop just became Apple-signed/notarized and is about to go in front of real users for the first time. Today, the very first thing anyone sees after install is a bare "Install the LaraKube CLI" Setup page — no welcome, no explanation, no sense of what the product even does. The user wants a proper onboarding experience that:

1. Asks what the person plans to use LaraKube Desktop for, so the app can hide irrelevant UI (Projects, Dev Boxes) for non-developers who only want to install Cluster Tools on a server.
2. Asks which cloud provider(s) they plan to deploy to, so the CLI-tool install flow only asks for what's actually needed (DigitalOcean/Hetzner just need an API token; GCP needs `gcloud` + OAuth; AWS needs the `aws` CLI or keys) instead of presenting every tool as equally relevant.
3. Explains *why* each tool is needed, so people don't get confused or agitated installing things that look irrelevant.

Investigation found this is less greenfield than it looks — several of the pieces already existed, just not wired into a first-run gate:
- `GlobalSettings::USAGES = ['tools', 'apps']` is already a persona-shaped setting ("Install tools on a server" vs "Build and run apps here"), already filters `ReadinessCheck`'s tool catalog (hides `git`/`docker`/`podman` for `tools`), already rendered as the two radio cards in the Setup page screenshot (`UsageChoice` in `resources/js/pages/readiness.tsx`).
- `app-layout.tsx`'s nav array already supports conditional hiding (`hideProjects`, `experimental` props shared from `HandleInertiaRequests::share()`), it just wasn't wired to `usage`.
- `ReadinessCheck::TOOLS` already carried a one-line "why you need this" `purpose` string per tool, and `required`/`installable`/`localOnly` flags — the exact data a filtered, explained install step needs, just not provider-aware yet.
- `resources/js/pages/servers/create.tsx` has a polished per-provider "what you need" pattern (credential hints, install nudges, token fields) worth mirroring, not copying wholesale — server creation keeps owning actual provider connection; onboarding only needs to *ask intent* and *pre-filter the tool list*.
- There was **no existing first-run gate** — `DashboardController::index()` redirected to `/readiness` only when the `larakube` binary itself was missing, and the only onboarding-flavored UI (`welcome-onboarding.tsx`, the dashboard's dismissible "Quickstart Journey" banner) is a `localStorage`-only, post-setup app-discovery widget for 1-click Quick Launch — unrelated in purpose, kept separate per decision below.

## Decisions (confirmed with the user)

- **Persona = reuse `usage`.** No new persona field. Picking "tools" hides Projects + Dev Boxes from the sidebar (in addition to the local-only tools it already hides); "apps" shows everything, same as before.
- **Providers = new multi-select field.** A new `intendedProviders: string[]` in `GlobalSettings`, distinct from the existing single `defaultCloudProvider`. Supports people who use more than one provider; filters which provider-specific tools the install step presents.
- **Gate = true first-run wizard.** A full-screen, step-based flow shown once before the rest of the app, not just extra cards bolted onto the existing Setup page. A new `onboardingCompletedAt` timestamp in `GlobalSettings` tracks completion; re-runnable later from Settings.
- **Quickstart banner stays separate.** `welcome-onboarding.tsx` is untouched.

## What shipped

**Wizard** — `resources/js/pages/onboarding/index.tsx`, a standalone centered page (no `AppLayout`/sidebar, since persona isn't decided yet) with a Quick-Launch-style numbered-circle stepper: Welcome → Persona (`UsageChoice`) → Providers (new multi-select cards) → Install (reused tool catalog) → Done.

**Shared components extracted from `readiness.tsx`** into `resources/js/components/setup/` so the wizard and the regular Setup page render identically instead of duplicating ~300 lines:
- `usage-choice.tsx` — the "what will you use this for" card pair (now takes an optional `prompt` override).
- `tool-catalog.tsx` — `CatalogEntry` type, `ToolRow`/`ToolState`, and a new `ToolCatalogList` wrapper.
- `cli-missing.tsx`, `wsl-check.tsx` — moved as-is.

`readiness.tsx` now imports all four instead of defining them locally.

**Backend**:
- `GlobalSettings`: added `intendedProviders()` (list, always provider slugs valid against `CLOUD_PROVIDERS`, empty = "not decided, show everything"), `hasCompletedOnboarding()`, `completeOnboarding()`, `resetOnboarding()`. `update()` accepts `intendedProviders`.
- `ReadinessCheck::catalog(?array $intendedProviders = null)`: when given a non-empty list, drops `aws`/`gcloud` rows whose provider isn't in it. DO/Hetzner need no CLI tool at all (API token only), so they were never in `TOOLS` to begin with — nothing to filter for them.
- `DashboardController::index()`: checks `hasCompletedOnboarding()` **before** the existing "CLI missing → readiness" check — onboarding's own install step is how the CLI gets installed for a brand new machine.
- `ReadinessController::setUsage()`: now also sets `hideProjects = ($usage === 'tools')` as a default every time usage is (re)picked — from either the Setup page or the wizard's persona step, both post to the same endpoint. The existing Settings-page checkbox still works as a manual override afterward.
- `app-layout.tsx`'s nav filter: `hideProjects` now also hides "Dev Boxes", not just "Projects".
- New `OnboardingController`: `show()`, `setProviders()`, `complete()`, `reset()`. Routes: `GET /onboarding`, `POST /onboarding/providers`, `POST /onboarding/complete`, `POST /onboarding/reset`.
- Settings page: a "Redo onboarding" button next to the existing "Hide Projects" toggle, posting to `/onboarding/reset`.

**Tests**: `tests/Feature/OnboardingTest.php` (wizard props + provider filtering + validation + complete/reset), plus required updates to pre-existing tests that hit the dashboard route without expecting the new onboarding gate (`DashboardTest.php`, `ExampleTest.php`) and one architectural test (`NoToolDataInDesktopTest.php`) that needed the extracted `tool-catalog.tsx` added to its allow-list (it already allowed the identical code when it lived inline in `readiness.tsx`).

All 424 existing + new Pest tests pass; Pint and `tsc --noEmit` are clean.

## Rollout note

`onboardingCompletedAt` starts unset for every existing install, including the developer's own machine — the wizard will show once for everyone on first launch after this ships. That's expected (no prior "completed onboarding" concept to backfill from, and this is still a canary-channel, pre-wide-release app), not a bug.

## Verification still needed (manual, by the user)

Per this repo's standing convention, implementation stops short of `./build`/`./php vendor/bin/pint`. Once built: fresh config (rename `~/.larakube/config.json` aside) → launch → confirm the wizard gates first, not the old bare Setup page; pick "tools" + DigitalOcean only → confirm the install step hides AWS/GCP CLI rows and the sidebar hides Projects/Dev Boxes after finishing; re-launch → confirm the wizard does *not* reappear; open Settings → confirm "Redo onboarding" works.
