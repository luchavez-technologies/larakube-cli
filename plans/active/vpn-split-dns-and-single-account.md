# Plan: NetBird single-account convergence, then split-DNS for VPN-only hosts

**Status:** Phase 1 partially shipped (env var only — insufficient on its own).
Rewritten 2026-08-28 after live testing on `larakube-159.89.205.239` invalidated
two earlier versions of this plan.

## The goal, stated concretely

A non-technical teammate installs the NetBird app, sets the server to
`https://vpn.luchtech.dev`, logs in through Zitadel SSO, and can then open
`https://admin.chat.luchtech.dev` — with **no `hosts` file editing** on any
platform, iOS included.

## Two independent problems

These were conflated in earlier drafts. They are separate and both must be
solved for the SSO path:

| | Problem | Blocks | Fixed by |
|---|---|---|---|
| **Routing** | SSO peers land in a different NetBird account from the gateway, and accounts are hard tenancy boundaries with their own `/16` | SSO users only | Phase 1 |
| **DNS** | Public DNS resolves VPN-only hosts to the public IP, so Traefik sees an ISP address and 403s | every peer, SSO or setup-key | Phase 2 |

**Setup-key peers only ever hit the DNS problem.** `vpn:grant` mints keys with
the bootstrap PAT, so those peers land in the same account as the gateway and
route to it fine. A cluster with no Zitadel needs Phase 2 only, and needs no
part of Phase 1.

---

## Phase 1 — Converge on exactly one NetBird account

### What is proven, and by what

Everything below was observed live on 2026-08-27/28. It contradicts two earlier
drafts of this plan, so the evidence matters more than the reasoning.

**Single-account mode is load-bearing.** Not optional, not redundant with
domain classification:

| management state | new SSO identity | result |
|---|---|---|
| mode **ON** | iPhone (first login) | created account B, `100.106.0.0/16` |
| mode **ON** | laptop (same user) | **joined** B |
| mode **OFF** | Joanna (new user) | created account C, `100.123.0.0/16`, "No peers available" |

An earlier draft nearly reverted `NETBIRD_MGMT_SINGLE_ACCOUNT_MODE_DOMAIN` on
the theory that private-domain classification was doing the consolidating. The
Joanna login disproves it: **with the mode off, every new SSO user gets their
own account.** Do not remove that env var.

**But the env var alone is not sufficient**, which is why Phase 1 is only
partially shipped. NetBird evaluates, once, at process start:

```go
am.singleAccountMode = singleAccountModeDomain != "" && accountsCounter <= 1
```

So the mode silently switches itself off as soon as a second account exists —
no error, healthy pod, correct-looking spec. Confirmed by restarting management
at two accounts and reading `single account mode disabled, accounts number 2`.

**The bootstrap account is the second account.** `vpn:init` POSTs `/api/setup`
to create a local owner non-interactively (this *is* NetBird's documented
modern flow — built-in auth first, external OIDC added after — not a LaraKube
deviation). That account is created with `domain: ""`, and:

- `/api/setup` accepts only `Email`, `Password`, `Name`, `CreatePat`,
  `PatExpireIn` — no domain field, and the handler never sets
  `Domain`/`DomainCategory`.
- `PUT /api/accounts/{id}` accepts only `settings` and `onboarding` — `domain`
  and `domain_category` are response-only.

So the bootstrap account can never own the SSO domain, and can never absorb SSO
users. Its existence is what pushes `accountsCounter` past 1.

**Email-based linking will not bridge them.** NetBird matches an OIDC login to
an existing user by IdP subject only. `lookupUserInCacheByEmail()` exists but is
never invoked during authentication, so inviting a user by email into the
bootstrap account does not make their SSO login land there.

### Rejected: one gateway per account

Considered and dead. Traefik would accept it — its allow-list already covers
`100.64.0.0/10`, and it sees the gateway pod's IP anyway — so the *app* is
reachable from any account. But without consolidation each SSO user spawns an
account, so it would need a gateway per person; and with consolidation there is
only one SSO account, so a second gateway has nothing to serve. It fails in
both branches.

### Target end state

**Exactly one NetBird account, and it must be the SSO-owned one**, with the
gateway peer keyed into it. Then `accountsCounter` stays at 1, single-account
mode survives restarts, and every SSO login lands alongside the gateway.

### The irreducible human step

Minting a setup key inside the SSO-owned account requires a credential in that
account, and there is no automatic way to get one:

- The NetBird OIDC app permits only `OIDC_GRANT_TYPE_AUTHORIZATION_CODE` and
  `OIDC_GRANT_TYPE_REFRESH_TOKEN` — no password grant, no client credentials.
- A Zitadel *machine* user can obtain a project-audience token but carries no
  email claim, so it would land domain-less — the same trap as the bootstrap
  account.
- Enabling ROPC would work but means a stored service password and a grant type
  dropped in OAuth 2.1.

So: **one dashboard action, once per cluster, by the person configuring SSO.**
That is acceptable under the "remove decisions from the CLI user" rule — it is
not a recurring choice, and a missing credential fails loudly and immediately
rather than producing the invisible account split this plan exists to fix.

### Migration on a cluster that has already drifted

Strict ordering. Deleting the bootstrap account first invalidates the gateway's
current setup key and strands it.

1. In the dashboard, logged in via SSO, create a setup key in the account being
   kept.
2. Swap it into `vpn-secrets` and clear `netbird-client`'s identity volume
   (`netbird-client-data`) so it re-enrols — changing `NB_SETUP_KEY` alone does
   nothing, the daemon keeps the identity in its existing `/etc/netbird` config.
3. Delete the surplus accounts via `DELETE /api/accounts/{id}` (owners only —
   the bootstrap PAT owns the bootstrap account; a user-created account must be
   deleted by its own owner).
4. Restart management and confirm `single account mode enabled, accounts number 1`.
5. Surplus-account users log in again and land in the surviving account.

Per the repo's no-one-time-migration-code rule, this is a documented manual
runbook — do not build drift detection for it.

### The SSO decision belongs at `vpn:init` time

Whether a cluster uses SSO for VPN should be declared when the VPN is first
stood up, before any peer exists. `vpn:init` can then converge to one account
immediately rather than unpicking it later.

Running `sso:wire vpn` on a cluster that already has setup-key peers is the
disruptive path: every existing peer is re-enrolled into a different account.
It must warn and confirm before proceeding, and say plainly that existing users
will be stranded until they either move to SSO or receive a new setup key.

### Phase 1 verification

- [ ] Management logs `single account mode enabled, accounts number 1` at
      startup — **not** `disabled, accounts number N`. This is the gate. The env
      var being present in the pod spec proves nothing.
- [ ] The gateway peer's IP is inside the same `/16` as SSO users.
- [ ] A brand-new SSO identity (someone who has never opened NetBird — an
      existing user re-logging in matches by subject and proves nothing) joins
      the same account and sees the gateway under Peers.
- [ ] The above still holds after a management restart.

---

## Phase 2 — Split-DNS for VPN-only hosts

Unchanged from the previous draft, and **needed by setup-key clusters too** —
it is the only part of this plan a non-SSO cluster requires.

### Scope: exact hosts, never a wildcard

An earlier draft proposed `domains: ["<cluster-domain>"]`. **That would break
production.** Verified 2026-08-27:

```
luchtech.dev        → 104.21.41.3, 172.67.141.16   Cloudflare Pages
docs.luchtech.dev   → 104.21.41.3, 172.67.141.16   Cloudflare Pages
cluster             → 159.89.205.239
```

A wildcard resolves the apex and docs site to the gateway; Traefik has no route
for them and 404s. Every VPN user silently loses both. It would also push all 21
cluster hostnames through one socat pod on a capacity-tight 4-core VPS —
including `sso`, so a VPN hiccup would take Zitadel with it.

Use exact match domains. Today that list is one entry:
`admin.chat.luchtech.dev`, the only host carrying
`larakube-shared-chat-vpn-only@kubernetescrd`.

### A. Resolver container on `netbird-client`

`resources/views/k8s/vpn/client.blade.php` runs two containers (`client`,
`ingress-proxy`). Add a third: CoreDNS or dnsmasq on `:53` serving an explicit
host list mapping each VPN-only host to the client's own NetBird IP, and
**forwarding everything else upstream** — without that default it becomes an
authoritative black hole. The list is generated from the tool registry, not
hand-written.

### B. Nameserver group registration

New Saloon requests alongside the existing nine in
`app/Http/Integrations/Netbird/Requests/`: `ListGroupsRequest` (to resolve the
`All` group ID), `ListNameserverGroupsRequest` (idempotent reconcile), and
create/update/delete.

```json
{
  "name": "Cluster Internal DNS",
  "nameservers": [{ "ip": "<netbird-client-ip>", "ns_type": "udp", "port": 53 }],
  "enabled": true,
  "groups": ["<ALL_GROUP_ID>"],
  "primary": false,
  "domains": ["admin.chat.luchtech.dev"],
  "search_domains_enabled": false
}
```

`primary: false` is correct for match-domain use. `search_domains_enabled` must
be **false** — with exact hosts, search-domain appending only creates surprises.

### C. Command surface

`vpn:wire <tool>` already means "restrict this tool's ingress to VPN peers", so
it is exactly the moment a host needs a DNS override:

- **`vpn:init`** — deploy the resolver, create/reconcile the nameserver group.
- **`vpn:wire <tool>`** — add the host to the resolver list and the group.
- **`vpn:unwire <tool>`** — remove it.

Cloudflare-hosted names never enter the list, and `vpn:unwire` gets a real
inverse instead of orphaning DNS.

### Two traps to build in, not discover

**Discover the client IP at runtime.** It persists only via the
`netbird-client-data` PVC; lose that (or `vpn:remove --purge`) and the peer
rejoins on a different IP, silently breaking the nameserver group. Confirmed
live — the gateway moved from `100.70.57.180` to `100.113.100.204` across one
rebuild. `vpn:init` should re-read and reconcile every run.

**Never key anything off the peer FQDN.** It is
`netbird-client-<pod-hash>.netbird.selfhosted` and changes on every redeploy
even when the IP does not.

### Phase 2 verification

- [ ] `netbird status` on a peer shows `Nameservers: 1/1 Available` (currently `0/0`).
- [ ] macOS and Windows resolve the host with no `hosts` edit; the page loads
      over TLS with a valid certificate.
- [ ] **iOS specifically** — mobile match-domain handling must be tested, not assumed.
- [ ] `docs.luchtech.dev` and `luchtech.dev` still resolve to Cloudflare while
      connected. This is the regression that matters most.
- [ ] `vpn:unwire` removes the host from both resolver and group.
- [ ] `./php vendor/bin/pint` and `./php vendor/bin/pest` pass.

---

## Confirmed — do not re-investigate

- **NetBird is pinned at `0.77.1`, which is current latest** (2026-08-21). Not a
  version problem.
- **The socat path already works.** `chat-vpn-only`'s `sourceRange` includes the
  pod CIDR `10.42.0.0/16`, so traffic forwarded from `netbird-client` passes the
  allow-list; socat is L4 so SNI survives and Traefik serves the real
  certificate. Proven by the `hosts`-file workaround succeeding.
- **The allow-list is not the problem.** It already permits `100.64.0.0/10`,
  which covers every account's range. The failure is routing, not filtering.
- **Exactly one host is VPN-gated:** `admin.chat.luchtech.dev`.
- **Permissions/roles are not involved** anywhere in this plan.
- **The non-interactive bootstrap is standard NetBird**, not a LaraKube
  deviation — built-in auth first, external OIDC added afterwards.

## Known alternatives, deliberately not taken

- **Drop `--vpn-only` from Element Admin.** It already sits behind MAS OAuth2
  authentication, so the middleware is defense-in-depth rather than the only
  gate. Removing it makes the whole problem disappear for that host at the cost
  of exposing an admin console's login page publicly. Available if the SSO
  ergonomics stop being worth the machinery.
- **Use setup keys instead of SSO for VPN.** `vpn:grant` works today with no
  account split; with Phase 2 it delivers the original goal — install, open the
  URL, it works — for the price of pasting a key instead of clicking through
  Zitadel. This is the supported path for clusters without Zitadel and remains
  the fallback for any cluster.

## Split out of this plan

Synapse/MAS admin synchronization moved to `plans/active/chat-mas-admin-sync.md`.
It shares a symptom ("I can't get into Element Admin") but nothing else.
