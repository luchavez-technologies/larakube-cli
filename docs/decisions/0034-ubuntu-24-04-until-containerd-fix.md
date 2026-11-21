# 0034: Servers Boot Ubuntu 24.04 LTS Until k3s Ships the containerd AppArmor Fix

**Status:** Accepted (supersedes [0033](0033-ubuntu-26-04-only.md))

## Context

ADR 0033 made Ubuntu 26.04 the only server OS. On a 26.04 node (kernel 7.0), the Commons MariaDB never started: its entrypoint stopped at "Switching to dedicated user 'mysql'", `gosu` sat at its CPU limit as PID 1, and the liveness probe restarted it forever.

Ubuntu 26.04 sets `kernel.apparmor_restrict_unprivileged_unconfined=1`. The kernel then turns runc's exec-time profile change into a stacked label (`cri-containerd.apparmor.d//&unconfined`), and containerd's default AppArmor profile only allows signals to its own plain label. `gosu` is a Go binary whose setuid signals every thread, so it waits forever. Any image that drops privileges this way hangs, which includes the official MariaDB and Postgres images.

- containerd's fix, containerd#14487 ("apparmor: allow signals and ptrace for stacked profile peers"), merged 2026-11-16 but is in no containerd or k3s release yet; k3s-io/k3s#13625 is waiting on it.
- The only workarounds confirmed on Kubernetes are a 24.04 host or a pod `appArmorProfile: Unconfined`. The second has to be repeated on every affected pod and weakens confinement. The `apparmor_restrict_unprivileged_unconfined=0` sysctl is confirmed on Docker only.

## Decision

1. **New servers boot Ubuntu 24.04 LTS (Noble Numbat).** `UbuntuImageResolver` and the OpenTofu template fallbacks map each provider to its 24.04 image: Hetzner `ubuntu-24.04`, DigitalOcean `ubuntu-24-04-x64`, GCP `ubuntu-os-cloud/ubuntu-2404-lts-amd64`, AWS Canonical `ubuntu-noble-24.04-amd64-server-*`.
2. **Still one OS version, still no `--ubuntu-version`.** ADR 0033's reasoning for a single supported version stands; only the version changes.
3. **Existing-server onboarding asks for a fresh Ubuntu 24.04 server.**
4. **Moving to 26.04 again** waits for a k3s release that includes containerd#14487. Before switching, verify on a 26.04 node that the Commons MariaDB and Postgres reach Ready.

## Consequences

- A server already created on 26.04 is not changed and keeps the bug. Destroy it and create it again on 24.04; it cannot be fixed in place without a host-level workaround.
- CI and image builds (LaraKube Linux, builder, workspace) stay on 26.04 runners. The hang concerns containers on a 26.04 Kubernetes node, not those builds.
