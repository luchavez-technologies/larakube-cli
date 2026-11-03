# LaraKube Cloud — Pricing Tiers Draft

## Status
**Draft for the user's own pricing decision — not a technical spec, not
final numbers.** CLI/Console/Cloud architecture docs already decided the
mechanism (Team-scoped entitlements, per-invocation checks against Cloud's
API, fail-open on outage — see `plans/active/monetization-strategy.md` and
`plans/active/larakube-cloud.md` §5). This document turns that mechanism
into an actual shape to sell, after the 2026-10-25 decision to stop gating
individual CLI commands (`cloud:create`, `monitor:init`, backups all free)
and sell hosted convenience instead — the Forge/Ploi.io model, not a
license-gate model.

Reference points (checked live, not from memory — see Sources):
- **Laravel Forge**: Hobby $12/mo, Growth $19/mo, Business $39/mo
  (annual: $120/$199/$399). Tiers scale by server count, team access,
  support level. You still pay your cloud provider separately.
- **Ploi.io**: free tier (1 server) + paid tiers reported around
  €8/€13/€30/mo in a mid-2026 comparison — confirm against Ploi's own
  pricing page before treating as current.

LaraKube Cloud sits in the same market category (managed server/cluster
orchestration dashboard), so these are a reasonable starting band, not a
ceiling or floor you're locked into.

---

## What's free, permanently — no exceptions

- **LaraKube CLI** — every command, including `cloud:create`,
  `monitor:init`, backups (`Guardian`'s manual dump path), all Cluster
  Tools. Zero license check, zero account required, zero network call to
  run locally. This is the adoption funnel — gating any of it undermines
  the thing that gets people to trust LaraKube enough to eventually pay
  for Cloud.
- **LaraKube Desktop** — same reasoning. It's a GUI over the same free CLI
  commands (`CliRunner`), not a separate product with its own paywall.
  Its value (seeing both premade and custom apps on your server, live
  status, logs) stays fully available with zero Cloud account.

## What Cloud actually sells: hosted convenience, not features

The pitch to a user who already has the free CLI/Desktop: **you don't have
to run OpenTofu, OpenBao, or a WSS agent yourself, and you don't have to
remember any of this is running** — Cloud operates the control plane
(provisioning job workers, Reverb WSS gateway, vault, Postgres) for you.
That's real infrastructure cost on LaraKube's side, which is why it's the
thing with a price tag, not the local-only CLI/Desktop that costs LaraKube
nothing to let people run.

---

## Proposed tiers

### Free (Cloud account, no subscription)
- Already decided in `larakube-cloud.md` §5: **2 concurrent
  `cloud:create`-provisioned stacks**, BYOC (Connect Existing Server)
  excluded from that cap — connecting infrastructure you already run
  costs LaraKube nothing, so it's uncapped even on Free.
- Full dashboard access for those stacks: real-time terminal/log stream,
  server status, companion tools catalog (install official free tools).
- Single-person team (no invites) — this is the natural wedge into the
  paid tier once a second person needs access, not an arbitrary limit.
- Official (free) marketplace templates only.

### Pro — draft: **$15/mo or $144/yr** (anchored between Forge Hobby/Ploi Basic)
- Unlimited concurrent `cloud:create` stacks.
- Team seats (the thing solo devs never need and companies always do) —
  draft: up to 5 included, confirm a number once there's a real second
  team to test against (already flagged as open in `larakube-cloud.md`
  §5: "billing admin" vs. "team-owner implies admin" is unresolved too —
  settle both at the same time).
- Premium marketplace template access (JetBrains-style perpetual
  fallback — once fetched while subscribed, keeps working forever even
  if the subscription later lapses; only *new* premium releases require
  an active subscription).
- Priority support (response-time commitment — exact SLA not drafted
  here, needs your own support-capacity reality check first).

### Scale/Business — draft: **$40/mo or $384/yr** (anchored to Forge Business)
- Everything in Pro.
- Audit log retention beyond Pro's default window, exportable.
- Managed companion services billed separately as metered add-ons (see
  below) — Scale is where this becomes relevant, since it's aimed at
  teams running real production workloads, not side projects.
- Candidate home for enterprise SSO (Zitadel/Keycloak, deferred per
  `larakube-cloud-architecture-and-zero-trust-agent.md` §11.1) once that
  work actually gets built — not included today, listed here as the
  tier it would land in.

### Metered add-ons (any tier, pay-for-what-you-use)
- **LaraKube-hosted companion services** — Postgres/Redis/object storage
  actually operated by LaraKube Cloud (not self-hosted on the customer's
  own cluster), for people who don't want to run even that themselves.
  Real infra cost + markup, same model Render/Railway use for managed
  databases. Not drafted with numbers here — needs its own cost-basis
  pass against actual provider pricing before quoting anything.
- **Managed Kubernetes tier** — LaraKube operates the control plane
  entirely (vs. free BYOC where the customer already has a cluster).
  Same "needs its own cost-basis pass" caveat.

---

## Explicitly not decided here

- Exact seat count included in Pro, exact SLA response times.
- Metered add-on unit pricing (Postgres/Redis/storage, managed K8s) —
  needs real provider cost data first, not guessed.
- Annual-discount percentage (drafted at ~20% off monthly, matching
  Forge's rough ratio — not independently chosen).
- Whether Free's "2 concurrent stacks" cap needs revisiting once real
  usage data exists.
- Payment provider mechanics — already resolved separately in
  `monetization-strategy.md` (Lemon Squeezy first, Paymongo deferred for
  PH-based customers) and superseded in `cli/plans/active/larakube-cloud-mvp-execution-plan.md`
  for the `cloud` app specifically (PayMongo-only today, Lemon Squeezy
  deferred) — **these two docs currently disagree on build order and
  need reconciling**, flagged here since this pricing draft surfaced the
  conflict, not resolved by this document.

## Sources
- [Laravel Forge pricing review (Benjamin Crozat)](https://benjamincrozat.com/laravel-forge)
- [Laravel Forge pricing history (PricingSaaS)](https://pricingsaas.com/companies/laravel)
- [Ploi.io pricing comparison](https://982309234098234023984.rocketee.rs/compare/ploi)
