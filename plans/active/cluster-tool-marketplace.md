# LaraKube Template Marketplace — database-driven Cluster Tool templates

## Context

Every Cluster Tool's deployable spec (`mail:init`, `sso:wire`, `crm:init`, etc.)
lives today as Blade files baked into the CLI binary
(`resources/views/k8s/{tool}/**`) — confirmed by direct investigation, not
assumption. There is **no DB anywhere in this CLI today** (no models, no
migrations, no SQLite) — ClusterTool install state lives in a cluster-side
`larakube-tools-registry` Secret (`app/Services/ToolRegistry.php`), and
template *content* has zero versioning or drift-protection (the SHA-256
lock/hand-edit mechanism in `GeneratesProjectInfrastructure::writeManagedManifest()`
exists only for a project's own app manifests, never extended to Cluster
Tools). Shipping a template fix today means shipping a whole new CLI release,
and every tool's full manifest set is permanently bundled into every CLI
binary.

You want to turn this into a **marketplace**: official + community-submitted
templates, upvoting, most templates free, premium ones gated — with a
JetBrains-style perpetual-fallback license (once your team has used a
release, you keep it forever; only *new* releases published after your
subscription lapses become inaccessible, except security patches, which are
always free) — while LaraKube Desktop/CLI must keep working for people who
never create a Cloud account at all. You also asked whether `*:wire`/
`*:unwire` could become "Cloud-driven."

**Scope clarified mid-discussion**: this isn't only alternate deployment
*recipes* for tools LaraKube already knows about — it's also a channel for
third parties to bring **entirely new tools** LaraKube has never integrated:
an open-source dev sharing their own self-hostable project, or a vendor
wanting their tool discoverable/deployable through LaraKube. For the selling
side specifically: **pure directory, not a payment processor** — LaraKube
never touches money or takes a commission. A vendor who wants to charge
distributes their own license the same way any "bring your own vendor
license" tool already works in this plan (a license-key env var LaraKube
wires through). A full app-store model (LaraKube processes payment, takes a
cut, vets images, handles EULA pass-through) is an explicit **later-phase
aspiration, not built now** — no headcount for it yet, same "ship something
sellable first" discipline `larakube-cloud.md` already applies elsewhere.

This plan covers the template-marketplace database design, the API surface,
how the CLI consumes it (with signature verification, since Blade compiles
straight to PHP — a real remote-code-execution surface if content isn't
authenticated), and resolves the wire/unwire question. It is a **focused
companion** to the existing `plans/active/larakube-cloud.md` (Teams/RBAC,
pricing tiers, dashboard job-execution architecture, OpenTofu remote state —
all already decided there, not re-litigated here) and
`plans/active/monetization-strategy.md` (CLI auth via Passport Device Grant,
fail-open entitlement checks, licenses/entitlements table — reused directly
below, not redesigned). Saved under `cli/plans/active/` per your request,
since the CLI-side consumption work is the larger unexplored piece.

**Decision from discussion:** full template content lives in Cloud's DB,
signed at publish time, verified by the CLI before compiling (your "signed
full content" preference) — not metadata-only. Metadata-only can't support
community upvoting/browsing (nothing substantive to vote on) and doesn't
solve CLI bloat (content would still have to ship in every release).

## Architecture

### Two tiers of tool, not one

- **Official tools** (today's `ClusterTool` enum + vendor classes) keep
  owning everything that's genuinely code: RBAC roles, namespace/topology
  rules, multi-engine dispatch, `commonsCapabilities()`, and deep wiring
  (`HasSmtpWiring`, `HasOidcWiring`, Commons DB/bucket/Redis auto-allocation
  — `app/Contracts/ClusterToolVendor.php`). **Don't move this to the DB** —
  it's logic, not a template, and turning `instanceof`-based vendor-contract
  dispatch into data would be a much larger, riskier rewrite for no
  asked-for benefit. The marketplace owns these tools' **manifest content
  only** (the Blade files), keyed by the enum's existing string value
  (`tool_slug`) — adding a template variant for an official tool never
  requires touching `ClusterTool.php`.
- **Marketplace-only tools** (new, from this discussion) are how a
  third-party dev adds a tool LaraKube has never integrated, without
  waiting on a CLI code change. These get a **separate, generic path**: a
  new `marketplace_tools` table (slug, label, icon, category, namespace
  convention, container image reference, a small declared-capability set —
  e.g. "accepts a license-key env var," "needs its own Postgres: yes/no")
  consumed by a generic `larakube marketplace:init {slug}` command instead
  of a per-tool `{tool}:init` command. No deep SMTP/OIDC/Commons
  auto-wiring — that requires real integration code, so a marketplace-only
  tool starts with whatever the generic command can do (deploy it, inject a
  license key if the tool declares it accepts one) until someone on your
  side writes it a proper vendor class and **promotes it into the official
  `ClusterTool` enum** (mirrors Homebrew's core-formula-vs-tap split, or
  VS Code's built-in-vs-extension split — a deliberate, later, manual
  promotion, not automatic).
- **New near-term risk this introduces**: a marketplace-only tool can bring
  its *own* container image, not one your team has ever reviewed — a
  different and arguably bigger trust surface than the Blade-template
  signing already designed (that's about manifest *content*; this is about
  arbitrary third-party *binaries* running on a customer's cluster). Worth
  a minimum bar even at directory-only scale — e.g. require the image live
  on a well-known public registry with public source, not an arbitrary
  unverifiable blob — rather than treating image trust purely as an
  app-store-phase problem.

### Schema (LaraKube Cloud's own Postgres — already decided stack, §10 of `larakube-cloud.md`)

- **`cluster_tool_templates`** — one row per template *variant* for a tool
  (a tool can have several: "official", a leaner community "minimal-resources"
  variant, etc.). `tool_slug`, `engine` (nullable, for multi-engine tools like
  Matrix/oCIS), `slug` (unique per tool+engine), `title`, `description`,
  `author_id` (FK to Cloud's users; null = LaraKube official),
  `visibility` (`official`/`community`), `access_tier` (`free`/`premium`),
  `upvotes_count` (denormalized). Unique on `(tool_slug, engine, slug)`.
- **`cluster_tool_template_releases`** — the actual version history.
  `cluster_tool_template_id` (FK), `version` (semver), `status`
  (`draft`/`published`/`deprecated`/`yanked`), `is_security_patch` (bool —
  bypasses entitlement checks for everyone, see below), `changelog`,
  `min_cli_version` (nullable), `signature` (detached signature over the
  release's canonical content), `published_at`. **Immutable once
  published** — a fix is always a new row, never an UPDATE. Unique on
  `(cluster_tool_template_id, version)`.
- **`cluster_tool_template_files`** — `release_id` (FK), `relative_path`
  (mirrors today's `resources/views/k8s/{tool}/` layout, so bundled files are
  literally the seed data for the first migration), `content` (raw Blade
  source).
- **`template_upvotes`** — `cluster_tool_template_id` (FK), `user_id` (FK,
  requires an account — browsing/using stays anonymous, voting doesn't).
  Unique on `(cluster_tool_template_id, user_id)`.
- **`template_entitlement_grants`** — the JetBrains-fallback ledger. One row
  per `(team_id, cluster_tool_template_release_id)`, written the first time a
  team successfully fetches a **premium** release while entitled. Once a row
  exists, that exact release stays fetchable by that team forever, lapsed
  subscription or not. A lapsed team can keep redeploying anything they've
  already used; they just can't pull *new* premium releases published after
  the lapse. `is_security_patch` releases skip this table entirely — always
  free for everyone, matching your "security patches always flow through"
  rule. Free/community templates never touch this table either — only
  premium releases need the check, so the common (free) path stays a plain
  public read with no entitlement join.

### API (public by default — no Cloud account required for the common case)

- `GET /api/templates/{tool}[?engine=]` — **public**, lists published
  templates (official + community) with metadata and upvote counts.
- `GET /api/templates/{tool}/{slug}/releases/{version}/content` — the fetch
  endpoint. **Public, no auth, no account** when the release is free or
  `is_security_patch`. Requires the existing Passport Device Grant token
  (already designed in `monetization-strategy.md`, not rebuilt here) when
  premium — and even then checks the grant ledger first (existing grant row
  → always allowed; no row → requires live entitlement). Returns file
  contents + `signature`.
- `POST /api/templates/{tool}/{slug}/upvote` — requires an account (reuses
  existing Teams/users, §5 of `larakube-cloud.md`).
- Community submission/review/moderation endpoints: **build deferred to a
  later phase** once the official-template path is proven, but the storage
  model is decided (see Licensing considerations below) — community
  templates point at a GitHub repo rather than LaraKube storing a copy. At
  launch, `visibility=official` (LaraKube-authored) is the only published
  content.

### CLI-side consumption (new work, lives in `cli/`)

- New service (parallels `app/Services/ToolRegistry.php`'s pattern), resolves
  which release to use at `*:init`/`*:wire` time: explicit `--template=`
  flag → a per-project pin recorded after first successful deploy (so a
  re-run never silently drifts to a newer version — matches the existing
  idempotency rule) → otherwise the marketplace's "latest" filtered to what
  the caller can access.
- **Signature verification is a hard boundary, not a warning.** CLI ships a
  public key; Cloud's publish pipeline signs each release's canonical
  content in CI (private key never leaves release infra). Unsigned or
  mismatched content is refused outright — mirrors this codebase's existing
  "fail loudly, never silently guess" posture (`MissingFlagException`
  pattern, `RequiresFlagsWhenNonInteractive`).
- **Local cache for offline reuse**: fetched + verified content is written to
  `~/.larakube/templates/{tool}/{engine}/{version}/*.blade.php` (same
  precedent as `~/.larakube/certificates`), verified once at fetch time, then
  reused without re-verification. A tool already needs network once to pull
  its container image at deploy time — fetching its manifest at the same
  moment isn't a new constraint, just the same one-time-online requirement
  template fetching now shares with it.
- **Resolved**: zero templates stay bundled in the CLI binary. Every
  `resources/views/k8s/{tool}/**` file migrates fully out to the marketplace
  — no fallback copy kept for offline bootstrap. The explicit consequence:
  an install that has *never* been online, not even once, cannot deploy any
  Cluster Tool until it is. That's the accepted trade-off in exchange for
  actually shedding the bloat, rather than a "mostly bundled, marketplace as
  an extra" compromise.
- **Onboarding pre-fetch is how the offline gap gets closed in practice**:
  both LaraKube Desktop's onboarding wizard and the CLI's own first-run/
  `setup` flow gain a "choose your tools" step — a multi-select over the
  same public tool catalog (`GET /api/templates`, no auth needed for the
  listing itself), showing free and premium side by side. Premium picks
  prompt sign-in right there if the user wants them; free picks need
  nothing. Selecting tools triggers a new CLI command (e.g.
  `larakube templates:prefetch {tool...}`, reusable by both Desktop's
  `CliRunner` and the CLI's own interactive setup) that fetches, verifies,
  and caches each selection's latest release into
  `~/.larakube/templates/...` while the user still has a connection —
  "stock up while online," not a different mechanism from the ad-hoc
  first-use fetch described above, just triggered deliberately and in bulk
  at the one moment every install already assumes internet access anyway.
  A user can always add more tools later; that just needs the same one-time
  network access as any other first-use fetch.
  - Where exactly this plugs into Desktop's existing onboarding/setup pages
    needs a quick look at implementation time (not re-verified in this
    planning pass) — treat as a UI insertion point to confirm, not a new
    flow to invent from scratch.

### `*:wire` / `*:unwire` — "Cloud-driven": already solved, nothing to extract

`larakube-cloud.md` §6 already decided Cloud wraps the **same CLI binary** as
a subprocess inside a Kubernetes Job on Cloud's own control cluster, rather
than reimplementing any command's logic separately — this was designed for
`cloud:create`/`cloud:deploy` but the decision applies to *any* CLI command
with zero extra engineering, including `sso:wire`/`*:unwire`. So: **don't
extract or duplicate wire/unwire logic into Cloud.** When Cloud's dashboard
triggers a wire action, it just runs `larakube sso:wire {env} --tool=X` as a
Job, exactly like every other dashboard action. The one new wrinkle the
marketplace adds: a Cloud-triggered Job runs inside Cloud's own cluster, so
it can resolve templates by reading Cloud's Postgres directly (no HTTP
round-trip) — while a standalone laptop CLI goes through the public/
authenticated API above. One schema, two read paths, zero duplicated logic.

## Licensing considerations

Grounded in `plans/active/oss-tools-licensing-audit.md` (scoped to "is it OK
to deploy this tool unmodified" — the marketplace introduces risks that audit
never had to consider, since it's now about redistributing and selling
access to deployment recipes, not just running stock images). Not legal
advice, same caveat as that audit — flag the SSPL/RSALv2 and DMCA items
below for an actual lawyer before launch.

- **MongoDB and Valkey (replacing Redis) ship free, not gated.** This
  resolves the sharper version of the audit's SSPL/RSALv2 concern: those
  licenses specifically target companies *commercializing the tool's
  functionality as a service*. A free, non-differentiating offering stays
  safely inside the audit's "fine, LaraKube isn't reselling to further third
  parties" reading instead of testing it once a paid tier is involved.
  Dropping Redis for Valkey also makes the audit's §1 action item (the
  `redis:7.4` RSALv2/SSPLv1 pin) **moot entirely** for this product, not
  just deprioritized.
- **Community templates point at a GitHub repo; LaraKube doesn't store a
  copy as the default path.** Storing and serving third-party content
  directly makes LaraKube a direct redistributor/host (full copyright
  exposure, DMCA safe-harbor only available with real compliance work).
  Pointing at a public GitHub repo instead is the established pattern
  (Homebrew Taps, Helm chart indexes) and shifts LaraKube from "host" to
  "directory" — GitHub's own ToS/DMCA process governs the actual content.
  Two requirements this needs to actually hold up:
  - **Pin to an exact commit SHA, never a branch**, at approval time — a
    mutable `main` reference would let content change underneath an
    already-signed/approved entry.
  - **Signing changes meaning**: LaraKube signs an *attestation* ("we
    reviewed commit SHA X of repo Y, here's our signature over that exact
    hash"), not a claim of ownership — a materially lower-risk act than
    hosting a copy, closer to a search engine checksumming a page it
    indexes.
- **Non-GitHub direct submission is still supported as a secondary path**
  (forcing GitHub-only is an unnecessary adoption barrier), but it reverts
  to the higher-exposure "LaraKube hosts a copy" model, so it needs real
  safeguards before it ships, not just an informal policy:
  - A clickwrap at submission time: "I own this or have the right to submit
    it; I grant LaraKube a license to store, sign, and distribute it,
    including in a paid tier." Doesn't stop a real owner from coming after
    LaraKube directly, but gives LaraKube a contractual claim against a
    submitter who lied.
  - **"We'll take it down if the real author emails us" is a good practice,
    not a legal shield by itself.** Actual DMCA safe-harbor protection
    requires registering a designated agent with the US Copyright Office
    (one-time, ~$6, minutes to do) plus a published takedown policy and a
    repeat-infringer policy — do this before the feature is paid, not rely
    on informal responsiveness alone. Confirm with a lawyer which
    safe-harbor regime actually applies if the LaraKube Cloud entity isn't
    US-based (most of the stack — Lemon Squeezy, GitHub — is US-based
    regardless, so registering is common practice either way).
  - Treat non-GitHub submissions as inherently lower-trust: no external
    commit history/provenance to lean on, so a longer manual-review queue
    and no immediate "premium" eligibility, specifically to offset having
    less signal that the content isn't copied from somewhere else.
- **Hard rule: no template may unlock or bypass a vendor's own paywalled
  feature.** Separate and more serious than open-source copyleft compliance
  — some tools gate specific features behind their *own* commercial license
  regardless of open-source status (confirmed: n8n and Windmill both paywall
  SSO upstream). A template that tried to inject a fake license key or patch
  around that check would be straightforward license circumvention, not a
  gray area. At most, a template may support "bring your own vendor
  license" — accept a license-key env var as a Secret and wire it through,
  same pattern already used for customer-supplied SMTP/OIDC creds — never
  claim to provide or unlock the feature as part of LaraKube's own tier.
  - **Current code already gets this right for n8n**
    (`app/Tools/N8n.php` deliberately omits `HasOidcWiring`, with a comment
    explaining n8n paywalls SSO upstream) but **Windmill has the same
    behavior undocumented** (`app/Tools/Windmill.php` also has no
    `HasOidcWiring`, but no comment explaining why — a small, independent
    fix worth doing regardless of this plan, so nobody "fixes" the perceived
    gap later without knowing why it's there).
  - **Formalize this as required, centrally-tracked metadata per tool**
    (extending the existing but inconsistently-applied
    `app/Contracts/HasSsoLicenseCaveat.php` pattern — currently a real
    contract on `DataTool`, a comment-only convention on `CrmTool`, and
    missing entirely on `Windmill`) so the marketplace UI can surface
    "this feature needs your own vendor license" to browsers, instead of it
    living inconsistently in code comments.

## Explicitly out of scope

- **Full app-store model**: LaraKube processing payment, taking a
  commission, handling EULA/license pass-through, or vetting/scanning
  third-party container images beyond the minimum "known public registry"
  bar noted above. Real future aspiration, explicitly not built now — no
  headcount for the vendor-agreement/KYC/payout-infrastructure work it
  needs. Pure directory (list it, deploy it, vendor handles their own
  licensing/payment entirely off-platform) is the whole of this phase.
- Community template submission/review/moderation workflow (flagged above).
- Exact pricing of premium templates / which tools get premium variants —
  that's `monetization-strategy.md` §9 territory, not re-decided here.
- Teams/RBAC, OpenTofu remote state, Cloud's own deployment topology — all
  already resolved in `larakube-cloud.md`, cross-linked not duplicated.
- Release *revocation*/yank semantics beyond the `status=yanked` column
  existing — exact CLI behavior on a yanked release (hard-block redeploy? warn
  only?) needs its own small design pass once this ships.

## Critical files

- `plans/active/oss-tools-licensing-audit.md` — the open-source-license
  audit this plan's Licensing considerations section builds on; update its
  Redis row to "moot — replaced by Valkey" once that swap actually ships.
- `cli/app/Tools/Windmill.php` — needs the same "SSO is paywalled upstream"
  comment `N8n.php` already has.
- `cli/app/Contracts/HasSsoLicenseCaveat.php` — the contract to formalize
  and apply consistently (`DataTool` has it, `CrmTool`/`Windmill` don't).
- `cli/app/Enums/ClusterTool.php` — stays as-is; `tool_slug` values must
  match its `->value`s.
- `cli/app/Services/ToolRegistry.php` — pattern to mirror for the new
  template-resolution service.
- `cli/app/Traits/GeneratesProjectInfrastructure.php:1509` —
  `writeManagedManifest()`'s lock/checksum precedent, useful reference even
  though Cluster Tools don't use it today.
- `cli/app/Traits/InteractsWithOpenTofu.php` — precedent for "opt-in remote
  resource with env-var config and a local fallback," same shape this
  feature needs.
- `resources/views/k8s/**` — becomes the seed data for the first
  `cluster_tool_template_files` migration.
- New Cloud-side: migrations + models for the 5 tables above, API
  controllers under the Cloud app (location TBD once Cloud's own skeleton
  exists — per `larakube-cloud.md` §11, Cloud is itself scaffolded via
  `larakube new`).

## Verification

- No code exists yet for either side — this is a design-only plan.
- Once implementation starts: Cloud side gets normal Pest feature tests per
  `cli`'s own testing conventions (signature verification, entitlement
  grant-ledger fallback behavior, public-vs-premium access). CLI side needs
  the project's standard `Process::fake()`/`Http::fake()` discipline for the
  new fetch/cache/signature-verify service — no test should hit a real
  network call.
- End-to-end: pick one low-risk tool (e.g. `uptime:init`), migrate its
  existing Blade files into the marketplace as the first real
  `cluster_tool_template_release`, and confirm `uptime:init` on a fresh
  cluster produces byte-identical manifests to today's bundled-Blade path
  before converting any other tool.
