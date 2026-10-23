# Evaluate Docker Hardened Images (DHI) for PHP-based container images

## Status

Deferred — tracked after the PHP2Shell incident response (see
`fix(wordpress): bump pinned image off a WordPress line Docker Hub no longer
rebuilds`, cli `dd11198d`). Not started.

## Context

During PHP2Shell (CVE-2026-109618 / CVE-2026-109619, a critical unauthenticated
PHP RCE patched upstream 2026-10-21) we found `wordpress:6.7-php8.3-apache` had
been frozen on Docker Hub since 2025-04-11 — it will never receive a PHP patch
again no matter how often it's pulled, because Docker Hub only rebuilds tags
for WordPress' currently-maintained line. Fixed by bumping to
`wordpress:7.1-php8.3-apache`.

Separately, `serversideup/php:8.4-fpm-nginx-alpine` (the default image for
deployed Laravel apps, `InteractsWithDocker.php:91`) turned out to already be
rebuilt same-day as the PHP patch — no code change needed there, just a fresh
pull/redeploy.

This raised the question of whether LaraKube should move its PHP-based images
onto Docker's Hardened Images (DHI) catalog (`dhi.io/...`) instead of relying
on upstream maintainers rebuilding floating tags promptly.

## What was checked

- **`dhi.io/wordpress`** exists in the DHI catalog: Alpine 3.24-based,
  distroless (no shell, no package manager), PHP 8.3/8.4, gated behind a
  Docker subscription/login to pull (separate registry from Docker Hub). The
  catalog page itself had an inconsistency (tag said WordPress 7.0, "About"
  text said 6.9.x) worth re-checking before trusting it.
- **ServerSideUp has no hardened/distroless equivalent.** `serversideup/php`
  is built on the official `php` images with a shell, debug tooling, and
  nginx baked in — not hardened by design.
- **DHI PHP** (`dhi.io/php`) exists too, as a potential replacement base for
  the Laravel app image.

## Why this isn't a quick swap

1. **Distroless breaks shell-dependent tooling.** No shell, no package
   manager in the running container. Anything that does `kubectl exec`/
   `larakube shell` into these pods, or runs `composer`/`artisan` via an
   exec'd shell, needs auditing first — unclear how many CLI commands and
   init containers assume `sh -c` is available inside the WordPress or
   Laravel app image.
2. **Different registry/auth model.** `dhi.io/...` needs a Docker
   subscription for pull access, not a plain anonymous `docker pull` from
   Docker Hub — a new credential/secret to provision on every cluster this
   CLI builds, plus whatever `runtime:install`/image-pull-secret plumbing
   that implies.
3. **Not actually required to close PHP2Shell.** Both current fixes (the
   WordPress tag bump and the already-fresh `serversideup/php` rebuild)
   close the specific CVE without this migration — DHI is a longer-term
   attack-surface reduction (no shell, non-root, fewer packages), not a
   same-incident fix.

## Scope for the real evaluation

- [ ] Audit every code path that execs into a WordPress or Laravel app
      container (`larakube shell`, init containers, `run-proxy`-style
      artisan/composer dispatch) for a shell/package-manager dependency.
- [ ] Confirm DHI's actual pull/auth model for a self-hosted cluster (image
      pull secret? subscription tied to the user's own Docker Hub account vs.
      LaraKube's?) and who bears that cost — this is a real cost question,
      not just mechanical: don't propose a $ figure without checking current
      DHI pricing against this project's existing version-check standards.
- [ ] Re-verify the DHI WordPress image's actual bundled WordPress/PHP
      version against its own "About" text before trusting the tag name.
- [ ] Decide whether to pilot this on one image first (e.g. the Laravel app
      base image, since LaraKube controls that Dockerfile/build already) vs.
      WordPress, which is a prebuilt third-party image.
