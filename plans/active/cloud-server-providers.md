# LaraKube Cloud: create servers on DigitalOcean, Hetzner, AWS, or a server you already have

**Status:** Planned, not started (2026-11-28). Desktop already does all of this through the LaraKube CLI; Cloud only does Google Cloud.

## Why
Cloud can create a server only in Google Cloud. Desktop creates them on DigitalOcean, Hetzner, AWS and Google, and provisions any existing VPS over SSH. Cloud's users should get the same choice, in Cloud's language: non-developers first, no provider jargon on the main path (see the product-audiences rule).

## How Desktop does it (the mechanism to reuse)
- **Catalog:** `larakube cloud:providers [--provider=x] --json`, backed by `App\Enums\CloudProvider`: `regions()`, `vpsSizes()`, `defaultRegion()`, `defaultVpsSize()`, `pricingUrl()`. Live discovery for DO/Hetzner, curated lists otherwise.
- **Create:** `larakube cloud:create --provider=do|hetzner|aws|gcp --vps --stack-name --region --size [--zone] --json`, with the provider's credential passed as secret environment, never as an argument (`--do-token`, `--hetzner-token`, `--aws-*` exist but would show in a Job spec).
- **Existing server:** `larakube cloud:init --ip --ssh-user --ssh-key [--ssh-port] --stack-name --json`.
- Desktop UI pieces worth copying: `ProviderLogo`, `AwsPolicyHelper` (the minimum IAM policy), the token fields in `cloud-accounts-modal.tsx`, and `servers/create.tsx`'s provider → region → size flow.

## How Cloud does Google today
`app/Services/Provisioning/OperationLauncher.php` builds one runner Job per operation. Everything below is Google-only and must become provider-aware:
- it requires a Google `CloudCredential` and passes `--provider=gcp --gcp-project [--gcp-credentials]`;
- it passes `--zone`;
- it passes `--authorized-key` for the team's approved SSH keys;
- it passes the installer trust: `--cloud-installer-issuer=https://accounts.google.com`, the per-server `--cloud-installer-audience`, and `--cloud-installer-subject` = the team's Google service account ID;
- `ServerCatalog` has a single `GoogleServerCatalog`;
- `StoreServerRequest` validates against Google regions, zones and machine types;
- the runner's `entrypoint.sh` allows `cloud:create`, `cloud:destroy`, `cloud:ssh-keys:sync`, `cloud:access:*` and `cloud:apps:*`, but not `cloud:init`.

## Gaps found (must be solved first)
1. **SSH hand-over is Google-only in the LaraKube CLI.** `CloudCreateCommand::resolveAuthorizedKeys()` refuses `--authorized-key` for other providers, and `handOverSshAccess()` renders `tofu.gcp.vps`. `cloud:ssh-keys:sync` only knows Google (it edits instance metadata). Without these, Cloud can't give the team SSH access or revoke it on a DO/Hetzner/AWS server.
2. **AWS credentials clash with the state storage.** The runner passes the R2 state storage's keys as `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` (see `InteractsWithOpenTofu::tofuEnv()`), the same names AWS itself reads. An AWS server would be created with the wrong keys.
3. **Installer trust has no subject outside Google.** Every Cloud-made server trusts Google-issued tokens for the team's own Google service account. A DO/Hetzner/AWS team may have no Google connection at all.
4. **No `cloud:init` in the runner**, so no "server you already have".

## Decisions (record as ADR 0043 before building)
- **Installer trust for non-Google servers:** issuer `https://accounts.google.com`, subject = **Cloud's own platform service account** for that environment (`larakube-cloud-<env>@…`, already used for impersonation), audience unique per server as today. Tokens are minted by Cloud, last an hour and only work for one server. The server still runs without Cloud (works-without-Cloud rule); the trust can be removed with `cloud:access:disconnect`.
- **SSH key changes after creation, for every provider:** the LaraKube CLI's `cloud:ssh-keys:sync` gains a provider-agnostic mode that writes `authorized_keys` through the cluster: a short Job pinned to the node, with a host mount limited to the user's `.ssh` directory, using the installer kubeconfig. Google keeps using metadata. **Needs the user's sign-off: it is a privileged pod.** The alternative, keys fixed at creation and changed only by rebuilding, breaks revocation, which Cloud already promises.
- **Credentials per provider**, stored in OpenBao under `VaultPath::team(…)`, like Google keys:
  - DigitalOcean: an API token (OAuth later, see `oauth-provider-connect.md`);
  - Hetzner: an API token;
  - AWS: an access key and secret, limited by the minimum policy (`aws-minimum-access-policy.md`).

  Each is verified once on save: DO `GET /v2/account`, Hetzner `GET /v1/server_types?per_page=1`, AWS STS `GetCallerIdentity`.
- **"A server you already have"** sits under Advanced. Cloud generates a key pair per attempt and shows a one-line command that adds the public key to the server; the private key lives only in that operation's Job Secret.

## Phases

### 1. LaraKube CLI (do first; Desktop gains from it too)
- **SSH hand-over for DO, Hetzner and AWS:** `--authorized-key` on their `tofu/*/vps.blade.php` (cloud-init / `user_data`), and `handOverSshAccess()` per provider. Drop the Google-only guard.
- **`cloud:ssh-keys:sync --kubeconfig=… --authorized-key=…`:** the in-cluster mode above, for any provider.
- **Separate state-storage keys:** `LARAKUBE_TOFU_STATE_ACCESS_KEY_ID` and `LARAKUBE_TOFU_STATE_SECRET_ACCESS_KEY`, written into the S3 backend block instead of inherited `AWS_*`. Keep the old names as a fallback when the new ones are unset, so Desktop is unchanged.
- **`cloud:init`** accepts `--authorized-key`, the `--cloud-installer-*` flags and `--dns-provider`/`--dns-tls-group`, like `cloud:create`.
- Tests for each provider's rendered HCL and for argument validation (ADR 0019 conventions).

### 2. Runner
- Allow `cloud:init` in `entrypoint.sh`. Read access after `cloud:init`, the same as after `cloud:create`.
- Rebuild the runner image once phase 1 is in a CLI release.

### 3. Cloud: credentials
- `CloudCredential.provider` already exists; add the `do`, `hetzner` and `aws` kinds, their forms, verification, and storage.
- The Credentials page becomes "Cloud accounts", with one card per provider: logo, a one-line plain explanation, "Connect", and a short "where do I find this?" guide with a link to the provider's token page. Google keeps "Connect with Google" first.

### 4. Cloud: catalog
- `ServerCatalog` per provider:
  - **DigitalOcean and Hetzner:** live, through Saloon connectors with the team's token, filtered and priced like `CloudProvider::vpsSizes()` (the cheapest sensible sizes, with prices).
  - **AWS:** the CLI's curated regions and sizes, checked against `DescribeInstanceTypeOfferings` when possible.
- Cache it the way `GoogleServerCatalog` does.

### 5. Cloud: create and destroy
- `OperationLauncher::inputs()` maps provider to arguments and environment:
  - `do` → `TF_VAR_do_token`;
  - `hetzner` → `HCLOUD_TOKEN`;
  - `aws` → `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and `--aws-region`, with state keys under the new names;
  - `--zone` for Google only;
  - the installer subject per the decision above;
  - destroy follows the same map.
- `StoreServerRequest` validates against the chosen provider's catalog.
- Servers record `provider`.
- **"A server you already have":** IP, SSH user and port; the one-line key command; then `cloud:init`.

### 6. Cloud: the create-server page
- Step 1 asks where it runs, with a card per connected provider: logo, "From $X/month", one line. A provider that isn't connected offers "Connect" inline.
- Step 2 is location and size in plain words, e.g. "Singapore", "2 CPUs · 4 GB", with the monthly price where the provider gives one, else a link to its pricing page (no invented prices).
- "A server you already have" sits under Advanced.
- Phone-width checked.

### 7. Verification
- Unit and feature tests per phase; full suites in the CLI, runner, Cloud and Desktop.
- Live, one provider at a time, on the user's own accounts and only with their go-ahead (real money):
  1. create the smallest server;
  2. check SSH access with a team key, then revoke it;
  3. install one app;
  4. destroy, and confirm nothing is left in the provider account.

## Open questions for the user
1. Approve the in-cluster SSH key sync (privileged, node-pinned Job), or accept keys fixed at creation for non-Google servers?
2. Which provider to ship first? Suggestion: DigitalOcean (simplest API, OAuth possible later).
3. Managed Kubernetes (DOKS, EKS) in Cloud too, or VPS only for now? Suggestion: VPS only.
