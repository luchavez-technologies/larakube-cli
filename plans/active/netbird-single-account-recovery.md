# NetBird single-account recovery (and the store-engine question)

**Status:** not started. Manual runbook — deliberately not a CLI command.
**Cluster:** `larakube-159.89.205.239`, namespace `larakube-vpn`.

## Why this is manual

Verified 2026-08-28 against the live cluster and NetBird 0.77.1:

- `GET /api/accounts` returns **only the caller's own account** (1 of 4 here). There is no
  API that can see, let alone delete, another account.
- `netbird-mgmt admin` offers only `mfa`, `proxy`, `token`, `user`. No account management.
- The store is **SQLite** at `/var/lib/netbird/store.db` on the `netbird-management-storage`
  PVC (2Gi, `local-path`). `sqlite3` is not in the management image.
- Upstream documents that **the SQLite store has no cascading deletes**: "related entries were
  not automatically removed when their parent entries were deleted". So a surgical
  `DELETE FROM accounts WHERE id NOT IN (...)` leaves orphaned peers, groups, policies,
  setup keys and tokens behind — silently.

That last point rules out row surgery. But none of it is needed: **`larakube vpn:remove`
deletes the whole `larakube-vpn` namespace**, and both PVs are `Delete` reclaim policy, so
the store is genuinely destroyed rather than merely unbound. `vpn-secrets` goes with it, so
`bootstrapVpnAuth()` re-runs by itself on the next `vpn:init`.

TLS survives: the Ingress uses Traefik's own `letsencrypt` certresolver, not cert-manager,
so the certificate lives in Traefik's `acme.json` outside the namespace. No re-issuance, no
Let's Encrypt rate-limit exposure.

## What single-account mode actually guarantees

```go
am.singleAccountMode = singleAccountModeDomain != "" && accountsCounter <= 1
```

Re-evaluated at **every process start**, so a restart or database outage cannot lose it.
When on, `updateUserAuthWithSingleMode` overwrites each login's domain claim with the
configured domain, so *every* SSO user — including one on a non-matching email domain —
lands in the one account. The count therefore cannot grow while it is on.

The only way to trip it is a login during a window where it was already off. That is
one-way: nothing lowers the count again. Our four accounts are debris from before
`NETBIRD_MGMT_SINGLE_ACCOUNT_MODE_DOMAIN` was templated, not ongoing drift.

`vpnSsoDomain()` cannot return empty (falls back to the base domain of `--domain`), so a
fresh install goes 0 → 1 accounts and stays enabled permanently. **Prevention is already
shipped; this runbook only cleans up the existing cluster.**

## Do it together with the Postgres move

NetBird's documented SQLite → Postgres migration needs `pgloader` plus manual SQL to repair
exactly the orphaned rows the missing cascades left behind. **If we are wiping anyway, that
whole step disappears**: a fresh empty Postgres store is 0 accounts, which means
single-account mode comes up enabled on first boot.

Wiping and migrating are the same outage. Do them once.

Env var for the multi-container layout we run:

```
NETBIRD_STORE_ENGINE_POSTGRES_DSN=host=<h> user=<u> password=<p> dbname=<d> port=5432
```

## What is lost

Confirmed live before writing this: **2 peers** (in-cluster gateway + one iPhone),
**1 user**, **1 setup key** (no expiry), **1 PAT** (expires 2027-08-28). Nothing else.
Re-enrolment is `vpn:setup-key` for the gateway and one SSO login for the phone — which,
with the mode back on, lands in the correct account automatically.

## Steps

```
larakube vpn:remove <env> --context=<ctx>
larakube vpn:init   <env> --context=<ctx>
larakube sso:wire   vpn <env> --context=<ctx>
```

Then sign in on the phone. No `vpn:setup-key` — a fresh `vpn:init` mints its own PAT and
setup key, and `netbird-client` enrols into the one account automatically.

Build the CLI first: `vpn:init` now reports the single-account state, creates the
`larakube-cli` service user that owns the PAT, and creates `larakube-routers` /
`larakube-people` so the gateway is grouped at enrolment.

Verify afterwards:

- management log reads `single account mode enabled, accounts number 0` (then `1`)
- `larakube vpn:show` prints no single-account warning
- the PAT in `vpn-secrets` belongs to `larakube-cli`, not a human
- the gateway peer is in `larakube-routers`
- the phone gets an address in the **same** `/16` as the gateway

`chat-vpn-only` in `larakube-shared` survives the removal — different namespace. It
allowlists the static `100.64.0.0/10`, so chat admin is unreachable during the window and
recovers on its own.

## Open items this exposes

- **`ClusterTool::VPN` declares `backupVolume: false`.** The entire VPN control plane —
  accounts, peers, groups, policies, setup keys, PATs — is on a `local-path` PVC that the
  nightly backup does not touch. Losing that node loses the mesh. This is the same class of
  failure as the Grafana-SQLite incident, and it is a one-line fix.
- **`vpn:wire` allowlists `100.64.0.0/10`**, the whole NetBird range, not one account's
  `/16`. Once there is exactly one account this is academic; while there are four it means
  the middleware does not enforce the account boundary. Only topology does.

## Resolution: `vpn:sso-login` (2026-08-30)

The recovery above gets to zero accounts. What it could not do is create the *right* first
account, and that turned out to be the whole problem.

A NetBird account is only shareable by SSO users if it carries an email domain, and the
domain is taken from the claims of the JWT that created it. There are three ways an account
can come into existence, and two of them were dead ends:

| Route | Domain | Why |
| --- | --- | --- |
| `POST /api/setup` (what `vpn:init` used) | none | The bootstrap endpoint sets no domain, and single-account mode then copies that emptiness onto every later login — each SSO user gets their own isolated account. |
| Dashboard sign-in with zero accounts | n/a | The dashboard hard-gates on its first-run wizard before making any authenticated call. Verified live 2026-08-29: the Zitadel login completed and management logged *nothing at all*. |
| First API call bearing an IdP JWT | derived | The one that works. |

So the CLI makes that call. `vpn:sso-login`:

1. starts a device-code grant against the embedded Dex (`/oauth2/device/code`, client
   `netbird-dashboard` — a static public client, no secret), prints the verification URL and
   user code, and polls `/oauth2/token`;
2. calls `GET /api/users` with the resulting JWT — this is what NetBird creates the account
   from;
3. asserts the new account has a non-empty domain, and **fails loudly if it does not** rather
   than reporting success into the fragmenting state;
4. mints a PAT as that user and stores it as both `pat` and `owner-pat` (account deletion is
   owner-only, so the owner credential has to be kept).

The sequence for a clean cluster is therefore:

```
larakube vpn:remove <env> --purge
larakube vpn:init <env>
larakube sso:wire <env>        # retires the domain-less bootstrap account
larakube vpn:sso-login <env>   # creates the shared, domained account
larakube vpn:init <env>        # service user, groups, gateway key against it
```

A PAT cannot substitute for the JWT at step 2 — a PAT belongs to an account that must
already exist, so it can never bring one into being. That is asserted in
`tests/Feature/VpnSsoLoginCommandTest.php`.

## Correction: the env var never did anything (2026-08-30)

The first end-to-end run succeeded and still produced a broken account. Verified live:

```
$ cat /proc/1/cmdline            # in vpn-management
/go/bin/netbird-mgmt management --log-file console
$ netbird-mgmt management --help
--single-account-mode-domain string   (default "netbird.selfhosted")
```

`netbird-mgmt` reads **no** `NETBIRD_MGMT_*` environment variables. Those names belong to
upstream's docker-compose template, which uses them to render exactly this command line. We
set the env var and never passed the flag, so the binary used its own default throughout:

| | |
| --- | --- |
| Deployment env | `NETBIRD_MGMT_SINGLE_ACCOUNT_MODE_DOMAIN=luchtech.dev` |
| Actual flag | *(absent)* → `netbird.selfhosted` |
| `accounts.domain` | `netbird.selfhosted` |

It fails silently in both directions: management logs `single account mode enabled` (true —
just with the wrong domain), and the Deployment reads correctly to anyone inspecting it.

**Why it matters:** a new SSO user's claim is rewritten to the configured domain, then
NetBird looks for an account whose private domain matches. `luchtech.dev` never matches an
account stamped `netbird.selfhosted`, so the second person to sign in gets their own account
and their own `/16` — the exact fragmentation single-account mode exists to prevent.

Fixed by passing it as `args` (which replaces the image CMD, so `management --log-file
console` is restated), dropping the env var, and tightening `vpn:sso-login` to assert the
account domain **equals** the cluster's SSO domain rather than merely being non-empty. The
non-empty check is what let this through.

Still on the default and deliberately untouched: `--dns-domain` (`netbird.selfhosted`), the
suffix appended to peer names. Changing it to a real domain collides with public DNS.

## Correction: the gateway setup key was never minted (2026-08-30)

Same run: `vpn:init` printed `Deploying NetBird Client ✓` while the DB held **0 setup keys
and 0 peers**, and the client logged
`PermissionDenied: no peer auth method provided, please use a setup key`.

Key minting lived inside the `/api/setup` bootstrap block, so it only ever ran on a
first-ever `vpn:init`. Once `vpn:sso-login` began creating the account, bootstrap
short-circuits on every subsequent run and nothing minted a key —
`ensureVpnServiceIdentity()` had been lifted out of that gate, but the key had not. The tick
was a rollout check, which a client that cannot enrol still passes.

Fixed by `ensureVpnGatewayKey()`, called from the always-runs path: it lists setup keys,
returns if any is valid and unrevoked, otherwise mints one into the routers group, patches
the Secret, and restarts the client (which only reads `NB_SETUP_KEY` at startup).

**Follow-up on the flag fix:** the image ENTRYPOINT is `/go/bin/netbird-mgmt management`,
not `/go/bin/netbird-mgmt` — so `args` must restate only the CMD's `--log-file console`, not
the subcommand. Restating it produced `management management` on the live command line;
cobra ignored the stray positional and the domain still applied, which is precisely why it
would have gone unnoticed until an upgrade tightened arg validation.
