# Plan: LaraKube Cloud, first server

**Status:** Decisions settled, ready to build.

**Goal:** a signed-in team adds a Google Cloud service account and its own R2 bucket, fills in a form, watches live output, and ends up with a running, hardened k3s server in its own Google Cloud project.

## Settled decisions

- **Codebase:** `cloud/` (git initialized). The old repos are archived in `archive/cloud-old/`; `larakube-cloud-salvage.md` lists what to port.
- **Execution:** one disposable Kubernetes Job per operation, running the `larakube` CLI.
- **Runner:** its own public repo in `runner/`, publishing a public image. It holds no secrets, the CLI it packages is already public (GPL-3.0), and customer clusters can later pull it with no registry credentials.
- **SSH:** the customer's public key is required. A throwaway key provisions the server and is removed afterwards. Only a public key is accepted; a pasted private key is rejected and never stored.
- **Secrets:** OpenBao, installed locally first, then on staging.
- **First provider and live test:** Google Cloud VPS.
- **Google Cloud connection:** a least-privilege service account JSON key, stored in OpenBao and mounted into the job as a file. "Connect with Google" follows before public launch, because a long-lived key is a weaker credential and newer Google organizations block creating one.
- **Tofu state:** the tested Cloudflare R2 backend, in the **customer's own** bucket, keyed by the server's unique ID. The per-server state passphrase lives in OpenBao, and customers can export it so they can leave with working state.
- **Access after the build:** SSH and the k3s API stay open and key-only.
- **SSH keys belong to users, like GitHub.** Each user manages their own public keys (`user_ssh_keys`), and a fingerprint can belong to only one account. Joining a team grants no server access. Cloud keeps no private key.
- **Server SSH access is an explicit grant (`server_ssh_access`).** A team owner or admin chooses which member's key goes on which server, and the grant records who approved it and when. It stores a copy of the key and fingerprint, so a deleted key or departed user still shows up as access that must be revoked.
- **Removing access is a real operation on the server.** Deleting a key or leaving a team never implies the key is gone. Cloud flags those grants as needing revocation until a revoke Job confirms removal.
- **No agent, and nothing of ours left running on a customer's server.** This overrides the agent design in the zero-trust plan. Cloud acts only when the customer asks it to.
- **Server status and visibility:** the build ends with `cluster:grant --read --cluster --name=larakube-cloud --json`, plus a small extra role for reading nodes and metrics. This creates a Kubernetes ServiceAccount: a permission, with no process running on the server. Its token lives in OpenBao, per server. Cloud reads health, workloads, events and live logs over the k3s API.
  - **Can't:** read Secrets, exec into pods, or change anything.
  - **Logs** are fetched when viewed and never stored by Cloud.
  - **Customer control:** the create form states this access, the server page lists the exact permissions, and **Disconnect** runs the revoke. The server keeps running, and Cloud falls back to VM state from the Google Cloud API.
- **Management access (next milestone):** a separate, explicit upgrade per server, off by default. Actions run as disposable Jobs from Cloud using that grant. It can be turned on at any time, and the same flow restores read access after a Disconnect:
  - Cloud's read token can't upgrade itself, by design, so enabling needs a one-time admin step.
  - **One-click (Google Cloud):** a team owner confirms. A disposable Job creates a throwaway key, adds it to the VM's metadata through the customer's Google credential (keeping existing keys), runs `cluster:grant`, removes the key, and writes an audit entry. Needs a live test.
  - **Fallback (any provider, or a removed credential):** Cloud shows a one-time command the customer runs over their own SSH key.
- **Blast radius of the provider credential:** the Google credential is stronger than the read token. It can change or delete VMs and add SSH keys through metadata, so it effectively has root on the customer's VMs. The UI and privacy copy must say so, it needs the narrowest roles that work, and removing it must be one click. "Connect with Google" doesn't remove this; only a narrower role would.

**Not in this milestone:** billing, Cluster Tools, project deploys, managed Kubernetes, other providers, and `larakube.app` URLs.

## Managed Kubernetes (later milestone, designed for now)

The access model stays the same: a read-only grant by default, management as an explicit upgrade, nothing of ours running in the cluster. The `servers` table carries a `kind` (`vps` or `managed`) from the start.

| | VPS | Managed (GKE, DOKS, EKS) |
|---|---|---|
| Build | Tofu, then SSH to install k3s | Tofu only, no SSH |
| Customer SSH key | Required | Not asked for |
| Read access | `cluster:grant --read --cluster` | The same |
| One-click Enable management | Throwaway key through VM metadata | Short-lived cluster admin through the provider credential, then `cluster:grant` |
| Fallback | One-time command over SSH | One-time command with the customer's own provider CLI |
| Destroy | From Tofu state | From Tofu state |

To solve before managed ships:
1. **Admin credentials in state.** The templates output a `kubeconfig`, so state can hold cluster credentials (DOKS most directly). State is encrypted, but the passphrase export and privacy copy must account for it.
2. **Runner tooling.** GKE needs `gcloud` and `gke-gcloud-auth-plugin`; EKS needs the AWS CLI. Decide between a larger image and a separate managed runner.
3. **EKS lockout.** Our template defines no access entries, so by default only the IAM identity that created the cluster is its admin. If that is Cloud's credential, the customer's own AWS user can't reach the cluster. Add an access entry for the customer.
4. **Private API endpoints.** Clusters we create have public endpoints. A customer who restricts theirs must allow Cloud's outbound IPs, or Cloud loses visibility.
5. **Metrics.** Don't assume `metrics-server` exists on every provider. Health must still render without CPU and memory figures.

## Design and landing page

**Today there are three looks:** Desktop (Geist, red `#d1261b`, paper and surface tokens), `cloud` (stock shadcn black and white), and the docs site (Kubernetes blue `#326CE5`). `cloud-v1` has a fourth, blue-anchored token set and about 2,350 lines of marketing pages.

**Open brand decision: blue or red.** `cloud-v1`'s design system set a written rule: blue-anchored (`#2563EB`), "never Laravel coral". The docs site is also blue. Desktop's red came later, with its Figma restyle. See `larakube-cloud-salvage.md`.

**Decision proposed: Desktop is the source of truth for structure and components; the accent follows the brand decision.**

1. **Tokens first, before any Cloud page is built.** Keep shadcn's components in `cloud` and point its variables at Desktop's light and dark values. Use Geist and Geist Mono. Keep one `tokens.css` with a check that fails when Desktop and Cloud drift. A shared package can wait until a third app needs it.
2. **Same product components.** Port `StatusPill`, `LiveDot`, `ServiceCard`, `ProviderLogo`, `LogPanel` and the Button styling. Align `lucide-react` (Desktop 1.48, Cloud 0.475). Action buttons carry icons, per the repo standard.
3. **Same app shell.** Sidebar, page headers, tables and empty states match Desktop, so moving between them feels like one product. Web-only differences: team switcher, responsive layout, no window chrome.
4. **Landing page after the first real server works,** so it can show real screenshots. Reuse `cloud-v1`'s structure, rewrite its content.

**`cloud-v1` copy that must not be carried over unchecked:**
- A terminal hero showing DigitalOcean, Kubernetes 1.29 and "LaraKube Local CA" for a production deploy.
- `larakube.app` instant URLs, which aren't built.
- Any claim that Cloud can't touch the customer's server. The stored provider credential can.

**Landing message:** manage servers in your own cloud from one dashboard, and leave any time with your server, state and manifests intact. Avoid "nobody else does Kubernetes this simply" (Porter does).

**Later:** bring the docs site onto the same brand.

## Source plans

| Plan | What it is | Use it for |
|---|---|---|
| `plans/active/larakube-cloud.md` | Product plan (July) | Positioning, Cloud/CLI portability, Teams, support boundary |
| `cli/plans/active/larakube-cloud-architecture-and-zero-trust-agent.md` | Security architecture | No stored root credentials, OpenBao custody, the agent |
| `cli/plans/active/larakube-cloud-mvp-execution-plan.md` | Schema and pages | `cloud_credentials`, `servers`, `audit_logs`, page list |
| `cli/plans/completed/cloud-headless-execution.md` | CLI prerequisite | `cloud:create --json`, flags, remote state. Marked as never manually verified |
| `cli/plans/active/cloudflare-r2-tofu-state.md` | R2 state | Proposed; the S3 backend it builds on already exists |
| `plans/active/monetization-strategy.md`, `larakube-cloud-pricing-worksheet.md`, `cli/plans/active/cloud-pricing-tiers-draft.md` | Pricing | Later milestone |
| `cli/plans/active/larakube-mobile-incident-response.md`, `oauth-provider-connect.md` | Adjacent ideas | Later |

## What exists today

**`cloud/` (current):** a fresh Laravel 13 app with Fortify, passkeys, Teams, Filament, Horizon, Reverb and Octane. It runs at `cloud.test`. It has no Cloud domain code yet, and **it is not a git repository**.

**`cloud-v1/`:** the only attempt with a provisioning engine:
- `CloudOperation`, `DispatchCloudOperation`, `JobRunner`, `KubernetesJobRunner`, `FakeJobRunner`, a signed result callback
- `Stack`, `Cluster`, `Entitlement`
- Marketing, pricing and billing pages

It has uncommitted changes, so copy from it and leave it untouched.

**`cloud-v2/`:** a bare skeleton. **`cloud-v3/`:** Teams plus a PayMongo integration, useful for the billing milestone.

**CLI:** `cloud:create` already runs headless with `--json`, takes the DigitalOcean token from `TF_VAR_do_token`, and supports S3-compatible remote state with `LARAKUBE_TOFU_PASSPHRASE`.

## Gaps found while checking the plans

1. **The job image does not exist.** v1 points at `ghcr.io/luchavez-technologies/larakube-runner:latest`, which GitHub reports as not found.
2. **Nothing posts the result back.** v1 expects the container to call `LARAKUBE_WEBHOOK_URL`, and the CLI has no such code. The image entrypoint has to do it.
3. **v1's runner leaks secrets into the Job spec.** It turns the whole payload into both env vars and `--key=value` arguments, so a token would be readable in the Job manifest.
4. **The MVP plan is stale on dependencies.** It says `lorisleiva/laravel-actions` is installed in `cloud`. It is not.
5. **The user keeps no SSH access.** `cloud:create` installs only the key it is given. If Cloud discards that key, nobody can reach the server. It needs a way to add the customer's public key.
6. **A throwaway key stays behind.** The DigitalOcean template registers the provisioning key in the customer's account and nothing removes it.
7. **Provisioning needs inbound SSH from the job.** The "zero inbound" promise can only start once provisioning ends, so the firewall has to close port 22 to the job afterwards.
8. **State names can clash inside one bucket.** The backend key is `tofu-state/<stack>/terraform.tfstate`, so a Cloud server named like an existing CLI stack would share its state. Cloud must key state by the server's unique ID.
9. **`cloud:destroy` can't run from a fresh container.** It stops at the local registry lookup, and has no backend file, provider config or `tofu init`. OpenTofu itself can destroy from state without the original `main.tf` (tested with a built-in resource; the Google provider is proven in the live test).
10. **Google Cloud keeps the throwaway key in VM metadata.** The template sets `ssh-keys = root:<key>`. The cleanup must remove it from metadata and from `authorized_keys`, and the live test must confirm the guest agent doesn't put it back.
11. **Service account keys are blocked by default on Google Cloud organizations created on or after May 3, 2024.** Cloud must detect this and explain it. The CLI's service account path also hasn't been used on a real server yet, so the live test is its first proof.
12. **R2 locking is unproven for `If-None-Match: *`.** R2 documents conditional writes, but the create-if-absent form OpenTofu's `use_lockfile` relies on needs a direct test: two concurrent lock attempts, where the second must fail.

## Where the plans disagree

| Topic | July plan | Zero-trust / MVP plan | Proposed for this milestone |
|---|---|---|---|
| How the server is built | CLI in a disposable Job, over SSH | Provider API plus cloud-init | **CLI in a disposable Job.** One engine, already works |
| SSH key | Generate, use once, discard | Customer supplies a public key | **Both.** Throwaway key to provision, customer's public key stays |
| Kubeconfig | Kept for managed clusters | Never stored | **Never stored** |
| Tofu state | Cloudflare R2 | SeaweedFS plus Postgres advisory lock | **Customer's own R2.** Confirm its conditional writes work with `use_lockfile` first |
| Token storage | Encrypted data store | OpenBao, per-team policy | **OpenBao** (decision 2) |
| Live output | Final result only | Live stream over Reverb | **Live**, by reading the job pod's logs |
| Payments | Lemon Squeezy first | PayMongo | Out of scope |

**Live test:** creates a real VM in your Google Cloud project, which bills until destroyed. It runs only with your go-ahead, on a small machine type, and is destroyed afterwards.

## Build order

### 1. CLI
- `cloud:create --authorized-key=<public key>`: adds the customer's key to the server (gap 5).
- Remove the throwaway key from the server and VM metadata after a successful build (gaps 6 and 10).
- `cloud:create` takes one `--authorized-key` per approved user. Google's guest agent owns those keys through VM metadata, and the throwaway key is removed only after they all arrive.
- `cloud:ssh-keys:sync` sets the exact list of keys on an existing VM. The live test must prove a revoked key can no longer log in.
- `cloud:ssh-keys:sync` changes metadata outside Tofu. Destroy is unaffected, but any future `tofu apply` on a server must render the current key list first or it would restore old keys.
- Apply the final SSH and k3s API access rule when provisioning ends (gap 7).
- Key headless state by an explicit ID (gap 8).
- Make `cloud:destroy` work headless: write the backend and a minimal provider config, run `tofu init`, destroy (gap 9).
- Test R2 locking directly (gap 12).
- Add the node and metrics read role to the Cloud grant, and confirm `cluster:grant`/`cluster:revoke` run headless against the new server.
- Audit `cloud:create` failure paths so no injected token reaches stdout or stderr.

### 2. Runner image
- `larakube` binary, `tofu` (1.10 or newer), `kubectl`, `ssh`, at the latest verified releases.
- The entrypoint runs one CLI verb, then POSTs its JSON result to the signed callback (gap 2).
- Non-root, no shell history, no credentials baked in.

### 3. Cloud: credentials
- `cloud_credentials` holds a pointer only. The raw token goes to the vault once and is never shown again.
- `/credentials` page: add, verify against the Google Cloud API, remove.
- `audit_logs` records who did what, with no secret values.

### 4. Cloud: provisioning engine
- Port `CloudOperation`, the dispatch job, the runner and the callback.
- Credentials go in a per-job Kubernetes Secret, created with the Job and deleted after it (gap 3).
- Allow one running operation per team and server name.
- A scheduled pass marks operations stuck past a time limit as failed.
- Every query is scoped to the request's authenticated team.

### 5. Cloud: UI
- `/servers`: list with status and an empty state that leads to Create.
- `/servers/create`: provider, credential, region and machine type from the live Google Cloud catalog, which members' SSH keys to grant (owner or admin only), review with a link to official pricing where the API gives no price.
- `/servers/{server}`: health, nodes, workloads, events, live unified log, the exact access Cloud holds with **Disconnect**, and how to connect with your own key.
- Destroy, with the server name typed to confirm.

### 6. Verification
- Full test suites in `cli` and `cloud`, with a dedicated test that no secret reaches the database, Job spec or stored logs.
- Live run on a small Google Cloud VM: create, SSH in with the customer's key, confirm k3s is healthy, confirm the throwaway key is gone from the VM and its metadata, destroy, confirm the VM, reserved IP and firewall rules are gone.
