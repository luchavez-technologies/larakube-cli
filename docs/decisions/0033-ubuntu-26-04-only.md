# 0033: Ubuntu 26.04 LTS Is the Only Supported Server OS

**Status:** Accepted

## Context

`cloud:create` could boot a VPS on Ubuntu 24.04 or 26.04 (`--ubuntu-version`, defaulting to 24.04), and the OpenTofu templates fell back to 24.04 images. Meanwhile the LaraKube Linux WSL image already builds from `ubuntu:26.04`, and GitHub Actions moved `ubuntu-latest` to 26.04. Carrying two server OS versions doubles what provisioning, hardening, and k3s compatibility must be verified against, for no user benefit on fresh servers.

## Decision

1. **New servers boot Ubuntu 26.04 LTS (Resolute Raccoon) only.** `UbuntuImageResolver` maps each provider to its 26.04 image (Hetzner `ubuntu-26.04`, DigitalOcean `ubuntu-26-04-x64`, GCP `ubuntu-os-cloud/ubuntu-2604-lts-amd64`, AWS Canonical `ubuntu-resolute-26.04-amd64-server-*`), and the templates' fallbacks match.
2. **`--ubuntu-version` is removed** from `cloud:create`. With one supported version there is nothing to choose.
3. **Existing-server onboarding targets 26.04.** Desktop and docs ask for a fresh Ubuntu 26.04 server.
4. **CI and image builds run on `ubuntu-26.04` / `ubuntu-26.04-arm` runners** (LaraKube Linux, builder, and workspace images).

## Consequences

- Servers created earlier on 24.04 keep running; nothing touches them. Only new provisioning changes.
- When the next Ubuntu LTS ships, moving to it is one resolver change plus template fallbacks, not a new flag.
