# Plan: Server / node security hardening (stub)

**Status:** ✅ BUILT — header called this a stub. Verified 2026-08-08: `cloud:harden` ships, described as "Harden an already-provisioned server (UFW firewall, fail2ban, key-only SSH) — safe to re-run any time".

## 🎯 Objective

`cloud:provision` stands up a usable k3s node, but doesn't harden it. Capture the
hardening checklist so a provisioned VPS/node isn't left with a default attack
surface. Applies to the VPS (Droplet) path primarily; DOKS nodes are managed by DO.

## ✅ Checklist (to design + build)

**Built (v1, in `cloud:provision` via `InteractsWithServerHardening`):**

### Firewall / ports
- [x] Enable a host firewall (UFW) with default-deny inbound.
- [x] Allow SSH (the live port, allowed *before* enable → no lockout), 80/443.
- [x] Allow k3s pod (10.42.0.0/16) + service (10.43.0.0/16) CIDRs so enabling UFW
      on a running cluster doesn't sever intra-cluster networking.
- [ ] Restrict the k3s API (6443) to the **operator's IP**, not 0.0.0.0/0
      (currently allowed open, with a printed warning).
- [ ] Audit kubelet (10250) exposure.
- [ ] Verify Traefik LB only advertises intended ports.

### SSH
- [x] Disable password auth (key-only).
- [x] fail2ban for SSH brute-force.
- [x] Disable remote **root login** (`PermitRootLogin no`) — GUARDED: only after
      `testSsh` + `canSudo` confirm `larakube` works (provision), or when harden
      runs as a non-root sudo user. Root account is kept (console/sudo/recovery).
- [ ] Optionally move SSH off 22.
- [DECIDED — won't narrow] Keep `larakube` full-sudo. Once root login is disabled,
      `larakube` IS the admin account and legitimately needs full sudo (provision/
      harden run apt/ufw/systemctl through it); scoping it to `k3s` only would leave
      NO remote admin path and break `cloud:harden`. The deploy automation's least
      privilege is enforced at the **K8s RBAC** layer instead ([[scoped-rbac-deploy]]),
      which is the correct layer. OS-level deploy isolation, if ever wanted, would be
      a SEPARATE dedicated deploy user — not a narrowing of `larakube`.

### k3s / cluster
- [ ] Ensure the admin kubeconfig (`/etc/rancher/k3s/k3s.yaml`) is root-only (600).
      NOTE: install sets `--write-kubeconfig-mode 644` (world-readable on the node)
      so the sync step can scp it — tightening needs the sync to use sudo. Deferred
      to avoid breaking sync.
- [x] Pairs with [[scoped-rbac-deploy]] — admin cert never leaves the box; CI uses
      namespace-scoped tokens.
- [ ] Review k3s flags (`--disable` unused components, secrets encryption at rest).

### Host
- [x] Unattended security updates (`unattended-upgrades`, folded into the hardening
      script so both `cloud:provision` and `cloud:harden` apply it).
- [ ] Drop unused listening services.

### Packaging
- [x] Standalone `cloud:harden {env}` reusing `InteractsWithServerHardening`
      (so an already-provisioned box can be hardened without re-provisioning).
      Resolves the server from a project env's cloud config, else prompts.
      SSH primitives shared via `InteractsWithRemoteSsh` (extracted from provision).

## 🛠 Possible command shape
- `cloud:harden {env}` — apply the firewall + SSH + k3s hardening over SSH, idempotent.
- Or fold key items into `cloud:provision` as a post-step with a `--harden` flag.

## ❓ Open
- UFW vs DO Cloud Firewall (API-driven) — which to standardize on?
- How much to automate vs document-and-let-the-user-confirm (destructive on SSH).
