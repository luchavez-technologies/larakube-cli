# LaraKube Cloud MVP — Execution Plan (for Antigravity)

## Purpose

`cli/plans/active/larakube-cloud-architecture-and-zero-trust-agent.md`
settles the *why* (zero-trust agent, OpenBao custody, Teams, deferred
enterprise SSO). This document is the *what to build*, at the level of
detail Antigravity should be able to execute against directly — database
schema, page list, design-token reconciliation, a privacy policy draft,
and the Maya Checkout integration shape — without needing to make its own
design or architecture decisions. Where a decision is genuinely the
user's to make (pricing tiers, legal sign-off), that's called out
explicitly rather than guessed.

Target project: `/Users/jsluchavez/Codes/Ideas/laravel-k8s/cloud`.

**`lorisleiva/laravel-actions` (^2.13) is installed — use it for all new
provisioning-related classes** (`ProvisionServer`, `InstallCompanionTool`,
`CreateCloudCredential`, `DestroyServer`, and similar). These need to run
as a queued job (background worker, Sections 2–3 of the architecture doc),
be callable the same way from an Artisan command for support/testing
parity, and feed `audit_logs` (`action`/`arguments`/`exit_status`)
regardless of entry point — `AsController`/`AsJob`/`AsCommand` on one class
covers all three without duplicating the logic per entry point. This does
**not** apply to the starter kit's own Fortify actions
(`app/Actions/Fortify/*`, `app/Actions/Teams/CreateTeam.php`) — those stay
as plain invokable classes, Fortify's own convention; don't migrate them.

## 0. What already exists in `cloud` — build on this, don't redo it

A survey of the actual project (not assumed from the architecture doc)
found it's further along than "newly created, empty":

- **Stack**: Laravel `^13.17`, scaffolded from the **official
  `laravel/react-starter-kit`** — Inertia v3 + React 19.2 + Tailwind v4 +
  shadcn/ui (`new-york` style, Lucide icons, Radix primitives), Wayfinder
  for typed routes/actions.
- **Auth**: Laravel Fortify already wired (`app/Actions/Fortify`,
  `FortifyServiceProvider`), plus **passkeys** (`@laravel/passkeys`,
  `passkeys` migration, `PasskeyVerify`/`PasskeyRegister`/`ManagePasskeys`
  components) and two-factor auth columns on `users`. This **is** Section
  11.1's "Fortify + Passkeys" decision — already done, not still to build.
- **Teams**: `teams`, `current_team_id` (on `users`) migrations already
  exist. Models: `Team`, `Membership`, `TeamInvitation`, `User` (with a
  `HasTeams` concern). Supporting: `TeamPolicy`, `TeamPermission`/
  `TeamRole` enums, `TeamName`/`UniqueTeamInvitation`/`ValidTeamInvitation`
  rules, `GeneratesUniqueTeamSlugs` concern, and UI components
  (`team-switcher.tsx`, `create-team-modal.tsx`, `invite-member-modal.tsx`,
  `remove-member-modal.tsx`). Feature tests already exist under
  `tests/Feature/Teams/`. **This already satisfies Section 8's DO-style
  Team model and most of Section 11.3's schema** (`teams`, `team_user`
  equivalent via `Membership`, `team_invitations` equivalent via
  `TeamInvitation`, role enum). Do not re-create these — extend them.
- **Nothing LaraKube-Cloud-specific is documented yet** — no `README.md`,
  no `docs/decisions/` ADR folder (unlike `desktop`). `AGENTS.md` is
  generic Laravel Boost boilerplate. This execution plan is the first
  place any of this is written down for `cloud` specifically.

What's genuinely still missing, and what the rest of this document
covers: cloud-provider credential custody, server/agent provisioning,
companion tools, audit logging, billing, the design-token reconciliation
with `desktop`, and legal pages.

---

## 1. Database Schema — new migrations only (extends what exists)

All new tenant-scoped tables key off `team_id` (FK to the existing
`teams` table), per the architecture doc's Section 8/11 rule.

### 1.1 `cloud_credentials`
Per architecture doc Section 6.1 — pointer only, never the raw secret.
```
id (ulid, pk)
team_id (fk -> teams, cascade on delete)
provider (enum: digitalocean, hetzner, aws, gcp)
label (string) — user-facing name, e.g. "Production DO token"
openbao_path (string) — e.g. secret/data/teams/{team_id}/providers/{provider}
created_by (fk -> users, nullable on delete)
created_at, updated_at
unique (team_id, provider, label)
```

### 1.2 `servers`
Per Sections 2–3. One row per customer cluster/server.
```
id (ulid, pk)
team_id (fk -> teams, cascade on delete)
cluster_id (ulid, unique) — matches LARAKUBE_CLUSTER_ID the agent registers with
name (string) — user-facing label
provider (enum: digitalocean, hetzner, aws, gcp, byo) — "byo" = Connect Existing Server
region (string, nullable)
status (enum: provisioning, online, offline, destroying, destroyed, error)
agent_connected_at (timestamp, nullable)
agent_last_heartbeat_at (timestamp, nullable)
created_by (fk -> users, nullable on delete)
created_at, updated_at
index (team_id, status)
```

### 1.3 `server_tool_installs`
Companion tools catalog state (Phase 1 roadmap item), per server.
```
id (ulid, pk)
server_id (fk -> servers, cascade on delete)
tool (enum: openbao, seaweedfs, postgres, mysql, redis, uptime_kuma)
status (enum: installing, installed, failed, removing)
domain (string, nullable) — e.g. status.example.com for uptime_kuma
installed_at (timestamp, nullable)
last_error (text, nullable)
created_at, updated_at
unique (server_id, tool)
```

### 1.4 `audit_logs`
Per Section 6.4/7. Metadata only — never secret values.
```
id (ulid, pk)
team_id (fk -> teams, cascade on delete)
user_id (fk -> users, nullable on delete) — null for system/scheduled actions
action (string) — e.g. "server.provision", "tool.install", "credential.create"
arguments (json, nullable) — redacted before write, see Section 6.4
exit_status (string, nullable) — "success" / "failure" / null while in-flight
created_at
index (team_id, created_at)
```

### 1.5 `team_subscriptions` (billing — see Section 4 for why this shape)

Column names are **gateway-neutral by design**, not PayMongo-specific —
this is the one piece of this schema that deliberately anticipates a
near-certain future need (Lemon Squeezy for international teams, per
Section 4.5) without building any actual multi-gateway code yet. A plain
naming decision, not a premature abstraction: today only `paymongo` is a
valid `gateway` value and only a PayMongo Saloon connector exists in
code; adding a second gateway later is a new enum case + a new connector,
not a schema migration.
```
id (ulid, pk)
team_id (fk -> teams, cascade on delete, unique) — one active subscription per team
plan (string) — plan slug, e.g. "free", "starter", "pro"
status (enum: trialing, active, past_due, canceled)
gateway (enum: paymongo) — only one case today; add lemonsqueezy later as a second case, not a rename
gateway_customer_id (string, nullable) — e.g. PayMongo Customer object, holds the vaulted card
gateway_subscription_id (string, nullable) — the gateway's own Subscription object ID
amount (integer) — minor units (centavos)
currency (string, default 'PHP')
next_billing_at (date, nullable) — informational mirror of the gateway's own cycle; the gateway drives the actual charge, not a local scheduler (see Section 4.2)
canceled_at (timestamp, nullable)
created_at, updated_at
```

### 1.6 `billing_payments`
```
id (ulid, pk)
team_id (fk -> teams, cascade on delete)
gateway (enum: paymongo) — same rationale as 1.5
gateway_reference_number (string) — idempotency key, see Section 4.3
gateway_payment_id (string, nullable) — the gateway's own payment/invoice ID once known
amount (integer) — minor units
currency (string, default 'PHP')
status (enum: pending, succeeded, failed)
raw_webhook_payload (json, nullable) — redact any PAN/CVV-shaped fields before write
paid_at (timestamp, nullable)
created_at, updated_at
unique (gateway, gateway_reference_number)
index (team_id, created_at)
```

---

## 2. Pages & Routes Needed (Inertia)

### Already built — do not recreate
Login, register, forgot/reset password, email verification, 2FA setup,
passkey management (`manage-passkeys`), team switcher, create-team modal,
invite-member modal, remove-member modal, account settings (profile,
password, appearance) — all present in the starter kit as scaffolded.

### New pages to build

| Page | Route | Purpose |
| :--- | :--- | :--- |
| Onboarding — Connect Credentials | `/onboarding/credentials` | First-run prompt after signup: add a cloud-provider token (or skip to "Connect Existing Server"). |
| Cloud Credentials index | `/credentials` | List team's `cloud_credentials` (label, provider, masked), add/remove. Add posts the raw token to the backend once; backend writes to OpenBao and stores only the path — the token is never redisplayed. |
| Servers index | `/servers` | Team's `servers` list — status badge, provider icon, agent connection indicator, "Create Server" / "Connect Existing" CTAs. |
| Server create wizard | `/servers/create` | Steps: choose provider → pick saved credential (or add new) → region/size → paste SSH public key → review → confirm. Submcolumn dispatches the provisioning job (Section 3 of the architecture doc). |
| Connect Existing Server | `/servers/connect` | Shows the one-line curl/kubectl install command for `agent:daemon` (Section 3's "Connect Existing Server via one-line curl / kubectl command"); polls for the agent's first heartbeat. |
| Server detail | `/servers/{server}` | Status, live terminal/log stream (Reverb channel), tabs for Companion Tools and Danger Zone (destroy). |
| Companion Tools catalog | within Server detail, tab | List of installable tools (`server_tool_installs` enum) with install/remove buttons and live status. |
| Audit Log | `/audit-log` | Per-team table of `audit_logs`, filterable by action/user/date. |
| Billing | `/billing` | Current plan, Maya-vaulted payment method, `billing_payments` history, upgrade/downgrade, cancel. |
| Privacy Policy | `/privacy` | Static page — content in Section 5 below. |
| Terms of Service | `/terms` | Static page — not drafted here; flag to the user as a separate legal item (see Section 6). |

Marketing/public landing page content is explicitly **out of scope** for
this execution plan — that's copywriting/positioning, not an engineering
decision Antigravity should make unprompted.

---

## 3. Design Elements — reconciling `cloud`'s shadcn tokens with `desktop`'s brand

`cloud` and `desktop` currently use **two different token systems**:
`cloud` ships the starter kit's stock shadcn/ui oklch tokens
(`--background`, `--primary`, `--destructive`, etc., "new-york" style);
`desktop` has a bespoke, Figma-driven palette (`--color-paper`,
`--color-surface`, `--color-ink`, `--color-accent` (`#d1261b`), status
colors `--color-ok`/`--color-warn`/`--color-busy`, brand/section colors,
Geist/Geist Mono fonts) with no shadcn/Radix layer at all.

**Decision: keep `cloud`'s shadcn structural layer (don't rip out the
already-generated component set), but override the token *values* to
match `desktop`'s actual brand colors, and add `desktop`'s
status/brand colors as named additions.** This is less work than
replacing shadcn, and gets visual consistency where it's most visible
(colors, fonts) without discarding a working, already-built component
library.

Concrete steps for `cloud/resources/css/app.css`:
1. Add `Geist`/`Geist Mono` as the font stack (matching `desktop`).
2. Re-point shadcn's `--background`/`--foreground`/`--card`/`--border`
   oklch values to `desktop`'s `--color-paper`/`--color-ink`/
   `--color-surface`/`--color-line` equivalents (convert hex/oklch as
   needed — shadcn's structure stays, the color values change).
3. Re-point `--primary`/`--destructive` to `desktop`'s `--color-accent`
   (`#d1261b`) and its tint/hover/line variants.
4. Add `desktop`'s status colors (`--color-ok`, `--color-warn`,
   `--color-busy` + tints) as new custom tokens, unchanged — these are
   directly reusable for `servers.status` and `server_tool_installs.status`
   badges in the new pages above (e.g. `online` → ok, `provisioning` →
   busy, `error` → warn/destructive).
5. Keep Lucide as the icon library — already shared by both projects, no
   change needed.
6. Repeat the same `.dark` class override block `desktop` already has, so
   dark mode stays consistent.

No ADR exists yet for either project's token choice (confirmed — none in
`desktop/docs/decisions/`), so this reconciliation should itself become
the first design-system ADR in `cloud/docs/decisions/` once applied.

---

## 4. PayMongo Integration — billing architecture

Decision (superseding an earlier Maya-based draft of this section):
**use PayMongo**, not Maya — confirmed via live research to have a real
Subscriptions API and real webhook signature verification, both of which
Maya lacked. Still real gaps to design around, below.

### 4.1 No official SDK — build a Saloon connector instead
PayMongo publishes **no official PHP/Laravel SDK**. The most-used
community package (`luigel/laravel-paymongo`, ~64k installs) explicitly
states in its own README that it's "not affiliated with PayMongo" — fails
this project's "official artifact" bar regardless of its popularity.
Build a plain Saloon REST connector against PayMongo's documented
Customers/Subscriptions/Webhooks endpoints instead — matching this
codebase's existing convention for Zitadel/Stalwart integrations (`cli`'s
`app/Http/Integrations/*`), not a third-party package.

### 4.2 Subscriptions API — real, but has a pre-launch dependency
PayMongo has a genuine **Subscriptions API** in two modes: "standard"
(PayMongo auto-generates invoices and auto-charges each cycle against a
vaulted card on a Customer object) and "on-demand" (you trigger each
charge manually, Maya-only as a payment method, not relevant here).
Standard mode is the right fit — it means **no local charge-scheduler is
needed** (unlike the Maya design this replaces): create a PayMongo
Customer + card payment method for the team (stored as `team_subscriptions.gateway_customer_id`),
create a PayMongo Subscription against it
(`gateway_subscription_id`), and then just react to PayMongo's webhooks
(`invoice.paid`, `invoice.payment.failed`, subscription status changes)
to update `team_subscriptions`/`billing_payments` locally.
**Pre-launch action item for the user, not Antigravity**: standard
(auto-charging) subscriptions require PayMongo to configure this on your
account via a support request before it works in production — this has
lead time and should be requested early, well before the MVP's planned
billing go-live date, not discovered as a blocker at launch.

### 4.3 Webhook security — real HMAC, implement it properly
Unlike Maya, PayMongo webhooks carry a `Paymongo-Signature` header with a
timestamp plus separate live/test signatures. Verification: HMAC-SHA256
the **raw request body** (not the parsed/re-encoded JSON — re-encoding
can change byte-for-byte output and break the comparison) using the
webhook's signing secret, then compare against the signature in the
header. Still layer on:
- Idempotency via `billing_payments.gateway_reference_number` (unique
  per `gateway`, see Section 1.6) — a replayed webhook for the same
  reference is a no-op.
- The webhook handler should only verify + enqueue a job, never do
  charge-reconciliation synchronously.
- Redact any PAN/CVV-shaped fields before writing `raw_webhook_payload`
  (mirrors the Section 6.4 output-redaction rule already established for
  command output).
- Confirm the exact signature doc (developers.paymongo.com's "Securing a
  webhook" page) against PayMongo's current format before implementing —
  one version of that page had an old last-updated timestamp when
  checked; don't assume it's unchanged.

### 4.4 Sandbox setup (for Antigravity to action directly)
Test keys (`pk_test_`/`sk_test_`) are available immediately on signup, no
approval wait — Dashboard → Developers. Build and test the entire
integration against sandbox keys first.

**Configuration — exact names, so this isn't improvised per-build**:
- `.env` keys: `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_SECRET_KEY`,
  `PAYMONGO_WEBHOOK_SECRET` — the user has both `pk_test_`/`sk_test_`
  (test) and live key pairs already generated as of this plan's writing.
  **Use only the `pk_test_`/`sk_test_` pair for all development and the
  entire build-out below.** Live keys do not get entered into `.env`
  until the Section 4.2 standard-subscriptions support request has been
  approved on the account and webhook signature verification (4.3) is
  implemented and tested end-to-end against sandbox — both are
  prerequisites, not parallel work.
- `config/services.php` entry:
  ```php
  'paymongo' => [
      'public_key' => env('PAYMONGO_PUBLIC_KEY'),
      'secret_key' => env('PAYMONGO_SECRET_KEY'),
      'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
  ],
  ```
- The Saloon connector (Section 4.1) authenticates using
  `config('services.paymongo.secret_key')` via HTTP Basic Auth (PayMongo's
  documented scheme: secret key as username, empty password) — confirm
  this against PayMongo's current auth docs before implementing, don't
  assume from memory.
- Never log `PAYMONGO_SECRET_KEY` or `PAYMONGO_WEBHOOK_SECRET` — this is
  exactly the kind of value the Section 6.4-style output-redaction rule
  exists for.

### 4.5 Explicitly flagged for the user, not Antigravity to decide
- **Pricing tiers/amounts** — not specified anywhere yet; needs the
  user's own input before `team_subscriptions.plan`/`amount` values can
  be finalized. The free-tool/paywalled-tool model you're considering
  (some companion tools free, others gated) may mean most teams never
  need a true recurring subscription at all — worth settling before
  `team_subscriptions`'s shape is treated as final.
- **Submitting the standard-subscriptions support request to PayMongo**
  (Section 4.2) — this is an account-level action only you can take.
- **Non-PH customers**: PayMongo is a Philippine-market gateway; if/when
  LaraKube Cloud takes international customers, a second gateway may be
  needed alongside it — out of scope for this MVP, noted so it isn't
  forgotten.

---

## 5. Privacy Policy — draft for `/privacy`

**This is a drafted starting structure, not a finished legal document.**
It must go through actual legal review before publishing — especially
given Philippine Data Privacy Act (RA 10173) obligations and payment-data
handling. Antigravity should implement this as the page content, but the
user (or counsel) must sign off on the text before launch.

```markdown
# Privacy Policy

Last updated: [DATE]

LaraKube Cloud ("we", "us") provides a platform for provisioning and
managing self-hosted infrastructure. This policy explains what
information we collect, why, and how it's protected.

## Information We Collect
- **Account information**: name, email address, authentication
  credentials (password hash or passkey public key — we never store
  your actual password or your device's private key).
- **Team information**: team names, membership, invitations.
- **Cloud provider credentials**: when you connect a cloud provider
  (DigitalOcean, Hetzner, AWS, GCP), your API token is encrypted and
  stored in our secrets vault (OpenBao). We never display it back to
  you after entry, and our support staff cannot read it in plaintext.
- **Server/cluster metadata**: status, region, and connection health of
  servers you provision — never the contents of your actual
  applications or databases running on them.
- **Payment information**: processed directly by PayMongo (our payment
  processor); we never receive or store your full card number. We
  retain only a payment token and transaction records (amount, date,
  status).
- **Usage/audit logs**: actions taken on your account (e.g. "server
  provisioned," "tool installed") for security and support purposes.

## How We Use Information
To provide and operate the service, process payments, communicate
service-related notices, and investigate security incidents. We do not
sell personal information.

## Data Sharing
We share data only with: the cloud provider you explicitly connect (to
provision your own infrastructure on your behalf), PayMongo (to process
payments), and infrastructure providers we use to run LaraKube Cloud
itself. We do not share your data with advertisers.

## Data Retention
Account and billing records are retained as required by Philippine tax
and accounting law. Audit logs are retained for [X months] for security
purposes. You may request deletion of your account at any time, subject
to records we are legally required to retain.

## Your Rights
Under the Philippine Data Privacy Act of 2012 (RA 10173) and, where
applicable, other data protection laws, you have the right to access,
correct, and request deletion of your personal data, and to object to
certain processing. Contact us at [PRIVACY EMAIL] to exercise these
rights.

## Security
Cloud provider credentials are encrypted at rest and accessed only by
short-lived, narrowly-scoped tokens during an active operation. Servers
you provision never require inbound access from us — all communication
is outbound-initiated from your own infrastructure.

## Children's Privacy
LaraKube Cloud is not directed at children under 18 and we do not
knowingly collect their data.

## Changes to This Policy
We will post any changes here and update the "Last updated" date above.

## Contact
[COMPANY NAME / DPO CONTACT — to be filled in before publishing]
```

---

## 6. Explicitly Not Decided Here — needs the user, not Antigravity

- Pricing tiers/amounts for `team_subscriptions`.
- Final Privacy Policy and Terms of Service text (legal review required).
- Company name/DPO contact details for the Privacy Policy footer.
- Whether/when to add a second, non-PH-specific subscription-capable
  payment gateway.
- The Zitadel-vs-Keycloak enterprise-SSO decision (explicitly deferred
  per the architecture doc's Section 11.1 — not part of this MVP build).

---

## 7. Verification

1. `composer test` / `composer analyse` / `composer format` in `cloud`
   for every new migration, model, and controller.
2. New Pest feature tests per new page/route, following the existing
   `tests/Feature/Teams/*` conventions already in the project.
3. Confirm `cloud_credentials`/`audit_logs`/`billing_payments` never
   receive a raw secret/card value in any test assertion or seeded
   fixture — this is the one invariant worth a dedicated test, not just
   manual review.
4. PayMongo integration: build and verify entirely against sandbox API
   keys first; do not point at production PayMongo credentials until
   webhook signature verification (Section 4.3) is implemented and
   tested, and until the standard-subscriptions support request
   (Section 4.2) has actually been approved on the account.
5. Design token reconciliation: visually diff a few shared component
   states (buttons, status badges) between `cloud` and `desktop` after
   the token changes, not just a build-succeeds check.
