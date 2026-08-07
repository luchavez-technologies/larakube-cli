# Plan: ARM / edge (single-board) deploy tier

**Status:** ⛔ NOT STARTED — confirmed 2026-08-08: `arm64` appears only in the bundle/k9s/update/remote-deploy paths (host-arch detection), never as an edge deploy tier.

## 🎯 Vision

Single-board computers (Raspberry Pi 4/5 8GB, Orange Pi, etc.) are now powerful
enough to run a real Laravel app. That makes them a great **cheapest tier** —
school projects, demos, homelab — and LaraKube's edge is the **portability
promise**: the *same blueprint* deploys to a Pi, a $6 VPS, or managed Kubernetes
(DOKS/EKS/GKE), and moving *up* is a one-line target change, not a rewrite.
"Build it on the Pi on your desk, then `cloud:deploy` it to DOKS for the demo."

## 🧱 Near-term: make the deploy arch-aware (prerequisite)

> **Status (2026-06-07): Phase 1 BUILT (unbuilt binary — user rebuilds).** Both
> deploy paths now resolve the platform instead of hardcoding amd64:
> `resolveDeployPlatform()` in `InteractsWithRemoteDeploy` picks
> `cloud.arch` override → SSH `uname -m` (sideload path) → kubectl node-arch
> (registry/managed path, e.g. DOKS) → `linux/amd64` fallback. New nullable
> `CloudData::$arch`. Command-builders take a `$platform` param (default amd64,
> so snapshots/old tests are unchanged). Mixed-arch managed pools resolve to
> null → safe amd64 default. Unit tests added in `RemoteDeployTest`. Testable on
> the existing **VPS staging** (SSH path) now; DOKS exercises the kubectl path
> (resolves amd64). Remaining: Phase 2 (Pi docs) + Phase 3 (tunnel).

The single blocker today is architecture. `cloud:deploy` hardcodes
`docker buildx build --platform linux/amd64` (assumes a DO droplet). A Pi is
**arm64** → the amd64 image gives `exec format error`, pod never starts.

Fix (small, and future-proofs ARM VPSes too — Graviton/Ampere):

- **Resolve the node arch** instead of hardcoding amd64. Options:
  - detect over SSH at deploy time (`uname -m` → `aarch64` → `linux/arm64`), or
  - a per-env knob `cloud.arch` (`amd64` | `arm64`), defaulting to amd64.
  - Probably: detect for VPS/sideload (we already SSH in), knob as override.
- Feed the resolved platform into `buildProductionImageCommand` /
  `buildAndPushImageCommand` (currently `linux/amd64` literal).
- Native build bonus: Apple-Silicon Mac → arm64 Pi is a *native* build (faster,
  no emulation), vs today's forced amd64 cross-build.
- Manifests: `imagePullPolicy: IfNotPresent` already set for sideload; arch
  doesn't change manifests, only the image build.

This benefits **any ARM target**, not just Pi — so it's worth doing regardless.

## 🌐 Reachability (mostly the user's network, document it)

A home Pi has **no public IP** — and often **CGNAT** (no inbound at all) or
ISP-blocked 80/443. So "reachable on the internet" is a network problem, not a
LaraKube feature:

- **LAN-only:** trivial — the Pi's local IP, no extra setup. (Great for demos.)
- **Internet, no CGNAT:** port-forward 80/443 (+22) on the router + **dynamic DNS**.
- **Internet, CGNAT / blocked ports:** a **tunnel** — Cloudflare Tunnel or
  Tailscale Funnel — which also sidesteps the public-IP requirement entirely.
- **TLS:** Let's Encrypt HTTP-01 needs port 80 reachable → broken under CGNAT.
  Use **DNS-01**, or a tunnel that terminates TLS (Cloudflare).

Possible future feature: a Cloudflare-Tunnel integration (vs. only docs) so a
home/edge node can be exposed without router surgery. (Relates to the existing
local-dev tunneling story — different use case.)

## 🐧 k3s-on-Pi nuances (for the docs)

- 64-bit OS required; enable cgroups: add `cgroup_memory=1 cgroup_enable=memory`
  to `/boot/cmdline.txt` (k3s needs it for memory limits).
- Single-node → k3s `local-path` storage is fine (no block-storage class needed).
- `cloud:provision` already installs k3s + hardens — should mostly "just work"
  on a Pi once arch is handled and SSH is reachable.

## 📦 Bundle path is actually the *better* Pi deploy story

`cloud:deploy` requires Docker on the Pi and a live SSH connection during the
build. The **bundle path** sidesteps both:

- Build on your Mac (`bundle:build airgap --arch=arm64`) — native arm64 build on
  Apple Silicon, no emulation.
- Copy to the Pi via SD card, USB stick, or `rsync` over LAN.
- `sudo ./larakube bundle:install` on the Pi — installs k3s offline, imports all
  images, generates certs. No Docker, no internet required.

This means the airgap bundle feature and the Pi story are the **same feature**.
The bundle already supports `--arch=arm64`; the only missing piece is the tunnel.

## 🌐 Cloudflare Tunnel — Phase 3 design

CGNAT (common in PH ISPs and most home connections worldwide) blocks all inbound
traffic, so port-forwarding and Let's Encrypt HTTP-01 are both dead ends. The
correct answer is **Cloudflare Tunnel** (`cloudflared`): the Pi dials *out* to
Cloudflare's edge; Cloudflare handles DNS, TLS, and inbound routing. No static
IP, no open ports, free tier.

### How it would integrate

**`bundle:build --tunnel` (or env-level flag)**
- Download `cloudflared` binary for target arch from GitHub releases.
- Write a `cloudflared-install.sh` helper into the bundle (configures systemd service).
- Record `tunnelEnabled: true` in `bundle.json`.

**`bundle:install` — tunnel branch**
- If `cloudflared` binary exists in bundle dir:
  - Prompt for the Cloudflare **tunnel token** (user creates the tunnel on
    dash.cloudflare.com → Zero Trust → Networks → Tunnels, copies the token).
  - Install `cloudflared` to `/usr/local/bin/cloudflared`.
  - Write `/etc/systemd/system/cloudflared.service` with the token.
  - `systemctl enable --now cloudflared`.
  - Skip the self-signed CA step entirely — Cloudflare terminates TLS at the
    edge with a real cert; internal traffic Pi→Cloudflare is already encrypted
    by the tunnel.

**Traefik adjustment**
- When tunnel mode is active, Traefik only needs to listen on an internal port
  (e.g. `8080`) — no need to bind 443 publicly.
- Cloudflare Tunnel routes `https://app.yourdomain.com → http://localhost:8080`.
- `larakube-reset` should also stop/disable `cloudflared.service`.

### What the user needs
1. A Cloudflare account (free).
2. A domain pointed at Cloudflare (can be a cheap `.com` or a free subdomain via
   Cloudflare for Teams if they don't have one).
3. Create the tunnel in the dashboard, copy the token — that's it.

No router access, no ISP cooperation, no static IP purchase.

## 🚦 Phases

1. **Arch-aware deploy** ✅ BUILT — node-arch detection + `cloud.arch` override.
2. **Pi quickstart docs** — `deployment/raspberry-pi.md`: cgroups note,
   bundle-path walkthrough, LAN vs internet, Cloudflare Tunnel for CGNAT,
   and the "move to DOKS" upgrade path.
3. **Cloudflare Tunnel integration** ✅ BUILT — `--tunnel` on `bundle:build`
   downloads cloudflared for target arch + sets `tunnelEnabled: true` in
   `bundle.json`; `bundle:install` prompts for token, installs
   `/usr/local/bin/cloudflared`, writes `/etc/systemd/system/cloudflared.service`,
   runs `systemctl enable --now cloudflared`; `larakube-reset` stops/removes it.
   Manual test §21 in `plans/testing-checklist.md` (Manual Test Guide section).

## ✅ Verification

- `bundle:build airgap --arch=arm64 --tunnel` on Apple Silicon Mac.
- Copy bundle to Pi, run `bundle:install` — enter Cloudflare tunnel token.
- App is live at `https://app.yourdomain.com` with a real cert, no port-forwarding.
- Re-target the env at DOKS + registry and `cloud:deploy` — same blueprint, no
  changes beyond the deploy target (the portability promise).
