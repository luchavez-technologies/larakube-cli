# One `tool:init` for Cluster Tools, schema first

**Status:** Stages 0-3 shipped. Stage 4 (retire the `{tool}:init` aliases and rewrite docs/hints) is not started and is gated on Desktop and docs no longer naming them. Alias list: `ToolInitCommands::LEGACY_ALIASES` (frozen, shrink only).

## Context
Cluster Tools have 32 concrete `*:init` leaf commands (~30 lines each, 1,040 lines) over 29 abstract category bases (7,888 lines) that hold the real deploy logic in one `deployXxx()` each. Every leaf's `handle()` is `renderHeader(); return $this->deployXxx();`. They differ only in their option sets and a 5-line `resolveEngine()` on five (pocketbase, directus, n8n, windmill, ocis). The flag sets are hand-written per leaf, and Desktop keeps its own hardcoded admin-email slug list (`ClusterToolController.php:108-112`) because the CLI never says what a tool needs. LaraKube Cloud will later feed tool metadata through the CLI, so this must come from the CLI, like `new:frameworks` did for apps.

User decisions: end state is one canonical `tool:init <environment> --tool=<slug>`; the old `{tool}:init` names are a **temporary** alias layer (deprecation banner, frozen list, removed once Desktop/docs/tests stop using them); **schema first, then the merge**.

Rules that bind the design (memory + CommandShapeTest): exactly one positional (`environment`), everything else a `--flag` (`--tool=` as `tool:add` already does); no hidden-flag commands; `*:init` idempotent and direct `kubectl apply` (ADR 0013/0014); naming via ToolInstance (ADR 0021). Never edit a live cluster; user runs `./build`.

## Stage 0: small bug found in the screenshot (do first)
`new` prints "SeaweedFS requires a one-time bucket creation… bucket `laravel`" for an app that **did** join the Commons (`cloud/.larakube.json` has `plex:[redis,seaweedfs]`, `.env` points at `seaweedfs.larakube-plex…`, `AWS_BUCKET=cloud-local`). `NewCommand.php:~266` skips Commons-backed components via `$config->isPlexBacked()`, but `$config` was loaded before `plex:join` wrote the file, so it never sees the join.
- Reload the config after `joinPlexCommons()` (`ConfigData::loadFromFile($projectPath)`) in `NewCommand`, `StatamicNewCommand`, `WordpressNewCommand`, `NextjsNewCommand`, and before the instructions loop.
- Test: with `plex` containing a storage driver, the post-install steps for it are not printed.

## Stage 1: init option spec in the CLI (single source)
- New value object `App\Services\Tools\InitOption` (key, type text|select|confirm, label, description, flag, required, default, options) reusing the field vocabulary of `new:frameworks` (`FrameworkCatalog`).
- `ClusterTool::initOptions(?engine): list<InitOption>` derived from capabilities already on the enum, so nothing is retyped per tool:
  - always: `--context`, `--force`; `--domain` (all but external-dns); `--vpn-only` (all but netbird, external-dns); `--proxied` via `InteractsWithIngressProxy` constants (default-on for kutt, pocketbase, directus);
  - `--admin-email` iff `requiresAdminEmail()`; `--no-plex` iff `supportsNoPlex()`; `--alias=*` iff multi-instance; `--app-name`/`--logo-url` iff `HasWhiteLabel`;
  - tool-specific extras declared on the vendor class through a new `App\Contracts\HasInitOptions` (grafana `--with/--no-logs/--traces`, matrix `--media-retention` `--no-host-port`, stalwart `--host-port`, penpot `--with-exporter`, ocis `--extensions`, sendrec `--allow-registration`, bulwark `--no-mail-restart`, netbird `--sso-domain`, external-dns `--zone --group --cloudflare-token`).
- `tool:list --json` rows (`ToolListCommand.php` ~l.147-182) gain `initFields` (the spec above) for every shipped tool, installed or not, replacing the need for a `requiresAdminEmail` special case (keep that field for old Desktop builds).
- **Golden test first**: snapshot the real option set of all 32 current leaves; `initOptions()` must reproduce each exactly (this proves the derivation before any command is deleted). Afterwards the leaves' hand-written signatures are checked against the spec (drift test, like `NewFrameworksCommandTest`).
- Reuse: `ToolListCommand`, `FrameworkCatalog` field shape, `ClusterTool::supportsNoPlex/supportsMultipleInstances/requiresAdminEmail/shippedCases`, `AbstractToolRemoveCommand::__construct` (signature-from-`tool()` pattern).

## Stage 2: Desktop renders the tool install form from the CLI
- `ClusterToolController::store()`/`InstallClusterToolRequest` take required inputs from the row's `initFields` (admin email, domain, tool-specific fields); delete the hardcoded slug list; `desktop/resources/js/pages/tools/index.tsx` renders `initFields` with the existing generic `components/framework-fields.tsx`.
- Guard test (like `NoFrameworkDataInDesktopTest`) forbidding the slug list and tool-name branches for form fields. Keep calling `tool:add` for now.

## Stage 3: the merge (behaviour-preserving)
- `App\Commands\Tool\AbstractToolInitCommand extends Command`: constructor takes the `ClusterTool`, builds `$signature` from `initOptions()` (the pattern `AbstractToolRemoveCommand` already uses); `handle()` = `renderHeader()` + the family's deploy method; engine comes from the spec, replacing the five `resolveEngine()` overrides.
- The 29 category bases extend it instead of `Command` and stop being `abstract`; `ClusterTool::initBase(): class-string` maps a tool to its family base (umami/plausible share `AnalyticsInitCommand`; drift test ensures every shipped tool has one). Fix the known smell: bases hardcode `ClusterTool::XXX` instead of `$this->tool` (analytics says "Umami" for both).
- `resume:init` (body lives in the class) moves into a `ResumeInitCommand` base like the others. Out of scope and left alone: `plex:init`, `backup:init`, `tls:init`, `snapshot:init`, `cloud:init*`, `init`, `drive:office:init`.
- New `tool:init {environment?} --tool=` (superset of options; rejects an option the chosen tool's spec doesn't list) builds the family command for the tool and runs it with the forwarded options. Idempotency, OpenBao pushes and `registerDeployedTool()` are untouched because the deploy methods are unchanged.
- Delete the 32 leaf classes. A registrar (`Artisan::starting`, as `AppServiceProvider` already does for stubs) registers each shipped tool's `{tool}:init` as a **deprecated alias**: same command, prints "use `tool:init --tool=<slug>`", listed in one frozen `LEGACY_INIT_ALIASES` array. A test fails if a tool outside that array gets an alias, so every tool added from now on exists only as `tool:init --tool=x` (adding a tool = enum case + vendor class + family base entry; no command class).
- `ClusterTool::initCommand()` keeps returning the alias name; callers (`ToolAddCommand`, `Vpn*Wire/Unwire`, `InteractsWithToolRegistry`, `ResolvesToolEnvironment`, `TogglesToolProxy`) switch to one helper `runToolInit($tool, $args)` that calls `tool:init --tool=`. `tool:add` stays the interactive multi-tool front end.

## Stage 4: retire the aliases (separate, gated)
Only after Desktop (already via `tool:add`) and docs stop naming `{tool}:init`: rewrite hint text (`resources/views/k8s/**`, ~25) and docs (`docs/docs`, ~109 + `docs/src`, ~35) to `tool:init --tool=<slug>`, migrate ~84 test files, delete the registrar and the frozen list. Do this in one release with a CHANGELOG/breaking-change note (`feat!`, pre-v1 bumps minor).

## Verification
- CLI: golden option-set test (32 leaves == `initOptions()`), drift test (every flag the spec names exists on the command), `tool:init` forwards options and rejects foreign ones, alias list frozen, `CommandShapeTest` (one positional) still green, existing `*InitCommandTest` files still pass through the aliases, full `pest --parallel` + PHPStan via the commit hook.
- Desktop: faked `tool:list --json` with `initFields` drives the install form; argv assertions in `ClusterToolsTest`; guard test; `composer ci:check`.
- Live (user, after `./build`, OrbStack): `larakube tool:init local --tool=vaultwarden` and `outline:init` alias behave identically to before (idempotent re-run, no secret rotation); install one tool from Desktop.
- Stage 0: run `larakube new demo --fast --postgres --seaweedfs` with the local Commons up and confirm no "one-time bucket" block is printed.

## Risks
- The derived option sets must match today's exactly; the golden test is the gate.
- Bases become instantiable, so any base that relies on being abstract (none found) would surface in the drift test.
- `--no-plex` means different things per tool (SQLite, PVC, dedicated DB); the spec carries the per-tool description text rather than one generic label.
