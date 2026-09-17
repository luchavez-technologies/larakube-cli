# Plan: `tls:init` / `tls:remove`, Let's Encrypt via the Cloudflare DNS challenge

**Status:** Phase 0 ✅ done (`d8a51de`, verified live on production). Phase 1 ✅ built; `tls:init` switched production to the DNS challenge (Traefik args/env verified, sites 200). Still to prove: a certificate actually issued through DNS (walkthrough Phase 1c). `tls:prune` ✅ verified live (12 unused certificates removed, backup kept, hosts unchanged). Phase 2 ✅ `cloud:proxy`/`cloud:unproxy` verified live: cli.larakube.app resolves to Cloudflare IPs, serves 200 with cf-ray.

**Phase 1 deviations from this plan:**
- **Managed (DOKS) clusters are refused for now.** Their Traefik install path never re-renders an existing install, so there is no safe apply path yet. Both templates already render the DNS challenge.
- **No tool-registry row.** `dns:init` doesn't register one either; `tls:show` is the status view.
- **Every cloud Traefik re-render keeps the cluster's own ACME email** (read from the running Deployment) over the operator's global config.
**Walkthrough:** `plans/active/tls-dns-challenge-testing.md`

## Why

Traefik gets every certificate through Let's Encrypt's **HTTP challenge**
(`acme.httpchallenge.entrypoint=web` in `k8s/traefik-cloud` and
`k8s/traefik-managed`). Let's Encrypt fetches a token over port 80 and Traefik
answers it. That fails once a host is proxied through Cloudflare (orange
cloud): Cloudflare reaches the origin over HTTPS, so the port-80 handler never
sees the request. Renewal then fails silently until the certificate expires,
and Cloudflare in Full (strict) serves 526s. This is why every ingress defaults
to DNS-only and the static-site template warns against proxying.

The **DNS challenge** proves domain control by writing a temporary
`_acme-challenge` TXT record through the Cloudflare API. Nothing has to reach
the server, so it works for proxied and DNS-only hosts alike, and allows
wildcard certificates.

## Relationship to `dns:init`

None, apart from the credential. `dns:init` runs ExternalDNS, which creates
A/CNAME records from ingresses. Certificates are Traefik's job. Both need a
Cloudflare token that can edit DNS, so `tls:init` **reuses** a token
`dns:init` stored when there is one, and asks for one when there isn't.
`tls:init` never requires `dns:init`, and `dns:init` never touches Traefik.

## Commands

### `tls:init {environment}`
Switches the cluster's `letsencrypt` resolver from the HTTP challenge to the
Cloudflare DNS challenge.

1. **Refuse `local`.** Local clusters use the LaraKube Local CA, not ACME.
2. **Resolve the token** (never through argv):
   - **`dns:init` has run**, i.e. `larakube-shared/cloudflare-token-*` Secrets
     exist:
     - One Secret: "Reuse the Cloudflare token from `dns:init` (group
       `luchtech-dev`: zones …)?" Confirm, or enter a different token.
     - Several: a picker by group, listing each group's zones. Non-interactive
       runs require `--group=`.
   - **`dns:init` has never run** (no stored token):
     - Explain that ExternalDNS is **not** required. DNS records can stay
       hand-managed; this only writes short-lived `_acme-challenge` TXT records.
     - Say what the token needs (Zone → Zone → Read, Zone → DNS → Edit, for
       every zone the cluster serves), and link Cloudflare's "Edit zone DNS"
       token template.
     - Prompt with `password()`. Non-interactive runs read
       `LARAKUBE_CLOUDFLARE_TOKEN` from the environment, and fail with that
       variable's name when it's missing.
     - If the domain isn't on Cloudflare at all, stop: this command is
       Cloudflare-only, and the HTTP challenge keeps working.
3. **Verify the token** before touching Traefik, through the existing
   Cloudflare Saloon connector:
   - The token is valid and active.
   - It lists zones.
   - **It can actually write:** create and delete a
     `_larakube-tls-check.<zone>` TXT record in each zone it will serve. A
     read-only token otherwise passes every other check and fails at renewal
     time.
4. **Coverage preflight.** Collect every ingress host on the cluster that uses
   `certresolver: letsencrypt`, and match each against the token's zones.
   - **Any host outside those zones:** refuse and list the hosts. After the
     switch those hosts could never renew. The fix is widening the token, not
     a flag.
   - **Mixed-provider clusters** (some zones not on Cloudflare) are out of
     scope for v1; see Open questions.
5. **Show the plan:** zones covered, host count, that existing certificates are
   **kept** and renew through DNS when due (no mass re-issue), and a Traefik
   restart of a few seconds (single replica). Destructive confirmation.
6. **Store the token in Traefik's own namespace** as `traefik/traefik-acme-cloudflare`
   (key `token`). A pod can't reference a Secret in another namespace, so even
   a reused `dns:init` token is copied. Re-running `tls:init` re-syncs the copy
   after a token rotation.
7. **Re-render and apply Traefik** through the same render path `cloud:init`
   uses (below). Wait for the rollout.
8. **Register** a `tls` row in the tool registry (no host; records the token
   source: `dns:<group>` or `standalone`) so `tool:list` shows it.

### `tls:remove {environment}`
Switches back to the HTTP challenge.

- **Refuse while any ingress is proxied**
  (`external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"`), and list
  those hosts. Their renewals would start failing.
- Re-render Traefik with the HTTP challenge, delete
  `traefik/traefik-acme-cloudflare`, unregister.
- Never touches `larakube-shared/cloudflare-token-*` (that's `dns:init`'s).
- Certificates in `acme.json` are kept.

### `tls:show {environment}`
Shows the challenge in use, the token source, the covered zones, and any
uncovered hosts (the preflight from step 4, read-only). It also lists
**stored certificates with no ingress**: Traefik renews every certificate in
`acme.json`, used or not. After the Phase 0 upgrade, production logged failed
renewals for 5 removed tools (`sheet`, `inbox`, `flow`, `desk` on luchtech.dev,
`mail.larakube.app`). Under the HTTP challenge they fail harmlessly (no DNS).
Under the DNS challenge they would **succeed**, issuing certificates for dead
hosts. Listing them is read-only; removing them stays a manual step, since it
means editing `acme.json`.

### `tls:prune {environment}`
Removes stored certificates no ingress uses from `acme.json`. Traefik renews
every certificate it holds and has no API to delete one, so each removed tool
leaves one behind.
- Reads `acme.json` over `kubectl exec` (decoded as objects so Traefik's `{}`
  values survive), drops certificates whose main domain and SANs are all
  unrouted, and lists them for confirmation.
- Backs up to `acme.json.bak` in place, writes the result on stdin to a temp
  file (mode 0600) and swaps it in, then restarts Traefik immediately so it
  can't save the pruned certificates back from memory.
- Re-reads the stored domains to confirm they're gone. Nothing touches the
  local disk. Managed clusters are refused for now.

## One render path for Traefik (the part that must not regress)

`ProvisionsK3sNode` (`cloud:create`, `cloud:init`, `traefik:setup`) and
`CloudProvisionDoksCommand` each render Traefik from a template that only knows
`email` (+ `ip`). Re-running any of them after `tls:init` must **keep** the DNS
challenge, or it silently reverts to HTTP.

- New helper `traefikAcmeChallenge(string $kubectl): array` on
  `InteractsWithTraefik`. It reads the cluster: if
  `traefik/traefik-acme-cloudflare` exists, it returns the DNS challenge,
  otherwise the HTTP one. The cluster is the source of truth, not a flag, so
  every caller agrees.
- Both templates `@include('k8s.traefik.acme-args')` instead of hardcoding
  `httpchallenge`:
  - HTTP: `--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web`
  - DNS:
    - `--certificatesresolvers.letsencrypt.acme.dnschallenge.provider=cloudflare`
    - `--certificatesresolvers.letsencrypt.acme.dnschallenge.resolvers=1.1.1.1:53,1.0.0.1:53`
      (check against Cloudflare's own resolvers, not the node's)
    - `CF_DNS_API_TOKEN` from `secretKeyRef` `traefik-acme-cloudflare/token`
- **The resolver name stays `letsencrypt`**, so none of the ~42 ingress
  templates that set `certresolver: letsencrypt` change, and no host is
  re-issued at switch time.

## Phases

### Phase 0: Traefik version (prerequisite, its own commit) ✅
Both templates pin the floating `traefik:v3.1`, and production runs it. The
latest stable release is **v3.7.13** (2026-09-04; re-check at implementation
time). The DNS challenge options were renamed after 3.1
(`delayBeforeCheck` → `propagation.delayBeforeChecks`).
- Pin an exact version in both templates.
- Read the 3.1 → 3.7 migration notes for anything affecting the current args
  (entrypoints, redirections, hostPort, the file provider, the dashboard).
- Verify live via `traefik:setup production` **before** Phase 1. Nothing else
  changes in this phase, so a regression has one cause.

### Phase 1: `tls:init` / `tls:remove` / `tls:show`
Everything above: render helper, partial, token resolution (both paths),
verification, preflight, commands, tests.

**Tests** (one file per command, `Process::fake` + `Http::fake` for Cloudflare):
- Template: both Traefik templates render the HTTP args when the Secret is
  absent and the DNS args + env when present; YAML parses.
- **Render path:** `cloud:init`/`traefik:setup` re-render keeps the DNS
  challenge when the Secret exists.
- **Token resolution:**
  - one stored `dns:init` token → reuse prompt;
  - several → picker, `--group=` when non-interactive;
  - none → `password()` prompt;
  - none + non-interactive → reads `LARAKUBE_CLOUDFLARE_TOKEN`, errors naming
    it when unset.
- **Verification:** invalid token, zero zones, write-probe failure (read-only
  token) each stop before any `kubectl apply`.
- **Preflight:** an uncovered host refuses and names it.
- **`tls:remove`:** a proxied ingress refuses and names it; it never deletes
  `cloudflare-token-*`.
- The token never appears in any faked command line.

### Phase 2: proxying apps: `cloud:proxy` / `cloud:unproxy` (built)
Users never edit `.larakube.json` for this. `EnvironmentData::$proxied` is set
by the commands and rendered through `ConfigData::getIngressAnnotations()` on
every app Ingress (Laravel web + Reverb, static sites, Next.js, server apps).

`cloud:proxy {env}` refuses unless:
- the cluster renews through the DNS challenge (Traefik ACME environments),
- an ExternalDNS instance on the cluster manages every host's zone (it would
  otherwise reset a manual orange-cloud toggle, or nothing could set it),
- Cloudflare's SSL mode isn't Off/Flexible (Full warns; unreadable warns).

Then it saves the setting, regenerates the manifests and says how it goes
live (commit + push for CI projects, else `cloud:deploy`). `cloud:unproxy`
has no preconditions.

### Original Phase 2 notes
- A real per-environment setting for apps (`cloud:configure --proxied` writing
  a blueprint field), replacing hand-edited `ingressAnnotations`.
- `--proxied` on apps and Cluster Tools warns when the cluster still uses the
  HTTP challenge, since renewal would fail.
- Planned separately once Phase 1 is proven.

## Risks
- **ExternalDNS and `_acme-challenge` records.** ExternalDNS runs
  `--policy=sync` with an ownership registry, so it should ignore TXT records it
  doesn't own. Past deletions of unowned records (SES DKIM) make this worth
  checking explicitly in the walkthrough, not assuming.
- **Rate limits.** Switching doesn't re-issue anything. Only new hosts and
  renewals request certificates.
- **Restart.** Single-replica Traefik with hostPorts means a few seconds of
  downtime on apply.
- **Token rotation.** Rotating the `dns:init` token leaves Traefik's copy
  stale. Re-run `tls:init`; `tls:show` should flag when the copy differs from
  the group it came from.
- **One Cloudflare credential per Traefik.** The Cloudflare DNS provider reads
  the token from process-wide environment variables, so Traefik uses one token.
  A second Cloudflare account's zones can't be covered.

## Open questions
- **Mixed-provider clusters** (Cloudflare and non-Cloudflare zones, or two
  Cloudflare accounts): keep a second HTTP-challenge resolver, or move to
  cert-manager with one issuer per token (the blueprint's `certManagerIssuer`
  field is already there). Deferred until a real cluster needs it.
- **Wildcard certificates** (`*.luchtech.dev`) to cut certificate count: a
  separate follow-up.
