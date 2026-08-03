# Vanity Local Domains — `.kube` TLD with Persistent Local CA

## Goal

Replace the `.dev.test` domain (borrowed from ServerSideUp's pre-baked wildcard cert) with a LaraKube-owned `.kube` TLD backed by a persistent local CA that LaraKube generates and manages itself. This removes the last local-dev dependency on SSU's certificate decisions.

---

## Background

Currently, local dev TLS works by reusing ServerSideUp's wildcard cert for `*.dev.test`. This:
- Gives no control over cert renewal or SAN coverage
- Limits subdomains to one level (`app.dev.test` ✓, `admin.app.dev.test` ✗)
- Ties LaraKube's local DX to an external project's decisions

LaraKube already generates its own CA in `bundle:install` (airgap). The same machinery applies here.

---

## Design

### Domain shape

```
{app}.kube                 → app root
{service}.{app}.kube       → per-service (reverb, minio, admin, etc.)
```

Examples:
```
hospital.kube
admin.hospital.kube
reverb.hospital.kube
minio.hospital.kube
```

The per-app wildcard cert covers `{app}.kube` + `*.{app}.kube` — one level of service subdomains, which covers every LaraKube-managed service.

### Persistent local CA

Stored at `~/.larakube/ca.crt` and `~/.larakube/ca.key`. Generated once on first `larakube trust` run, reused for every subsequent app. Users trust it once; all future app certs are auto-trusted.

### Per-app cert

Generated during `larakube up` if not already present (or if expired). Stored at `~/.larakube/certs/{app}/`. SANs: `{app}.kube`, `*.{app}.kube`.

### DNS resolution

Two paths depending on whether dnsmasq is present. `larakube up` adapts automatically.

**With dnsmasq** (preferred):
- `larakube trust` writes `/usr/local/etc/dnsmasq.d/larakube.conf` (macOS) or `/etc/dnsmasq.d/larakube.conf` (Linux) with `address=/.kube/127.0.0.1`
- Wildcard: all `*.kube` resolves to localhost, zero per-app work

**Without dnsmasq** (fallback):
- `larakube up` writes a `# larakube: {app}` block to `/etc/hosts` with each named host for the current app
- `larakube down` removes the block
- Requires one `sudo` prompt per app on first `up`

---

## `larakube trust` — Revised Flow

```
larakube trust
  │
  ├─ 1. Generate ~/.larakube/ca.crt + ca.key  (skip if already present)
  ├─ 2. Install CA into system keychain
  │       macOS:  security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain
  │       Linux:  cp ca.crt /usr/local/share/ca-certificates/larakube.crt && update-ca-certificates
  │
  └─ 3. DNS setup
        ├─ dnsmasq detected → configure *.kube → 127.0.0.1, restart
        │
        └─ dnsmasq NOT detected →
              "dnsmasq not found. Install it for automatic wildcard DNS? (recommended)"
              ├─ YES → brew install dnsmasq  /  apt install dnsmasq  /  dnf install dnsmasq
              │         → write config, restart
              │
              └─ NO  → "No problem — larakube up will manage /etc/hosts entries instead."
```

`larakube trust` always succeeds regardless of the dnsmasq choice.

---

## `larakube up` — DNS Fallback Behaviour

When dnsmasq is absent, `larakube up` manages `/etc/hosts`:

```
# larakube: hospital
127.0.0.1 hospital.kube
127.0.0.1 admin.hospital.kube
127.0.0.1 reverb.hospital.kube
127.0.0.1 minio.hospital.kube
# end larakube: hospital
```

`larakube down` removes the block. Re-running `larakube up` rewrites it (idempotent). Requires sudo — prompted once, not on every command.

---

## The k9s Parallel

| | k9s | dnsmasq |
|---|---|---|
| Required? | No | No |
| Auto-detected? | Yes | Yes |
| Offered during setup? | Yes | Yes (`larakube trust`) |
| Without it | Use `kubectl` directly | `/etc/hosts` managed by `larakube up/down` |
| With it | Richer TUI | Wildcard DNS, zero per-app friction |

---

## TLD Choice: `.kube`

`.kube` is not ICANN-reserved (unlike `.test`, `.example`, `.localhost`). The brand value outweighs the theoretical future-conflict risk; if ICANN ever registers `.kube`, `larakube heal` can migrate all projects. Fallback plan: append `.test` to get `{app}.kube.test` (ICANN-reserved) — but this is a last resort, not the default.

---

## What Changes

| Component | Change |
|---|---|
| `larakube trust` | Generates persistent CA; installs to keychain; optional dnsmasq setup |
| `larakube up` | Generates per-app cert signed by local CA; writes `/etc/hosts` if no dnsmasq |
| `larakube down` | Removes `/etc/hosts` block if written |
| `larakube new` / `larakube init` | Defaults app domain to `{appname}.kube` |
| `larakube heal` | Regenerates certs + updates host entries for existing projects |
| Dockerfile.php / manifests | No change — Traefik ingress rules already use the configured hostname |
| `.dev.test` | Retired; `larakube heal` migrates existing projects |

---

## Phasing

### Phase 1 — Own the CA (non-breaking)
- `larakube trust` generates persistent `~/.larakube/ca.crt`
- `larakube up` generates per-app cert signed by it
- Domain still `.dev.test` — no breaking change
- Validates the cert pipeline end-to-end before changing URLs

### Phase 2 — Switch to `.kube` (breaking, minor version bump)
- Default domain changes to `{appname}.kube`
- `larakube trust` gains dnsmasq detection + optional install
- `larakube up` gains `/etc/hosts` fallback management
- `larakube heal` migrates existing `.dev.test` projects
- Changelog entry + migration note

### Phase 3 — Polish
- `larakube trust --check` diagnostic: is CA trusted? is DNS resolving? is cert valid?
- `larakube trust --reset` regenerates CA and re-signs all app certs

---

## Open Questions

- **Windows**: no dnsmasq. Options: WSL2 dnsmasq, hosts-file only, or defer. Hosts-file fallback covers it for now.
- **Cert expiry**: local dev certs can have long TTLs (e.g. 825 days, the browser max). `larakube up` can auto-renew on expiry silently.
- **App name conflicts**: two projects named `hospital` on the same machine share the cert. Acceptable — same dev, same machine.
