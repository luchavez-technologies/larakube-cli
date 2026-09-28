# Handoff: workshop frameworks (Desktop) + canonical resource naming (CLI)

Two workstreams are in flight across `desktop/` and `cli/`. Read this first
when resuming (Antigravity or Claude).

## 1. Workshop goal: every framework from LaraKube Desktop

Before the workshop, students (mostly Windows, no PHP/Node) create or add an
app in every deployable framework (Laravel, Next.js, Vite, Astro,
Docusaurus, Statamic, WordPress) and deploy it from the desktop.

- Status table and first steps: `desktop/plans/NEXT-STEPS.md`.
- Checklist: `desktop/plans/active/07-all-frameworks-for-workshop.md`.
- CLI pieces already committed on `develop`: `new:options --json`,
  `new --email`, `init --email` (`applyEmailOption()`), wizard "None" key,
  `laravel new` without `-it` headlessly (`installerCanPrompt()`),
  `tool:list` adopting unregistered tools.
- Open CLI work for it: `WordpressNewCommand` (line ~252) and the .NET,
  AdonisJS and Django scaffolders still run `docker run -it`
  unconditionally, which fails headlessly; `StatamicNewCommand` offers
  `larakube up` after a confirm, which a headless run answers yes.
- The user must run `./build` before any of it works in the desktop.
- **Next and blocking every deploy:** `desktop/plans/active/08-environments-and-server-linking.md`:
  make `larakube env <name>` fully headless (`--context`, `--web-host`,
  `--ingress`, `--managed`, …; never silently pick the first kube-context),
  gate `cloud:deploy`'s `Proceed?` confirm, and add a multi-environment
  section to the desktop project page.

## 2. Canonical resource naming (ADR 0021), the other agent's work

Goal: every Cluster Tool resource is named
`{component}[-{token}]-{instance}` with no category, sourced from
`ToolInstance`, with a zero-data-loss live migration per tool (runbooks, no
migration verbs in the CLI).

State of `ClusterTool::resourceNaming()` (`app/Enums/ClusterTool.php`):

- **CANONICAL (done):** MONITOR, GIT, NOTES, FLOW, SIGN, DATA, LINK,
  ANALYTICS, SHEETS, TASKS, DASHBOARD, MEET, WEBMAIL, DRIVE, VPN.
- **AS_SHIPPED (remaining):** CHAT, PASSWORDS, SSO, RECORD, RESUME, SUPPORT.
- **INSTANCE_SUFFIXED (remaining, the default):** CRM, DNS, ERRORS,
  INSIGHTS, MAIL, SECRETS, UPTIME, DESIGN, PASTE.

Plans: `unified-resource-naming-and-migration.md` (architecture),
`canonical-resource-naming-next-steps.md`, the per-tool
`*-canonical-resource-naming.md` files, `tool-instance-naming.md`,
`pvc-naming-convention.md`, `kubectl-service.md` (lands before ToolInstance
Stage 2).

Rules that bite: never rename live resources ad hoc (fix the code, migrate
through a runbook, verify); SSO and MAIL are foundational, so migrate them
last and carefully; `sso:wire` on a renamed tool must rename the Zitadel
project in place or grants are stranded.

## Shared rules

- The user runs `./build` and pushes. Agents never do.
- Never test destructive flows on `luchtech-vps`; use `gcp-test-vps`.
- `cli/` commits: `git commit --only -- <paths>`; the pre-commit hook runs
  the full suite. Conventional Commits (ADR 0025).
