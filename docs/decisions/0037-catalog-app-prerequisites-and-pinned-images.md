# 0037: Catalog Apps Declare Prerequisites, and Every Catalog Image Is an Exact Tag

**Status:** Accepted

## Context

LaraKube Cloud installs apps from its catalog by running `cloud:apps:install` in a runner Job. Two problems showed up while moving the remaining apps into that catalog:

- Some apps cannot work alone. Bulwark is a webmail client for Stalwart, and `BulwarkInitCommand` refuses to run without it. From the Apps tab that surfaced only as a failed install Job.
- Several images used floating tags (`louislam/uptime-kuma:1`, `ghcr.io/umami-software/umami:postgresql-latest`, `directus/directus:12`, `penpotapp/*:2.17`). A release pins an image by digest when it is published, so a floating tag makes two publishes of the same template differ, and a reviewer cannot tell what a release runs from its source.

## Decision

1. **A catalog app lists the apps it needs.** `CatalogAppSeeder` carries `'options' => ['requires' => ['<slug>']]`. `InstallableApps::all()` exposes it as `requires`.
2. **Cloud enforces it twice.** `InstallServerAppRequest` rejects the install unless every required app has status `installed` on that server. The server's Apps tab disables Install and says which app to install first. The first is the guarantee; the second is only guidance.
3. **The CLI keeps its own refusal.** `*:init` still checks its prerequisite (for Bulwark, `isMailInstalled`). The catalog `requires` does not replace it, so the CLI stays correct when run by hand.
4. **Every image an app declares through `HasImages` is an exact release tag.** No major-only (`:1`, `:12`), minor-only, or `latest` tags for an app's own image. The tag is changed in the vendor's `images()` and the template together, and `AppImagePinsTest` fails if they disagree.
5. **Images change only after checking the registry.** The exact tag is the one the floating tag pointed to on the day it was pinned, verified by comparing digests. A newer version is a separate, deliberate change.
6. **The registry allowlist grows only for a registry an app needs.** `ImageDigestResolver` in the CLI and in Cloud must list the same registries.

## Consequences

- A user cannot start an install that is known to fail for a missing prerequisite.
- Only one level of prerequisite is supported, and "installed" means the Cloud's own record for that server, not a live cluster check. An app installed outside Cloud is not seen.
- Pinned images no longer move on their own, so security updates arrive only through a new release. The Plex Commons and bundled-database images (`postgres:15-alpine`, `busybox:1.36`, `mysql:8.4`, `mariadb:11.8`, `meilisearch:v1.12`, `seaweedfs:4.20`, `node:24-trixie`, `postgrest:v14.18`) are still minor or major tags and are not covered yet.
- Adding a registry means editing two files, and a test in each repo keeps them from drifting.
