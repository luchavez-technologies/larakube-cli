# Plan: Self-hosted team chat (Slack/Teams alternative)

## 🎯 Objective

`chat:init` — a self-hosted **Mattermost** deployment into `larakube-shared`,
slotting in exactly like `desk:init`/`insights:init`/`errors:init` already do:
Plex Commons Postgres for the DB, Commons S3 (SeaweedFS/MinIO) for file
uploads, Stalwart for outbound notification email via `mail:wire`. One cluster
-wide instance, not per-project — same tier as FreeScout/Metabase/GlitchTip.

## 🔍 Why Mattermost over the alternatives

| | DB | Notes |
|---|---|---|
| **Mattermost** ✅ | **Postgres** | Single container, MIT-licensed compiled binary, fits `DatabaseDriver` exactly |
| Rocket.Chat | MongoDB | `DatabaseDriver` enum only supports Postgres/MySQL/MariaDB — would need a new engine for one tool |
| Zulip | Postgres + **RabbitMQ** | A third moving service just for this one tool |
| Matrix/Element | Postgres | Federation protocol is real overkill for "give my team channels + DMs" |

Rocket.Chat's Community Edition is also capped at 100 concurrent users with
push notifications routed through *their* cloud gateway unless you bring your
own APN/FCM certs — a SaaS dependency baked into the "free" tier that
Mattermost's Team Edition doesn't have.

## 💸 Licensing — what's actually free, and what triggers a bill

Verified directly against Mattermost's docs (2026), not assumed:

- **Team Edition** — MIT-licensed compiled binary, self-hosted, **free forever,
  no license key, no registration**. The "250 users" figure is a *soft
  deployment guideline* (docs cite it being "over-deployed to thousands,
  causing performance issues"), not a technically enforced or licensing cutoff.
  As of 2025 it **lost SSO support** — positioned purely for small
  teams/hobbyists without an SSO requirement.
- **Mattermost Entry** — a newer free tier (still $0) with the richer
  Enterprise Advanced feature set (Playbooks, Boards, Calls, AI Agents), but
  requires **registering a free commercial license key** with Mattermost, and
  has hard usage caps (10,000 messages history server-wide, etc.).
- **Professional** ($10/user/month) — the actual paywall: **SSO (SAML/OIDC/
  OAuth2) and AD/LDAP sync** live here, plus Entry's usage caps lift.

**Default `chat:init` to Team Edition.** Zero registration friction matches
every other `*:init` in LaraKube (nobody creates an account anywhere to run
`desk:init`). Document — don't automate — the manual upgrade path to Entry
(register a free license key in System Console) for anyone who wants
Playbooks/Boards/Calls/AI Agents later. SSO is a real paid feature, not
something to route around; if a team needs it, that's a deliberate `$10/user`
decision they make themselves, same posture as Brevo→SES→Cloudflare in
`mail:relay` — LaraKube gets you to a working default, not around a vendor's
actual paywall.

## 🧱 Design

- **`SharedClusterService::CHAT`** + **`ClusterTool::CHAT`** ('chat') — same
  dual-registration every shared tool has: `SharedClusterService` owns the
  ingress host/template/namespace-probe machinery `up`'s reconciler uses;
  `ClusterTool` owns the `tool:add`/`tool:remove` dispatch + label + (new)
  `smtpEnv()`.
- **Namespace**: `larakube-shared` — matches Desk/Insights/Errors/Secrets/
  Monitor. Not per-project; this is cluster infra, same tier as FreeScout.
- **Database**: Plex Commons Postgres via the existing `ensureCommons(['postgres'])`
  → `allocateDatabase(DatabaseDriver::POSTGRESQL, 'mattermost', $dbPassword)`
  pair every Plex-backed tool already uses (`InteractsWithPlex`). `--no-plex`
  falls back to a bundled Postgres pod, matching Desk's `--no-plex` shape.
- **File storage**: reuse `git:init`'s exact S3 pattern — detect the Commons'
  enabled S3 service (SeaweedFS default, MinIO fallback) via
  `enabledCommonsServices()`, `allocateStorageBucket($driver, 'chat-storage')`,
  wire `MM_FILESETTINGS_*` (`DriverName=amazons3`, endpoint, bucket,
  access/secret key) into the manifest — Mattermost speaks S3 natively, no new
  integration code needed beyond the env mapping.
- **Outbound email**: add a `CHAT` arm to `ClusterTool::smtpEnv()` mapping
  Mattermost's SMTP settings (`MM_EMAILSETTINGS_SMTPSERVER`, `SMTPPORT`,
  `SMTPUSERNAME`, `SMTPPASSWORD`, `ENABLESMTPAUTH=true`,
  `CONNECTIONSECURITY=STARTTLS`) — `larakube mail:wire chat` then works for
  free, same as `flow`/`sheets`/`passwords` today. `tool:add`'s
  `offerMailWiring()` picks it up automatically once `smtpEnv()` is non-null.
- **Context resolution**: built on **`DeploysClusterTool`** (this session's
  trait) from day one — `resolveToolContext()` for deploy/remove,
  `removeResources()` for every teardown step. A brand-new tool has no excuse
  to reintroduce the wrong-cluster-targeting or swallowed-failure bugs the
  other 13 tools just got fixed for.

## 🛠 Commands

```bash
larakube chat:init [environment] [--context=] [--domain=] [--no-plex] [--vpn-only] [--remove]
larakube tool:add chat        # same interactive dispatcher as every other tool
larakube tool:remove chat
larakube mail:wire chat       # SMTP notifications through Stalwart
```

## ♻️ Reuse (most of the machinery already exists)

- `InteractsWithPlex` — `ensureCommons()`, `allocateDatabase()`,
  `allocateStorageBucket()`, `buildDropTenantSql()`, `commonsAdminClient()`,
  `readCommonsS3Credentials()` (all already used by `git:init`/`desk:init`).
- `DeploysClusterTool` — `resolveToolContext()`, `removeResources()`.
- `SharedClusterService` / `ClusterTool` enums — this is a case addition, not
  new machinery (per the "maximize the enums" convention).
- Host resolution (`resolveXxxHost()` / `promptForCloudXxxHost()`) and the
  `resolveEnvironment()` boilerplate — copy the Desk/Insights shape exactly.

## 🚦 Phases

1. [ ] `SharedClusterService::CHAT` + `ClusterTool::CHAT` cases; `chat:init`
   deploy (namespace, Commons Postgres alloc, S3 bucket alloc, secrets sync,
   manifest apply, rollout wait), Team Edition by default.
2. [ ] `chat:init --remove` (Plex tenant drop + S3 bucket release + resource
   delete, all through `removeResources()` with proper abort-on-failure).
3. [ ] `ClusterTool::CHAT->smtpEnv()` + confirm `mail:wire chat` end-to-end.
4. [ ] Docs page (setup, the Team Edition vs Entry vs Professional licensing
   note above, Calls limitation) + Blueprint Anatomy update.

## ✅ Verification

- `chat:init` (local) → open the workspace, complete first-run admin setup,
  create a second account, post a message + upload a file (confirms S3
  wiring reads/writes through the Commons bucket, not local disk).
- `mail:wire chat` → trigger a notification email (e.g. a mention while
  offline) and confirm it arrives via Stalwart.
- `chat:init --remove` → confirm the Commons `mattermost` database and the
  `chat-storage` S3 bucket are both actually gone, not just the pods.
- `chat:init production` (no explicit `--context`) → confirm it resolves the
  environment's saved cloud target, not the ambient kube-context — the exact
  regression this plan is designed not to reintroduce.

## ⚠️ Risks / open questions

- **No SSO in the default (Team Edition).** Fine for LaraKube's actual
  audience (one small team per cluster); call it out in the docs page rather
  than let someone discover it mid-onboarding.
- **Calls (voice/video) need a separate RTC plugin/service** — out of scope
  for v1. Text/channels/DMs/file-sharing/search all work without it.
- **Confirm the official container image tag** maps to Team Edition (MIT) by
  default and doesn't silently boot into an Enterprise trial mode — a
  five-minute check at implementation time, not a planning blocker.
- **250-user figure** is a soft guideline per Mattermost's own docs, not
  something LaraKube needs to detect or warn about.
