# speckit.plan: Portable — Complete Local Environment (trust + ingress + hosts)

**Status:** ✅ BUILT — verified 2026-08-08: the `portable` command "Generate[s] a CLI-free larakube.sh wrapper (and optional guide) so teammates can run this project locally without installing LaraKube".

## 🎯 Objective

Make the CLI-free `larakube.sh` wrapper capable of the **full** local dev
experience — not just lifecycle. Today `forward` port-forwards the web pod to
localhost, which bypasses Traefik, so **Vite HMR** (`vite-<name>.dev.test`)
and **Reverb WebSockets** (`reverb-<name>.dev.test`) — referenced by the app
via their own ingress hostnames — don't work. Real React+Vite+Reverb projects
need the ingress + trusted TLS + hostnames, like the CLI provides.

Add `trust`, `traefik` (ingress + cert), and `hosts` to the wrapper so a
no-CLI teammate gets a working `https://<name>.dev.test` with HMR and sockets.

## 🧩 What the CLI does (and where the bits live)

`setupTraefik` (local):
1. `kubectl apply` a Traefik install manifest — `view('k8s.traefik-install')` (static, no data).
2. ConfigMap `traefik-config` from `view('traefik.dev-certs')` (static dynamic-TLS config).
3. Secret `traefik-certificates` from `local-dev.pem` + `local-dev-key.pem`.
4. Restart Traefik.

`trust` installs `local-ca.pem` into the OS trust store (macOS keychain;
Linux `/usr/local/share/ca-certificates/` + `update-ca-certificates`, or
`/etc/pki/ca-trust/...`).

`hosts` appends `*.dev.test` entries to `/etc/hosts`.

**Available to the wrapper today:** the certs (`local-ca.pem`,
`local-dev.pem`, `local-dev-key.pem`) are in `.infrastructure/traefik/certificates/`.
**Not available:** the Traefik install manifest + dev-certs config (CLI blade
views) — these must be emitted into the project by `larakube portable`.

## ⚠️ The hard part: clusters differ on Traefik

| Cluster | Traefik | Wrapper action |
|---|---|---|
| **k3s** | **ships Traefik built-in** | DON'T install another — apply only the dev-cert config to the existing Traefik (k3s uses HelmChartConfig / its own config surface — needs care) |
| Docker Desktop / OrbStack | none | full Traefik install + cert + secret |
| k3d | LaraKube installs its own | full install + cert |

A naïve `traefik install` would collide with k3s's built-in controller. The
`traefik` command must detect the cluster (kubectl context / existing
`traefik` resources) and either install or just configure the cert.

## 🧱 Design

1. **`larakube portable` emits the Traefik assets** into the project (so the
   standalone script has them): e.g. `.infrastructure/portable/traefik-install.yaml`
   and `.infrastructure/portable/traefik-dev-certs.yml`, rendered from the CLI
   views at generation time. (Certs already present.)
2. **`larakube.sh trust`** — install `.infrastructure/traefik/certificates/local-ca.pem`
   into the OS store (macOS/Linux branch; sudo). Self-contained, cluster-agnostic.
3. **`larakube.sh traefik`** — detect cluster:
   - existing Traefik (k3s) → apply only the cert config/secret to it.
   - none → apply the emitted install manifest + cert ConfigMap/Secret + restart.
4. **`larakube.sh hosts`** — read the project's ingress hostnames
   (`kubectl get ingress -n <ns> -o jsonpath` after `up`, or derive from
   `.larakube.json`) and append them to `/etc/hosts` → 127.0.0.1 (sudo).
5. **`forward`** stays as a documented fallback for quick "is it up?" checks
   on simple (no Vite/Reverb) apps; LOCAL_DEV.md explains when each applies.

## 🚦 Phases

1. `trust` — smallest, self-contained, immediately useful even before the rest.
2. `hosts` — kubectl-derived ingress hosts → /etc/hosts.
3. `traefik` — the cluster-aware one; emit assets from `larakube portable`,
   detect-and-apply. Most work; needs testing across k3s / Docker Desktop / k3d.

## 🧪 Verification

- On Docker Desktop/OrbStack and on k3s: after `up` + `trust` + `traefik` + `hosts`,
  `https://<name>.dev.test` loads with a trusted cert, Vite HMR connects, and
  Reverb WebSockets work — no CLI installed.
- `trust`/`hosts`/`traefik` are idempotent (safe to re-run).

## ⚠️ Notes / risks

- **k3s built-in Traefik collision** is the central risk — get detection right
  before shipping `traefik`.
- These commands need **sudo** (trust, hosts) — prompt clearly.
- This grows the wrapper toward CLI parity. Keep the line: the wrapper is for
  *running* a project locally; authoring/regenerating manifests stays CLI-only.
- Timing: after current stabilization. `trust` (Phase 1) is a safe early add if
  wanted sooner.
