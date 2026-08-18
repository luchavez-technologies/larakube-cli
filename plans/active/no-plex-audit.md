# Audit: `--no-plex` coverage + multi-instance `:wire` verification

> **Status:** Not started — queued explicitly for a future session (raised 2026-08-18,
> right after `monitor:init` gained `--no-plex` as part of the Grafana persistence fix).
> This file is the starting inventory + questions to resolve, not a finished design. Two
> related-but-distinct audits live here — §2-5 cover `--no-plex`, §6 covers whether
> `secrets:wire`/`sso:wire`/`mail:wire` actually work against a real multi-instance
> install, not just look correct on read.

## 1. Why this exists

Tonight's Grafana fix (`monitor:init` defaulting to Commons Postgres, with `--no-plex`
falling back to a PVC) surfaced a broader inconsistency: `--no-plex` — "bypass Plex
Commons, use local PVC/bundled storage instead" — exists on some Commons-backed tools
and not others, with no visible rule for which get it. Nobody has checked whether that's
deliberate (a real architectural reason) or just organic drift (whoever built the tool
remembered the flag or didn't).

`git:init` is the reference implementation: `--no-plex` swaps Commons S3 buckets +
Postgres for local PVC storage, cleanly, with its own `usesBundledStorage()` detection
on the remove side so `--purge` doesn't try to drop a Commons tenant that was never
allocated.

## 2. Starting inventory (grepped 2026-08-18 — re-verify before acting, this drifts)

**Have `--no-plex` today:**
Chat, Desk (FreeScout), Errors (GlitchTip), Flow (n8n/Windmill), Drive (oCIS), Git
(Forgejo), Insights (Metabase), Monitor (Grafana — shipped tonight), Sso (Zitadel).
(`Statamic`/`Wordpress` also carry the flag but are `new`-style app scaffolds, not
`ClusterTool` cluster tools in the same sense — probably a different question, keep
them out of this audit unless investigation says otherwise.)

**Genuinely Commons-backed (`HasCommonsDatabases` and/or `HasCommonsBuckets`) but NO
`--no-plex` today — the real candidate list:**

| Tool | Commons DB | Commons buckets |
|---|---|---|
| CRM (Twenty) | yes | yes |
| Data (Directus/PocketBase) | yes | yes |
| Design (Penpot) | yes | yes |
| Link (Kutt) | yes | no |
| Mail (Stalwart) | yes | yes |
| Notes (Outline) | yes | yes |
| Password (Vaultwarden) | yes | no |
| Record (Sendrec) | yes | yes |
| Resume (Reactive Resume) | yes | yes |
| Sheet (Teable) | yes | yes |
| Sign (Documenso) | yes | yes |
| Support (Chatwoot — distinct product from Desk/FreeScout, confirmed via getLabel()) | yes | no |
| Tasks (Planka) | yes | ? (re-check, count was ambiguous) |

**No Commons dependency at all (`--no-plex` presumably N/A, not a real gap):**
Meet (LiveKit), Webmail (Bulwark/ForwardAuth).

This table was built with a fast grep for interface names, not a read of each file —
treat every row as "needs a real look," not settled fact. In particular: confirm Support
vs Desk aren't the same underlying FreeScout install counted twice, and confirm Tasks'
actual bucket status.

## 3. Real questions this audit needs to answer, per tool

1. **Does it make sense for this tool to run without Plex at all?** Some tools are
   arguably foundational enough (Mail, SSO) that "no Commons available" might mean "don't
   install yet" rather than "fall back to bundled storage" — worth deciding per tool, not
   assuming `git:init`'s answer applies everywhere. (Note: SSO already has `--no-plex`,
   so at least that precedent exists for foundational tools.)
2. **If yes, what does bundled/local storage look like for it?** A PVC for the DB (like
   `git:init`'s `--no-plex`, and tonight's Grafana fallback)? A bundled sidecar DB
   container (the `{tool}-db` pattern `usesBundledStorage()` checks for elsewhere, e.g.
   `chat-synapse-db`, `desk-freescout-db`)? Depends on whether the tool's own image
   supports an embedded DB story or needs a real Postgres/MySQL next to it.
3. **Does `{tool}:remove --purge` already have a correct `usesBundledStorage()` override**
   for whichever bundled-storage shape gets built? Getting this wrong either leaves a
   Commons tenant undropped forever, or — worse — attempts to drop a Commons tenant a
   `--no-plex` install never allocated (a real footgun if the naming happens to collide
   with a DIFFERENT tool's or instance's tenant).
4. **Buckets, not just databases** — a tool with `HasCommonsBuckets` and no `--no-plex`
   has the same "S3 or bust" gap `git:init` solves for Forgejo's storage/packages/lfs
   buckets. Don't fix the DB half and leave buckets still hard-requiring Commons.
5. **Is there a cheaper answer than a full audit-and-build pass?** e.g. a shared
   `EnsuresPlexOrFallback` trait that gives every `{tool}:init` the same `--no-plex`
   behavior structurally, rather than 13 more hand-rolled copies of what `git:init`
   and `monitor:init` each wrote inline tonight. Worth at least 30 minutes of design
   thought before starting the mechanical per-tool work — 13 more inline copies is a
   lot of near-identical code to maintain and re-verify individually.

## 4. Suggested approach for the future session

1. Re-run and verify the inventory in §2 for real (read each vendor file, don't trust
   the grep counts).
2. Answer §3.5 first — decide whether a shared trait/helper is worth building before
   touching any individual tool, since that changes the shape of everything after it.
3. Pick ONE tool as a pilot (Sheet or Design are good candidates — both DB+bucket,
   neither foundational-infra-critical the way Mail/SSO are) and build+ship it fully
   (code, tests, live verification) before repeating for the rest, rather than a big-bang
   change across 13 tools at once. Matches how `--no-plex` for Monitor and the ADR 0018
   wiring fix were each done tonight: one thing, fully verified, before moving to the
   next.
4. `{tool}:remove`'s `usesBundledStorage()` coverage should ship in the SAME pass as each
   tool's `--no-plex`, not as a follow-up — the Grafana fix tonight built both together
   for exactly that reason (a `--no-plex` install with no matching remove-side detection
   is worse than no `--no-plex` at all, since `--purge` would then either fail loudly or
   silently no-op against a database that doesn't exist).

## 5. Done when (`--no-plex` part)

- Every tool in the §2 candidate list has either a real `--no-plex` (with matching
  `usesBundledStorage()` on its `:remove` command) or a documented reason it deliberately
  doesn't (e.g. "foundational, no-Commons install isn't a supported configuration").
- No tool's `--purge` can attempt to drop a Commons tenant/bucket that a `--no-plex`
  install never allocated.
- Pest coverage per tool mirrors what `MonitorInitCommandTest`/`GitInitCommandTest`
  already do for their `--no-plex` paths.

## 6. Multi-instance `:wire` verification (raised 2026-08-18, separate from `--no-plex`)

Prompted by fixing `secrets:wire`'s ExternalSecret naming for Monitor (`monitor-secrets-db`,
not a hand-guessed `monitor-db`) — the question came up whether `secrets:wire`/`sso:wire`/
`mail:wire` actually work correctly against a tool with more than one instance running
(the 4 multi-instance-capable tools per ADR 0012: Design, Notes, CRM, Data).

**On paper the design already handles this** — read, not assumed, 2026-08-18:
- `secrets:wire` resolves `$instance` via `resolveInstanceForDomain($kubectl, $tool, $domain)`
  (its own `--domain=` flag) and threads it through every downstream call:
  `dbSecretRef($instance, $engine)`, `commonsDatabases($instance, $engine)`,
  `deploymentName($instance, $engine)`. The dynamic-credential ExternalSecret name
  (`"{$ref['secret']}-db"`) is derived from the already-instance-suffixed secret name, so
  a second Design instance would correctly get `design-secrets-design-luchtech-dev-db`,
  never colliding with a bare `design-secrets-db`.
- `sso:wire` resolves the same way: `$instance = resolveInstanceForDomain(...)` before
  `$tool->oidcEnv($engine, $instance)`, so `applyToolEnv()` (the method ADR 0018 fixed the
  same night) only ever sees already-instance-suffixed `$schema['secret']`/
  `$schema['deployment']` — never a hardcoded name. `mail:wire` mirrors this.

**What's NOT confirmed**: none of this has been exercised **live** against a real second
instance of the same tool — e.g. two Design installs on one cluster, both independently
wired to OpenBao static-role rotation via `secrets:wire --tool=design --domain=<host>`,
verified to end up with two genuinely separate ExternalSecrets/roles/passwords that don't
step on each other. This is a "verify it actually works," not a "build it" gap — the
structure reads correctly, but reading correctly and behaving correctly under a live
3-way OpenBao/ESO/Zitadel interaction are different claims, and this class of bug (wrong
name → silently races or clobbers a DIFFERENT resource) is exactly what bit Forgejo/
Zitadel/Documenso earlier this same week (see [[project_openbao_db_static_role_rotation]]).

### Suggested verification

1. Stand up a second instance of ONE multi-instance tool (Design is the best-understood
   one from tonight — `design:init --domain=<second-host>`).
2. Run `secrets:wire --tool=design --domain=<second-host>` and confirm: the correct
   instance-suffixed ExternalSecret/role get created, the FIRST instance's rotation is
   completely untouched (check its ExternalSecret's `refreshTime`/password didn't change),
   and Grafana... no — check the second instance's own Penpot pod actually authenticates
   against Postgres afterward (not just that the command reported success).
3. Repeat for `sso:wire --tool=design --domain=<second-host>` — same isolation check,
   plus confirm the FIRST instance's Zitadel OIDC app/redirect URIs are untouched.
4. If this reveals a real bug, it likely affects all 4 multi-instance tools uniformly
   (same shared `applyToolEnv()`/`wireTool()` code path) — fix once, not per-tool.
