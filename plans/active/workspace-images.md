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
