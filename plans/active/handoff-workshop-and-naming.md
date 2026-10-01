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

- **CANONICAL (code and cluster):** MONITOR, GIT, NOTES, FLOW, SIGN, DATA, LINK,
  ANALYTICS, SHEETS, TASKS, DASHBOARD, MEET, WEBMAIL, DRIVE, VPN, CRM,
  PASSWORDS, CHAT, MAIL.
- **CANONICAL in code, live migration pending:** SECRETS (OpenBao), SSO
  (Zitadel). Runbooks: `openbao-canonical-naming-live-migration.md`, then
  `zitadel-canonical-naming-live-migration.md` (it re-points every OpenBao
  generator and deletes the bridge Service the first one leaves). Also
  `secrets-stale-externalsecrets-cleanup.md` (14 dead objects; delete only).
- **AS_SHIPPED (not installed, code-only later):** RECORD, RESUME, SUPPORT.
- **INSTANCE_SUFFIXED (not installed, code-only later):** DNS, ERRORS,
  INSIGHTS, UPTIME, DESIGN, PASTE.

**Live state, `larakube-159.89.205.239` (read 2026-10-02).** Chat, Mail, CRM,
Vaultwarden, Forgejo, Grafana stack, Documenso, Headlamp, LiveKit, n8n, oCIS,
Outline, NetBird, Bulwark are canonical and verified. Left on the old names:
OpenBao (`openbao-backend`, `openbao-data`, `openbao-bootstrap`) and Zitadel
(`sso-zitadel`, `sso-secrets`, database `zitadel`, tools registry row with an
empty instance). Not installed, so code-only: RECORD, RESUME, SUPPORT, DESIGN,
ERRORS, INSIGHTS, UPTIME, PASTE. Leftover: PVC
`pocketbase-storage-data-luchtech-dev` (PocketBase is not registered; check it
for data before deleting). Known remaining non-canonical code: the shared
ForwardAuth proxy is now `proxy-{instance}` but only the AS_SHIPPED RECORD tool
uses it; `forDeployment()` still matches bare, instance-less names such as
`vaultwarden`, which is how a dead leftover Deployment can abort `backup:run`.

Plans for this: the order and recipe are the memory note
`project_naming_convention_no_category` and the proven runbook
`plans/completed/vpn-canonical-naming-live-migration.md`; the finished
Chat, CRM, Vaultwarden, Forgejo and Documenso runbooks are beside it in
`plans/completed/` (Mail's stays in `plans/active/` until its last
people-checks are confirmed). The PVC half is
`pvc-naming-convention.md` (done for every migrated tool). Also open: `tool-instance-naming.md`,
`kubectl-service.md` (lands before ToolInstance Stage 2). Finished or
superseded naming plans (`unified-resource-naming-and-migration.md`,
`canonical-resource-naming-next-steps.md`, the per-tool
`*-canonical-resource-naming.md`, `drive-canonical-naming-live-migration.md`)
are in `plans/completed/`.

Rules that bite: never rename live resources ad hoc (fix the code, migrate
through a runbook, verify); SSO and MAIL are foundational, so migrate them
last and carefully; `sso:wire` on a renamed tool must rename the Zitadel
project in place or grants are stranded.

## Shared rules

- The user runs `./build` and pushes. Agents never do.
- Never test destructive flows on `luchtech-vps`; use `gcp-test-vps`.
- `cli/` commits: `git commit --only -- <paths>`; the pre-commit hook runs
  the full suite. Conventional Commits (ADR 0025).
