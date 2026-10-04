# Workspace images: one per runtime, built from the project's own base

Status: PLAN, nothing built. Follows `remote-workspaces.md` (Step 1 is built with a single PHP image).

## What exists today
- The workspace image is `php:8.4-cli-bookworm` (the official image) plus code-server, Node 22, Composer and a fixed extension list from `install-php-extensions`. It is **not** a Server Side Up image.
- LaraKube's own app images already use `serversideup/php:{version}-{variation}` (`resources/views/docker/php.blade.php`, with the project's `getAllPhpExtensions()`), and the other frameworks use `node:24-alpine`, `golang`, `rust`, `dotnet/sdk`, `gradle:8-jdk21`. So a workspace and the app it edits run on **different PHP builds, users and extension sets**. "Works in the workspace, breaks in the deploy" is possible.
- One image only fits Laravel/Statamic/WordPress. Django, FastAPI, Next.js, Nest, Adonis, Astro, Vite, Docusaurus, Spring Boot, .NET, Gin and Axum have no toolchain in it.

## The questions, answered
1. **Should the PHP workspace use Server Side Up?** Yes, its `cli` variation, for parity with what we deploy: same PHP build, same extension mechanism, same `www-data` conventions, weekly security rebuilds. They publish `{php}-cli` tags for 8.2 to 8.5 on Docker Hub and GHCR (verified on their docs page). Check before relying on it: their repository's licence (a web search reported GPL-3.0, not confirmed) and whether `cli` is multi-arch, as redistributing a layered image has different obligations from only pulling it.
2. **One image per framework or per language?** Per **runtime**, not per framework. Frameworks in the same runtime share a toolchain; what differs is the project (packages, version) and that is installed into the persistent volume, not the image.
3. **One fat image with everything?** No. PHP + Node + Python + JDK + .NET + Go + Rust is 3 GB or more, slow to pull, and every language's security patches would force a rebuild of all.

## Design
Runtimes (one image family each; the version is part of the tag):

| Runtime | Frameworks | Base | Versions |
| --- | --- | --- | --- |
| php | Laravel, Statamic, WordPress | `serversideup/php:{v}-cli` | 8.2 to 8.5 |
| node | Next.js, Nest, Adonis, Astro, Vite, Docusaurus | `node:{v}` (Debian) | 22, 24 |
| python | Django, FastAPI | `python:{v}` | 3.12, 3.13 |
| java | Spring Boot | `eclipse-temurin:21` + Gradle | 21 |
| dotnet | .NET | `mcr.microsoft.com/dotnet/sdk` | 10 |
| go | Gin | `golang` | latest 1.x |
| rust | Axum | `rust` | 1 |

All share one Dockerfile with `ARG BASE_IMAGE` and a common layer: non-root user 1000, git, ssh, curl, code-server (pinned and verified), the entrypoint script, and Node (a PHP app needs Vite). So the family differs only by `BASE_IMAGE`.
- The framework to runtime mapping lives on `AppFramework` (next to `tech()`), so Desktop and Cloud read it from the CLI and keep no copy.
- `workspace:create` gets `--runtime` (derived from `--framework` when given) and `--runtime-version`; `workspace:options --json` lists runtimes, versions and the memory guidance for each, so Desktop draws the picker from the CLI.
- Extensions and system packages: the prebuilt PHP image carries the common set. A project that needs more uses the local-build path (`--rebuild`, with `--php-extensions=` taken from the blueprint), which keeps the current build code as the fallback.
- Project-aware create: from a project in Desktop, "Open in a workspace" pre-fills framework, runtime version and extensions from `.larakube.json`/`composer.json`.

## Publishing
- GitHub Container Registry (public; Docker Hub rate-limits anonymous pulls per IP). Multi-arch (amd64 and arm64) from a GitHub Actions matrix, rebuilt weekly and when a pinned version changes. Tags: floating `php8.4` and pinned `php8.4-cs4.139.1-r3`.
- The CLI pulls by tag on the server (no Docker needed locally, no SSH transfer). Local build and SSH sideload remain for `--rebuild` and offline use.
- CI verifies the code-server and base-image versions against upstream releases before publishing, per the repo's version-pinning rule.

## Phases
0. **Decide** (needs the owner): PHP base (Server Side Up vs the official image), the GitHub org and package name, which runtimes ship first. Verify licences and `cli` multi-arch.
1. **Refactor** `WorkspaceSpec` into a runtime-aware spec; add `--runtime`/`--runtime-version`; options JSON; Desktop picker. Tests: spec per runtime, unknown runtime refused, Dockerfile renders for each.
2. **Images and CI**: Dockerfile with `BASE_IMAGE`, workflow, first publish of php 8.4 and node 24, then the CLI pulls instead of building. Verify by running each image (clone, branch, `/healthz`, toolchain versions), as was done for the first one.
3. **Other runtimes** one at a time, by demand (python, then java, dotnet, go, rust). Rust and Java builds need more RAM, so they get their own size guidance.
4. **Project-aware create** from Desktop's Projects, and custom extensions.

## Gaps this plan does not close (separate work)
- **Seeing the running app.** Only the editor is tunnelled. code-server's built-in `/proxy/<port>/` path would expose `artisan serve` or a dev server through the same tunnel with no new ingress; a real preview URL needs the ingress and SSO work.
- **Backing services.** A workspace has no database, cache or bucket yet. Plan: a Plex Commons tenant per workspace, as `plex:join` does for apps.
- **Debugging.** Xdebug in the PHP image and the editor extension setup.
- **Disk.** Images are 0.5 to 1.5 GB each; a server pulling three runtimes needs 4 GB for images before any workspace volume.

## Risks
- Image upkeep is a standing cost (weekly rebuilds, version matrix). Mitigation: one Dockerfile, CI matrix, floating tags only for the base.
- Licence of a Server Side Up based image (see above). Fallback: official `php` image plus our own extension layer, losing prod parity.
- code-server's extension marketplace is Open VSX, not Microsoft's; some extensions are missing.

## Decisions (after review)
- **PHP base: Server Side Up `cli`**, floating tag per PHP version. Licence (reported GPL-3.0) and arm64 for `cli` still to be checked before the first publish.
- **Keeping up with their releases**
  - Their floating tags (`8.4-cli`) get weekly security rebuilds; ours follows. A scheduled workflow compares the base image's digest with the one recorded in our image's `org.opencontainers.image.base.digest` label and rebuilds, smoke-tests (starts, `/healthz`, `php -v`, `composer -V`, git) and publishes only on a change.
  - Tags: floating `php8.4` plus immutable `php8.4-r<date>`. Workspaces run the floating tag with `imagePullPolicy: Always`, so Resume picks up the new base; the volume (repo, vendor, extensions) is untouched.
  - The PHP matrix comes from the CLI's `PhpVersion` enum (`workspace:options --json`), so a new PHP version in the CLI is built automatically. code-server and Node pins are `ARG`s that Renovate bumps by pull request.
- **No `larakube up` inside a workspace.** `up` needs a container engine and a cluster; a workspace has neither by design (no Docker, no cluster API). The app runs inside the workspace with the runtime's dev command, which the CLI owns per framework (Laravel `composer run dev`, Next.js `npm run dev`, and so on). Shipping stays git push then CI/CD (ADR 0014).
- **Seeing the work: `workspace:open` forwards the app's dev ports as well as the editor.** The Service and pod expose the dev port per runtime (Laravel 8000 and Vite 5173, Next.js 3000, Django 8000, ...), so the app is at `http://127.0.0.1:<port>` on the developer's computer. Vite HMR works because it is not behind a path proxy. Dev servers must bind `0.0.0.0`; the CLI-owned dev command does that. A shareable preview URL waits for the ingress and SSO work.
- **`TunnelCommand` is not needed for this.** It forwards a project's database services to localhost; `workspace:open` already forwards the workspace's ports. It would matter later for reaching a workspace's Commons database from a local GUI.

## Decisions (images repo owns the images)
- The Dockerfile, the base images, the PHP extension list and the pinned code-server version now live in the `larakube-workspace` repository (`Dockerfile`, `images.json`), not in the CLI. Bumping any of them is a commit there; no CLI release is needed and the pipeline no longer installs the CLI.
- The CLI knows only what the UI needs (runtimes, versions, dev commands and ports, and which runtimes are published) and the image names it pulls: `ghcr.io/luchavez-technologies/larakube-workspace/<runtime>:<version>`. `WorkspaceRuntime::published()` is switched on when a runtime's image exists. A non-blocking job in the images repo reports when `workspace:images` and `images.json` disagree.
- `workspace:create` no longer builds or ships an image, so Docker is not needed on the user's computer. `--rebuild` is gone; `--image=` runs a custom image instead (pulled only when missing). Published tags are pulled on every start, so Resume picks up a rebuilt base.
- The LaraKube CLI is not bundled in the workspace image: its verbs that matter (`up`, `cloud:deploy`) need a container engine and a cluster, which a workspace does not have by design. Revisit for scaffolding or for the workspace MCP.
