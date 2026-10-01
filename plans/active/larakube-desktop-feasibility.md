# LaraKube Desktop — Feasibility Research & Plan

**Status:** Research / proposal. Nothing built.
**Verdict:** Feasible. NativePHP is not the hard part. The hard parts are
(1) the LaraKube CLI is a human TUI, not a machine API, and (2) Windows.

## Who it's for

An operator who never creates apps. They want to: pick a cloud (AWS / GCP /
DO / Hetzner), provision a server, then install Cluster Tools (`zitadel:init`,
`stalwart:init`, `netbird:init`, …) onto it. `larakube setup --profile=remote` already
cut their install down to kubectl + OpenTofu + a provider CLI. The desktop app
removes the terminal completely.

Out of scope for the desktop app: `new`, `init`, `up`, local clusters, container
runtimes, anything in the `local` profile. Those stay CLI-only.

## NativePHP Desktop v2 — verified facts (2026-09-26)

| Item | Finding |
|---|---|
| Latest release | `nativephp/desktop` **v2.3.1** (Sep 14 2026) |
| License | MIT, free |
| Runtime | Electron (v38 at the v2 launch), bundled static PHP (static-php-cli) |
| Requirements | PHP 8.3+, Laravel 11+, Node 22+ (build machine) |
| Target OSes | Windows 10+, macOS 12+, Linux |
| Cross-builds | Build per platform. Linux can build Windows via Wine; macOS→Windows is limited. **Use a CI matrix (macOS + Windows + Linux runners).** |
| Signing | macOS needs notarization or users get "app is damaged". Windows: Azure Trusted Signing or signtool. |
| Auto-update | GitHub Releases / S3 / DO Spaces. On macOS it only works on **signed** builds. |
| Child processes | `ChildProcess::start(cmd:, alias:, env:, cwd:, persistent:)`; stdout/stderr come back as `MessageReceived` / `ErrorReceived` events, the exit as `ProcessExited`; `->message()` writes stdin. The docs warn args differ between Windows and POSIX, so use full binary paths. |

Plain Laravel `Process` also works inside the app for short synchronous calls.
Livewire and Filament run unchanged, so the UI can match LaraKube Console.

## Architecture

```
┌──────────────── LaraKube Desktop (NativePHP / Electron) ───────────────┐
│  Livewire/Filament UI  →  CliRunner service  →  ChildProcess           │
└──────────────────────────────────────┬─────────────────────────────────┘
                                       │ argv only, --no-interaction
                                       ▼
          larakube <verb> <env> --flags --output=ndjson      (macOS/Linux)
          wsl.exe -d LaraKube -- larakube …                  (Windows)
                                       │
                       kubectl · tofu · aws · gcloud · ssh
```

**Decision: the desktop app shells out to the real `larakube` binary. It never
embeds CLI code.**

- One source of truth: every fix lands in `cli/app/Commands/*`, which follows
  the "command-driven fixes only" rule.
- The CLI keeps its own release cadence. The desktop app pins a minimum
  version and installs or updates the binary itself: install.sh on macOS and
  Linux, the WSL distro on Windows.
- Rejected: requiring the CLI as a Composer package. That means two Laravel
  apps in one process (Laravel Zero + Laravel), a lockstep release cycle, and
  the CLI's `Process::run('sudo …')` calls running inside Electron.
- Rejected: wrapping LaraKube Console. Console observes a cluster from inside it
  using a ServiceAccount. It can't provision a cluster that doesn't exist yet.
  Reusing its Filament components is still worth doing.

## Gap 1 — the CLI needs a machine interface (required, all platforms)

Today every flow ends in Laravel Prompts (`select`, `confirm`, `multiselect`).
A GUI can't answer a TUI through a pipe. Needed:

1. **Non-interactive completeness.** Every command the desktop app calls must
   run fully via flags. 59 commands already use
   `RequiresFlagsWhenNonInteractive`. Audit the desktop set
   (`setup --profile=remote`, `cloud:create`, `cloud:provision*`, the ~40
   `*:init`/`*:wire` tools, `*:show`, `*:remove`) and fix any prompt that still
   has no flag. This fits the existing hard rule, and headless CI benefits too.
2. **A structured output channel: `--output=ndjson`.** This is a global option
   that makes `LaraKubeOutput` emit one JSON event per line instead of styled
   text:
   `{"type":"step","id":"tofu-apply","status":"running"}`,
   `{"type":"log","line":"…"}`, `{"type":"open-url","url":"…"}` (gcloud/AWS
   login), `{"type":"result","ok":true,"data":{…}}`. Only 9 commands support
   `--json` today, so this is new work. Because most output already goes
   through `laraKubeInfo`/`laraKubeWarn`/`laraKubeError`, one trait change
   covers a lot of it.
3. **Discovery commands the UI can populate pickers from.** Examples: list
   providers, regions and sizes, list environments and their clusters, list
   installable Cluster Tools and their install state. Some already exist as
   `*:show`. The rest are small read-only commands that return JSON.
4. **Browser-auth handoff.** `CliTool::ensureAuth()` opens a browser from the
   CLI. Under the desktop app it must emit an `open-url` event (or use the
   provider's device-code flow), and the app opens the URL with
   `Shell::openExternal`. This is mandatory on Windows+WSL, where the CLI can't
   open the Windows browser reliably.
5. **sudo.** There are 138 `sudo ` occurrences. The remote profile mostly
   avoids them, but some installs (e.g. the AWS CLI fallback) still use sudo.
   The desktop app has no TTY for a password prompt. Every remote-profile tool
   must install user-local (`~/.local/bin`) without sudo. This is a small,
   contained audit of `CliTool::install()`.

## macOS specifics

No container on macOS. Linux containers there always run inside a VM
(OrbStack/Docker Desktop), which is exactly the install the remote profile
avoids. Desktop drives the **native standalone binary** that `./build` already
produces (`mac arm`, `mac x64`).

- **App-managed CLI, sudo-free.** Download the right-arch standalone binary to
  `~/Library/Application Support/LaraKube/bin/`, not `/usr/local/bin`. Tools
  (kubectl, tofu, aws, gcloud) install into the same directory. `CliTool`
  currently prefers Homebrew, and its brew-less kubectl path creates
  `/usr/local/bin`, which needs sudo. It needs an install-dir override
  (e.g. `LARAKUBE_BIN_DIR`).
- **"Install `larakube` command in Terminal"** is an opt-in menu item that
  symlinks the app-managed binary onto PATH, like VS Code's `code` command.
  The GUI and Terminal then share one CLI version, and the user can always
  drop to the terminal.
- **Existing Homebrew install:** the app uses its own copy by default. It
  doesn't silently adopt a brew `larakube` of unknown version, and it tells
  the user when both exist.
- **PATH gotcha:** GUI apps launched from Finder/Dock don't inherit the
  shell's PATH (no `/opt/homebrew/bin`, no `~/.local/bin`). The CLI has ~34
  `command -v` checks. `CliRunner` must pass an explicit PATH
  (app bin dir + Homebrew dirs + system) in every child-process `env`.
- **Shared state with Terminal:** `~/.kube/config`, `~/.ssh`, the gcloud/aws
  config dirs and `~/.larakube` are the same ones the CLI uses. That's a
  feature: anything set up in the app works in Terminal and vice versa.
- **Signing and notarization:** if the `larakube` binary ships **inside** the
  `.app`, notarization requires it to be signed with the Developer ID and
  hardened runtime. The static-PHP micro binary may need entitlements (e.g.
  `allow-unsigned-executable-memory`), so check this in the spike. Downloading
  it after install sidesteps nested signing, but the binary must still pass
  Gatekeeper, so sign the release binaries in the CLI pipeline anyway.
- **Alternative to check in the spike:** skip the separate binary and run
  `larakube.phar` on NativePHP's own bundled PHP (`ChildProcess::php`). That
  means one signed runtime instead of two. It only works if NativePHP's PHP
  build includes the extensions the CLI needs (posix, pcntl, …).
- **Architecture:** pick arm/x64 at runtime, or ship a universal2 app.

## Gap 2 — Windows

The CLI doesn't run natively on Windows today, and a native port is large.
Measured in `cli/app`:

| POSIX-ism | Occurrences |
|---|---|
| `sudo ` | 138 |
| `2>/dev/null` | 73 |
| `~/` paths | 69 |
| `command -v` | 34 |
| `\| kubectl` pipelines | 11 |
| `curl … \| sh/tar` installers | most of `CliTool` |

Also: `build` only produces mac/linux standalone binaries, and the test suite
fakes POSIX command strings everywhere. `Kubectl` (235 call sites) is
argv-based and would port cleanly. The other ~500 `Process::` calls wouldn't.

### Options

| | W1: WSL2, bundled distro (**recommended**) | W2: native Windows port |
|---|---|---|
| How | App runs `wsl --install --no-distribution` if needed (admin + one reboot), then `wsl --import LaraKube <dir> larakube-rootfs.tar` with a prebuilt minimal distro containing larakube, kubectl, tofu, aws, gcloud. Commands run as `wsl.exe -d LaraKube -- larakube …`. | Windows `build_standalone` target, Windows branches in `CliTool` (zip/winget), replace shell idioms, Windows CI. |
| Effort | ~1–2 weeks plus the distro build pipeline | Months, plus ongoing: every new command can break Windows |
| User friction | One UAC prompt + one reboot on first run. After that, invisible. | None |
| Risk | Corporate machines that block virtualization or WSL. Fallback: show a clear "WSL unavailable" screen. | Long-tail breakage, since the CLI's whole idiom is POSIX shell |

The existing `larakube setup` already tells Windows users to use WSL2, so W1
matches current policy. Shipping our own imported distro, not Store Ubuntu,
gives a known environment with the tools preinstalled. The desktop app manages
that distro's lifecycle. Kubeconfigs, SSH keys and provider credentials live
inside it, so the app never needs them on the Windows side.

Revisit W2 only if the WSL friction proves to be a real blocker for users, and
then only for the remote-profile subset.

## Workshop scope change (2026-09-27): attendees deploy a Laravel app

Workshop attendees don't only install Cluster Tools; they **deploy a Laravel
app**. That brings back `new`/`init`/`cloud:configure`/`cloud:deploy`, which
this plan had scoped out. Both paths currently need a **container runtime**:
- `larakube new` runs `laravel new` inside a container.
- `cloud:deploy` builds the image locally, then SSH-sideloads it (VPS) or
  pushes it to a registry.

Options, to decide before the MVP:

| | A. Build in CI (**leaning**) | B. Build locally |
|---|---|---|
| How | `cloud:configure --only=ci`: GitHub Actions builds and pushes to GHCR, then deploys. Students only `git push`. | Container runtime on each laptop |
| Windows | Nothing extra | Rootless Podman **inside the LaraKube WSL distro**. `setup` already supports it on WSL, so the `wsl` image target can preinstall it. |
| macOS | Nothing extra | Needs OrbStack/Docker Desktop/Podman machine (a VM) |
| Needs | GitHub account per student, git | Disk, RAM, and a big download on venue Wi-Fi |
| Open issue | `new` still runs `laravel new` in a container. Use a template repo students fork, then `init`, or a container-free `new` path. | None beyond the install |

ADR 0014 already says project apps ship via CI/CD, so A matches the existing
architecture. Git and GitHub CLI are now Readiness items, not optional
extras.

## Go/no-go status (2026-09-27)

| Criterion | Status |
|---|---|
| NativePHP drives the CLI via ChildProcess, streaming live | **GO**: real GCP `cloud:create` end to end, exit 0 |
| No terminal prompt blocks a GUI run | **GO**: `--no-interaction` defaults cover harden/k3s/kubeconfig; DNS/TLS offers become app follow-ups |
| Clean `--json` result for the app | **GO** after two fixes (SSH stdout leak in the CLI, TrimStrings in the desktop) |
| Cancel leaves nothing orphaned | **GO (safe)** after the tofu fix; a graceful mid-apply *stop* needs pcntl in the build |
| Child processes isolated from the desktop's own env | **GO** via `ToolLocator::isolate()` |
| macOS PATH-less launch | **GO** via explicit tool directories |
| Windows via WSL | **NOT STARTED**: spike 0b |
| Packaged, signed macOS build + auto-update | **NOT STARTED** |

## MVP progress

- **Design:** Figma "LaraKube Desktop" (`5lHxcb5oXNJGpCd4NotBw3`): Foundations
  plus Setup/Servers/Runs/Tools screens, matching the docs landing.
- **Built from it (2026-09-27):** restyle (Geist, tokens, sidebar), Servers
  list/detail, and destroy with type-to-confirm (`cloud:destroy <name>
  --force`). Also Activity, the run stepper and the result card. The CLI
  gained `cloud:stacks --json` with `ready`/`incomplete`/`unfinished`, via a
  shared `DiscoversUnfinishedStacks` trait.
- **Verified live:** "Destroy leftovers" on an unfinished GCP stack from the
  app succeeded, and the list refreshed to the two real servers.
- **Tools section (2026-09-27):** `/servers/{server}/tools` catalog from
  `tool:list --json --context=…`, plus detail, install and remove.
  - Install runs `tool:add --tool --context --domain --force`, with SSO/mail
    wiring offered only when installed. Remove runs `tool:remove` behind
    type-to-confirm. Open goes to the browser via `Shell::openExternal`.
  - `tool:list` takes **~30 s against a remote cluster** (per-tool kubectl
    round trips), so the desktop caches it per context for 10 min, lifts
    PHP's 30 s limit for that call, and drops the cache when an install or
    removal run exits. A fast CLI mode (e.g. one registry read + one
    `get deploy -A`) would remove the wait.
  - Some `*:init` need more than `--domain`, and those fail with
    MissingFlagException in the run log. Per-tool install forms are follow-up
    work.
- **Next:** Next-steps actions (`external-dns:init`, `tls:init`) need forms. Also
  structured progress events (`--output=ndjson`) to replace log-text matching
  in the stepper, and a fast `tool:list` mode.

## Spike progress (2026-09-27)

- **CLI:** `cloud:providers [--json]` added
  (`app/Commands/Cloud/CloudProvidersCommand.php`, tests in
  `CloudProvidersCommandTest.php`). It returns regions, sizes, defaults, and
  per-provider credential readiness mirroring the non-interactive `ensure*()`
  checks. Needs `./build` before the installed binary has it.
- **Desktop (`desktop/`):** Setup (readiness) page, Create server (VPS), and a
  live Run page with Cancel.
  - Runs are NativePHP `ChildProcess` starts of
    `larakube … --no-interaction [--json]` with an explicit PATH. Provider
    tokens go by env (`TF_VAR_do_token`, `HCLOUD_TOKEN`) and are never stored.
  - The `RecordRunOutput` listener folds stderr into the log and parses the
    stdout JSON line as the result. The page polls once a second.
  - `app_id = app.larakube.desktop`, app name "LaraKube Desktop".
- **Verified against the real machine:** the readiness props resolve all six
  tools. `cloud:providers` from source reports DO/GCP ready, and Hetzner/AWS
  not connected, in ~4s.
- **Environment isolation is mandatory:** child processes inherit the
  desktop app's own Laravel environment. Electron uses `...process.env`, and
  NativePHP sets `APP_CONFIG_CACHE`/`NATIVEPHP_*` for its PHP. The CLI, also
  Laravel, crashed on it (`Target class [db] does not exist`). Every CLI call
  now goes through `ToolLocator::isolate()`:
  `sh -c 'exec env -i NAME="$NAME"… "$@"'` with an allowlist, and secrets
  referenced by name so they never reach argv. The Windows adapter needs the
  same thing inside WSL.
- **First real end-to-end run (GCP, asia-east1, e2-small), 2026-09-27:**
  `cloud:create` from the app completed with exit 0. It covered tofu apply,
  SSH wait, harden, the `larakube` user, k3s, the kubeconfig merge and Traefik,
  all streamed live, with nothing hanging on a prompt. The Mac spike is a
  **go** on the core loop. Findings:
  1. **CLI bug:** `runRemoteCommand()` echoed remote SSH output to **stdout**
     even under `--json`. That breaks the one-result-line contract for Desktop
     and for LaraKube Cloud's job runner. Fixed to use stderr in JSON mode,
     like `StreamsProcessOutput`.
  2. **Desktop bug:** Laravel's `TrimStrings` trimmed every chunk NativePHP
     posts to `/_native/api/events`, stripping newlines. The log ran together
     and the result JSON couldn't be parsed (run `succeeded`, `result` empty).
     `_native/api/events` is now exempt from TrimStrings and
     ConvertEmptyStringsToNull.
  3. Under `--no-interaction` the CLI accepts the harden/k3s/`larakube`-user/
     kubeconfig defaults (all "yes"). It **skips** the Cloudflare DNS
     (`external-dns:init`) and TLS DNS-challenge (`tls:init`) offers. The app must
     offer those as explicit follow-up actions after a successful create.
  4. The CLI's closing "Next steps" text is terminal-oriented
     (`kubectl config use-context …`). The app should replace it with a
     Servers list and actions, and eventually hide it via a structured
     `nextSteps` field in the result.
  5. The local HTTP server already rejects requests without NativePHP's
     secret header/cookie (`PreventRegularBrowserAccess`). That partly
     answers the Authentication section's "local server exposure" check, but
     the app's own web routes still need the same verification.
- **Cancel test (GCP `cancel-server`, cancelled during `tofu apply`): FAILS
  SAFELY? NO.** The Run showed Cancelled and no `tofu` process survived. But
  GCP ended up with the instance and both firewalls **running**, while the
  tofu state recorded only `google_project_service.compute`. So
  `cloud:destroy` can't clean them up, and they leak and bill silently.
  - Likely mechanism: `ChildProcess::stop()` SIGTERMs `larakube`, PHP dies
    and closes tofu's pipes, tofu dies on its next write (SIGPIPE) before
    persisting state, and the GCP API calls already in flight complete
    server-side.
  - **Same bug hits LaraKube Cloud:** Kubernetes SIGTERMs a Job's pod on
    deletion or timeout.
  - **Fix in the CLI, not Desktop:** while OpenTofu runs, trap SIGTERM/SIGINT
    (pcntl), forward **SIGINT** to tofu (its graceful path: finish in-flight
    operations, write state), wait for it, then exit non-zero. Desktop's
    Cancel should say "Stopping… waiting for the provider to finish the
    current step".
  - Also: NativePHP reported exit code 0 for the killed process, so don't
    trust the exit code on a cancelled run.
  - **FIXED in the CLI (`InteractsWithOpenTofu::runTofuSurvivingInterrupts`):**
    1. tofu is `exec`'d with its output redirected to a temp log that
       larakube tails. It never writes to a pipe, so larakube dying can't
       SIGPIPE it mid-apply.
    2. Where pcntl exists, SIGTERM is turned into ONE graceful SIGINT to tofu
       and waited for. SIGINT (a terminal Ctrl+C, which already reached tofu
       through the process group) is waited on, not forwarded, since a second
       SIGINT force-quits tofu. Handlers are installed *before* the spawn: an
       ignored SIGINT stays ignored across exec.
    3. Signalling needed `exec`. Symfony's `start()` otherwise signals an
       `sh -c` wrapper, not tofu.
  - **Verified:** 4 real-subprocess tests (stub `tofu`), full suite green.
    Manual kill test with pcntl disabled: PHP dies, the stub still finishes
    and saves state.
  - **Build caveat:** the shipped standalone binary (phpacker `php-bin`)
    includes **neither pcntl nor posix**. So in production, Cancel means
    "larakube stops, tofu runs to completion and saves state". That's safe,
    but the server gets fully created and must then be destroyed. Getting a
    true graceful stop (SIGINT) needs pcntl in the build: a custom
    static-php-cli build, or ask phpacker to add it.
- **Previously open:** Cancel mid-run: whether it kills
  `tofu` grandchildren (SIGTERM reaches `larakube`; whether OpenTofu is
  orphaned is the open go/no-go question), and events arriving while the
  window is closed.
- Local OpenTofu is **v1.6.2**. Cloud jobs need ≥ 1.10 (`use_lockfile`), so
  Readiness should eventually flag stale versions, not just missing ones.

## Audiences & sequencing

- **First user (macOS).** The Mac build is both the go/no-go vehicle and a
  real deliverable.
- **Workshop (students, mostly Windows).** Windows can't be deferred. It's a
  hard requirement before the workshop, not a Phase 4 nice-to-have. The macOS
  work comes first and derisks everything shared (CLI machine interface, UI,
  `CliRunner`). Windows then only adds a transport adapter and first-run
  WSL setup.

## Toolbox image (proposed: one image, used as a build source)

Build one OCI image in CI, `larakube-toolbox`, containing the `larakube`
binary, kubectl, OpenTofu, aws, gcloud, hcloud and openssh, with versions
pinned in a single place. Before pinning, check each tool's current release.
No such image exists today.

**Use it as a build source, not as something users run:**
- **Windows:** `docker export` the image to a tarball; that becomes the WSL
  rootfs for `wsl --import LaraKube`. The CI pipeline builds one artifact and
  it serves two purposes. Students never need Docker.
- **CI / headless:** run `larakube` in pipelines without an install step.
- **macOS/Linux desktop:** keep the native standalone binary plus user-local
  tools. The remote profile's whole point was "no container runtime", and
  requiring one would bring the heavy install back.

**Why the desktop app should not *require* Docker to run the CLI:**
- Mac users would need Docker Desktop or OrbStack, an extra install bigger
  than the tools it replaces.
- Windows students would need Docker Desktop, which runs on WSL2 anyway. That
  adds a layer (and licensing terms for larger companies) without removing
  the WSL requirement.
- Container friction: bind-mounting `~/.kube`, SSH keys and provider
  credential directories, file ownership, SSH agent forwarding on macOS, and
  browser logins that redirect to `localhost` (gcloud) failing inside a
  container. Use device-code / `--no-launch-browser` flows.
- The gcloud SDK is large. Consider slim per-provider variants, or install
  gcloud on demand, so the WSL download stays workshop-Wi-Fi friendly.

**Optional later:** a "container mode" runner on macOS/Linux for users who
already have OrbStack/Docker, sharing the same image.

### Same image as the LaraKube Cloud job runner (decided direction)

`plans/active/larakube-cloud.md` §6/§10 already specifies a job container
holding the `larakube` binary, tofu, kubectl and ssh, which Cloud runs as
disposable Kubernetes Jobs executing `larakube cloud:create --json`.
`cli/plans/completed/cloud-headless-execution.md` already added the
non-interactive flags and `--json` to `cloud:create`, but they have **not been
verified by hand**. The desktop toolbox and the Cloud job image are the same
artifact. Build it once with multi-stage targets:

| Target | Contents | Consumer |
|---|---|---|
| `core` | `larakube` + tofu (≥ 1.10 for S3 `use_lockfile`) + kubectl + openssh, non-root user, no secrets | Cloud Jobs, CI |
| `core` + `aws` / `gcp` | adds one provider CLI | Cloud Jobs for that provider; keeps images lean |
| `wsl` | `core` + chosen providers + `/etc/wsl.conf`, default user, ssh-agent | exported to the Desktop's WSL rootfs |

- **Base:** Debian slim, not Alpine. AWS CLI v2's official installer targets
  glibc; verify before choosing a base.
- **Tagging:** tag = CLI version, published to GHCR by the same release
  pipeline as the binary (ADR 0025), so Desktop, Cloud and the CLI can't
  drift. Desktop pins a minimum image version the same way it pins the binary.
- **Credentials:** never baked in. Cloud injects them per Job; Desktop/WSL
  keeps them in the user's distro home.

**One machine protocol, not two.** Cloud v1 wants one final `--json` object;
Desktop wants live progress. Define `--output=ndjson` as a superset:
progress events stream, and the **last line is exactly the `--json` result
object**. Cloud v1 reads only the last line. Cloud's deferred "live log
streaming" (Phase 2+) then comes free, and the two front-ends share one
parser.

**LaraKube Console (in-cluster):** it should not embed the CLI; if it runs
actions, it dispatches toolbox Jobs.
- Today, `console/app/Jobs/RunLaraKubeCommand.php` expects
  `/usr/local/bin/larakube` inside the Console container. But `Dockerfile.php`
  never installs it, and nothing dispatches the job, so it's dormant.
- Console is designed as the **read-only** observer: a scoped `larakube-dashboard`
  ServiceAccount, and "Safe/Read-Only" in AGENTS.md. Copying the CLI into the
  long-running web pod would put a write-capable tool, and the credentials it
  needs, behind the web attack surface.
- If Console gains actions, it should create a Kubernetes Job from the toolbox
  image with its own action-scoped ServiceAccount, the same pattern as Cloud
  §10. Console then becomes the single-cluster, self-hosted counterpart of
  Cloud, and the read-only dashboard SA stays read-only.
- Until then, `RunLaraKubeCommand` is dead code and a candidate for removal.

**Execution venue differs, the image doesn't:** Cloud runs it as a K8s Job
(multi-tenant, one-time SSH keys). Desktop runs it in WSL (Windows) or skips
it for the native binary (macOS/Linux). Console's local-subprocess model from
the Cloud plan is the same shape as Desktop's.

## Features & design process (proposed)

The project exists at `desktop/`: Laravel 13, NativePHP desktop ^2.3,
Inertia v3 + React 19 + Tailwind 4, no auth. NativePHP's bundled PHP is 8.4.

**No Figma or brainstorm before the spike.** The spike's scope is fixed by its
go/no-go criteria, and the UI is throwaway. Design in code with the logo
palette. Figma comes after a "go", for 3–4 MVP screens only. The Figma
Starter plan allows ~20 MCP calls/month, so batch one page per call.

**Spike screens (Mac):**
1. **Readiness:** CLI found/installed + version, kubectl/tofu/aws/gcloud
   status, provider login status.
2. **Create server:** provider, region, size and stack name, then
   `cloud:create --no-interaction` with live streamed output and a Cancel
   button.
3. **Install a tool:** pick one `*:init`, pick an environment, run it,
   stream the output.

**MVP navigation**, mirroring the logo's three bottom cubes:

| Cube | Section | Contents |
|---|---|---|
| red `>_` | **Setup** | readiness check, CLI/tool install & updates, cloud logins, "Install `larakube` in Terminal" |
| teal `↑` | **Servers** | environments/stacks list, create server, destroy (type-to-confirm) |
| purple pulse | **Tools** | Cluster Tools catalog with installed state; install / show / remove |

A persistent **Activity** drawer holds every run's streamed output and its
result, and survives navigation.

**Palette (sampled by eye from `desktop/logo.png`, refine in design):**
blue `#5683E0` / `#4169C9` / `#CFDFF8`, red `#D6412D`, teal `#2C9FC0` /
`#8BDAED`, purple `#8457E0` / `#6D2BC6` / `#C1AEEE`. Each section takes its
cube's colour as accent. Blue is the primary/brand colour.

**Housekeeping before any packaged build:** `config/nativephp.php` still has
`app_id = com.nativephp.app`. Set a real reverse-DNS id first; the app
support dir, keychain entries and updater are keyed on it.

## Phased plan

**Phase 0: macOS spike (≈3–5 days, go/no-go)**
- A NativePHP v2.3.1 hello-app that runs `larakube cloud:stacks` through
  `ChildProcess`, streams output live into a Livewire view, and survives
  cancel/stop.
- One real end-to-end flow on a Mac: `cloud:create` on a cheap VPS, then one
  `*:init` tool, driven from the app with `--no-interaction`. Hand-parsing text
  output is fine here. The spike proves the loop, not the protocol.
- Local unsigned `.app` build.
- **Go** = the flow completes from the UI with live progress, plus a clean
  cancel. **No-go signals** = ChildProcess can't stream or cancel reliably, or
  too many flows are prompt-bound to fix cheaply.

**Phase 0b: Windows spike (right after a Mac "go", before the MVP work)**
- On a Windows VM: `wsl --import` a minimal rootfs, call
  `wsl.exe -d … -- kubectl version` from the NativePHP app, and confirm
  streaming and cancel work across the boundary.
- Build the Windows target in GitHub Actions. It can't be built on a Mac.

**Phase 1: CLI machine interface (in `cli/`, useful without the desktop app)**
- `--output=ndjson` in `LaraKubeOutput`, plus the `open-url` event from `ensureAuth`.
- Non-interactive audit of the desktop command set.
- sudo-free user-local installs for kubectl/tofu/aws/gcloud.
- JSON discovery commands (providers/regions/sizes, environments, tool catalog + state).

**Phase 2: Desktop MVP, macOS + Linux**
- First-run wizard: install/verify the CLI, then `setup --profile=remote`, then
  cloud login.
- "Create server": provider, region and size pickers, then `cloud:create`, with
  live progress steps.
- "Tools" screen: catalog with installed state; Install / Remove / Show runs
  `*:init` / `*:remove` / `*:show`.
- Signed + notarized macOS build, AppImage/deb for Linux, auto-update from
  GitHub Releases.

**Phase 3: Windows via WSL (must ship before the workshop)**
- WSL detection/installation flow, distro rootfs build in CI, `CliRunner`
  Windows adapter, Azure Trusted Signing.
- A **readiness check** screen, also runnable standalone. It checks that
  virtualization is enabled, WSL is installable (admin rights), there's enough
  disk, and the network reaches the provider APIs. It reports pass/fail in
  plain language.

## Workshop risks (Windows students)

A room of Windows laptops is the worst case for WSL. Most first-run problems
happen on the machine, not in the app:

| Risk | Mitigation |
|---|---|
| Virtualization off in BIOS/UEFI | Students run the readiness check **days before** and fix it at home. That can't happen live. |
| No admin rights (school/company laptops) | Readiness check flags it early. Pair those students up, or give them a prepared jump box. |
| Reboot needed after WSL enable | Do the full install before the workshop, never during it. |
| Slow venue Wi-Fi | Keep the WSL rootfs small and offer it as a separate download ahead of time. |
| Many students provisioning at once | Stagger provisioning. Pick small instance sizes. |
| **Cloud accounts/billing per student** | A logistics decision, not a code one. Options: shared org account with per-student scoped credentials, student credits (AWS Educate / GCP education credits, verify current programs), or a cheap provider (DO/Hetzner). Also decide who runs `cloud:destroy` afterward. |

Rehearse the whole flow with 2–3 people on real Windows laptops before
the workshop.

**Phase 4 (later)**
- Embed the LaraKube Console views for cluster health.
- Optional: `wire` flows (`sso:wire`, `mail:wire`) as UI toggles.

## Difficulty summary

| Area | Difficulty |
|---|---|
| NativePHP app + UI | Low–medium. It's Laravel/Livewire, which is familiar ground. |
| Driving the CLI (ndjson, flags, auth handoff) | Medium. Most of the real work, and it improves the CLI too. |
| macOS/Linux packaging, signing, updates | Medium. Needs Apple Developer membership and a CI matrix. |
| Windows via WSL | Medium. First-run UX plus a distro build pipeline. |
| Windows native CLI | High. Not recommended. |

## Authentication (proposed: no app login in the MVP)

- **Why not.** An app-level login protects nothing on a single-user machine.
  The real secrets (`~/.kube/config`, SSH keys, provider CLI credentials) sit
  on disk and are readable by anyone with the OS account, app login or not. A
  login would also add a server dependency and a sign-up step during the
  workshop.
- **Auth that is required:** the cloud provider logins (AWS/GCP), done through
  the provider CLIs' own browser or device-code flows via the `open-url` event.
- **Guard destructive actions instead.** `cloud:destroy`, `*:remove` and
  `cloud:nuke` require typing the resource name to confirm.
- **App-held secrets go in the OS keychain.** Example: a DO/Hetzner API token
  the user pastes into the app. Electron `safeStorage` is one option; the
  spike must check whether NativePHP exposes it. Never store these in the
  app's SQLite unencrypted.
- **Check in the spike: the local PHP server's exposure.** NativePHP serves
  the app from a local HTTP server. Confirm that another local process, or a
  web page in the user's browser, can't call its routes (for example to
  trigger `cloud:create`). This is the real attack surface.
- **Later, optional:** "Sign in to LaraKube Cloud" using the planned Passport
  Device Grant, for teams/RBAC or managing workshop cohorts. Local features
  never depend on it.

## Open questions

1. ~~Target OS?~~ Answered: the first user is on macOS, and workshop students
   are mostly Windows. Windows is required before the workshop. When is the
   workshop? That sets the Phase 3 deadline.
2. Does the app ship inside the monorepo (`desktop/`) or as a separate repo?
3. Does the Apple Developer / Azure signing budget exist? Without it, macOS
   shows "damaged app" warnings and auto-update is off.
4. Should cloud credentials stay entirely in the provider CLIs (recommended),
   or does the app need its own vault?

## Sources
- https://nativephp.com/docs/desktop/2/getting-started/introduction
- https://nativephp.com/docs/desktop/2/getting-started/installation
- https://nativephp.com/docs/desktop/2/digging-deeper/child-processes
- https://nativephp.com/docs/desktop/2/publishing/building
- https://nativephp.com/docs/desktop/2/publishing/updating
- https://github.com/NativePHP/desktop/releases
- https://nativephp.com/blog/nativephp-for-desktop-v2-released
