# SSH-over-443 fallback — port-22 egress blocked by a client network

## Status: NOT STARTED — scoped for a fresh agent to implement

## Why this exists

Live incident during a workshop: a workmate stood up a cloud VPS fine, but
every subsequent step that needs SSH (image sideload, server hardening,
`dashboard:trust`'s k3s API-server OIDC config, etc.) failed because the
school network's firewall blocks outbound port 22. This is a known, common
restriction on school/campus and corporate networks — port 22 is a classic
thing to block, port 443 (HTTPS) essentially never is, because it's
indistinguishable from normal browser traffic. GitHub documents exactly this
workaround officially (`ssh.github.com:443`) for the same reason.

Expect this to recur at future workshops on networks LaraKube doesn't
control. The fix needs to be a first-class, repeatable feature — not a
one-off manual recovery — across **both** the CLI and Desktop.

## The decision

Let the operator pick an alternate SSH port (443 is the documented
recommendation, but don't hardcode it — some networks block 443 outbound too
for non-HTTPS-shaped traffic inspection reasons, rarer but real) **at VPS
creation time**, baked into the provider's own first-boot mechanism — so the
alternate port is listening *before* anyone ever needs to SSH in. Never try
to SSH in on 22 first to then reconfigure the port — that's the exact
chicken-and-egg problem this plan exists to avoid.

The data model mostly already supports this: `CloudData::$port` already
defaults to `22` and is already threaded through every SSH-based command via
`$cloud->port ?? 22` (e.g. `app/Commands/Dashboard/DashboardTrustCommand.php`).
**The gap is entirely on the provisioning side**: nothing ever sets `$port`
to anything but 22, and nothing configures the VM to listen anywhere else.

## Part 1 — CLI: provision the alternate port at boot

### Add the flag

`cli/app/Commands/Cloud/CloudProvisionCommand.php` (`cloud:init`, aliased
`cloud:provision`) is the plain-VPS/K3s provisioning entry point. Add:

```
{--ssh-port= : SSH port to configure on the new server (default: 22; use 443 if your network blocks outbound 22)}
```

Per this repo's `RequiresFlagsWhenNonInteractive` convention
(`flagOrPrompt()`), default to `22` without prompting unless the operator
explicitly wants to be asked — this is an edge case, not something to
interrupt the normal flow for. If the chosen port conflicts with something
the VM already needs (443 is also where Traefik's own HTTPS ingress listens
— **these are different listeners on the same VM, not a real conflict**,
sshd and Traefik can both bind 443 on different IPs is NOT true on a single
public IP; sshd would need to bind 443 and Traefik's own 443 ingress would
then collide). **This needs a real design decision, not an assumption** —
either:
  (a) sshd listens on 443 in *addition* to 22 (`Port 22` + `Port 443` both in
      `sshd_config`) and Traefik keeps 443 — doesn't work, same collision, OR
  (b) pick a different, less-likely-blocked-but-not-443 port as the actual
      default recommendation (e.g. `2222`, or literally ask the operator
      which port their network allows and let 443 be an option only when
      Traefik's own ingress is reachable some other way), OR
  (c) sshd owns 443 and Traefik's ingress moves to a different port with the
      cloud provider's load balancer/NAT doing the 443→Traefik mapping
      (more infrastructure, more to get wrong).
  **Read `app/Enums/SharedClusterService.php` and whatever currently binds
  443 on these VMs (Traefik's own manifest, likely in
  `resources/views/k8s/` or a cluster-bootstrap step) before picking an
  approach** — this collision is the single most important thing to resolve
  correctly before writing any provisioning code, and the problem statement
  above undersold it. Don't ship a plan that silently breaks every website
  the cluster serves to fix SSH.

### Wire the port into each provider's boot script

Every provider's OpenTofu template lives at
`cli/resources/views/tofu/{provider}/vps.blade.php`. Current state (checked
directly, not assumed):

- **AWS** (`tofu/aws/vps.blade.php`) already has a `user_data` cloud-init
  heredoc (lines ~161-171) that injects the SSH public key and reloads
  sshd — **this is the template to extend**: add a
  `sed -i 's/^#\?Port .*/Port {{ $sshPort }}/' /etc/ssh/sshd_config`-style
  line (or append `Port {{ $sshPort }}` if not present) before the
  `systemctl reload ssh` line. Also extend the security group (~line 77,
  currently a hardcoded "SSH (Port 22)" ingress rule) to open `$sshPort`
  instead of/alongside 22.
- **DigitalOcean** (`tofu/do/vps.blade.php`), **Hetzner**
  (`tofu/hetzner/vps.blade.php`), **GCP** (`tofu/gcp/vps.blade.php`) have
  **no boot script at all today** — they rely entirely on the provider's
  native `ssh_keys`/`ssh_key` resource attribute (confirmed: DO's template
  does `ssh_keys = [local.ssh_fingerprint]` on the `digitalocean_droplet`
  resource, no `user_data`). These three need a **new** boot-script block
  added, mirroring AWS's:
  - DO/Hetzner: `user_data` attribute (same Terraform resource attribute
    name, cloud-init format, available on both providers' droplet/server
    resources).
  - GCP: `metadata.startup-script` (GCP's equivalent, different attribute
    name and shell-script-not-cloud-init-yaml format — don't copy the AWS
    heredoc verbatim, GCP's startup-script is plain bash).
  Each needs the same two things: open the firewall/security-group rule for
  `$sshPort`, and set `Port {{ $sshPort }}` in `sshd_config` + reload sshd.

### Thread the value through

- `$sshPort` needs to reach each `vps.blade.php` render call — find where
  these templates are currently rendered (likely in `ProvisionsK3sNode.php`
  or a sibling `InteractsWithOpenTofu` trait/service — confirmed
  `ProvisionsK3sNode.php` already builds an `ssh -p {$port}` command at
  line ~133, so it already receives a `$port` variable from somewhere —
  trace that backward to find the one place to inject the new flag's value).
- Once the VM boots with the new port, `CloudProvisionCommand` must persist
  it the same way `captureToolContext()` / `DashboardTrustCommand`-style
  commands already persist `cloud.ip` to `.larakube.local.json` — write
  `environments.{env}.cloud.port` (not 22) so every later command (`cloud:
  configure`, `dashboard:trust`, image sideload, `cloud:harden`, etc.) picks
  it up automatically via the existing `$cloud->port ?? 22` pattern — no
  changes needed in those downstream commands if this one write happens.

### Audit every place that still assumes port 22

Grep-confirmed (not exhaustive — re-run this grep, the list may have grown):
`grep -rln "22" cli/app/Commands/Cloud cli/app/Traits` turned up
`CloudRepairCommand.php`, `CloudHardenCommand.php`, `CloudRestartCommand.php`,
`ResolvesEnvironmentContext.php`, `ManagesSshKeys.php`,
`InteractsWithRemoteDeploy.php`, `ChecksCloudflareProxy.php`,
`ProvisionsDevBox.php`, `ManagesToolFirewallPorts.php`. Each needs checking:
does it already read `$cloud->port ?? 22` (fine, no change needed), or does
it hardcode `22`/`-p 22` literally (needs fixing to read the recorded port)?
This audit is real work, not a formality — do it file by file.

## Part 2 — Desktop: surface and use the same port

Desktop mostly shells out to the CLI binary (`desktop/app/Services/LaraKube/
CliRunner.php`), which already reads `.larakube.local.json`'s recorded
`cloud.port` — so most Desktop flows need **no change** once Part 1 lands,
same as the CLI's own downstream commands.

Three places were found (via `grep -rln "ssh " desktop/app`) where Desktop
constructs or models an SSH connection **directly**, bypassing the CLI, and
each needs to read/accept a port instead of assuming 22:

- `desktop/app/Models/Server.php` — the server record has no `port` column
  today (confirmed: no `port` reference in the model at all). Add one,
  default `22`, nullable-safe fallback for existing rows.
- `desktop/app/Http/Controllers/ServerController.php` — wherever this
  constructs an `ssh ...` command, read `$server->port` instead of a bare
  `22`.
- `desktop/app/Services/LaraKube/DevBoxShell.php` — same; this is the
  interactive dev-box shell, check how it builds its remote command and
  thread the port through.
- `desktop/app/Models/Run.php` — also matched the grep; check what this
  model's SSH usage is for (a background job type?) before assuming it
  needs the same fix — verify first, don't guess.

Whatever UI shows/edits a server's connection details (check
`desktop/app/Http/Controllers/ServerController.php` and the corresponding
Inertia/React page) should let the operator see and, if the CLI-side
provisioning didn't run through Desktop, manually set the port for an
already-existing server — this is the Desktop-side equivalent of the
immediate manual recovery (web console → edit sshd_config → note the new
port) that was used to unblock the workshop incident live.

## Explicit non-goals / out of scope

- **Don't** try to make this work by SSHing in on 22 first — if 22 is
  blocked, that attempt is exactly the failure being fixed.
- **Don't** default to 443 without resolving the Traefik-collision question
  above — that's a correctness bug waiting to ship, not a style choice.
- **Don't** touch the managed-Kubernetes paths (DOKS/EKS/GKE provisioning —
  `CloudProvisionDoksCommand.php`/`CloudProvisionEksCommand.php`/
  `CloudProvisionGkeCommand.php`) in this pass unless they turn out to share
  the same plain-VPS SSH bootstrap path — check first, these may provision
  the control plane entirely through the provider's managed API with no SSH
  involved at all, in which case they're already unaffected by this problem.
- **Don't** build a general multi-port SSH story (port lists, automatic
  fallback probing 22→443→2222) for v1 — one explicit, operator-chosen port
  recorded once at creation time is enough. Fancier fallback logic is a
  later iteration if the simple version proves insufficient.

## Verification

1. `composer format && composer analyse && composer test` in `cli/` after
   the CLI-side changes — this touches OpenTofu templates (no direct test
   coverage likely exists for Terraform HCL output; add a test asserting
   the rendered `vps.blade.php` output contains the expected `Port` line and
   security-group rule for a non-default `--ssh-port=`, matching this
   repo's existing pattern of testing rendered Blade manifests directly via
   `view(...)->render()`).
2. Manual battle-test, per this repo's command-driven-fixes standard: after
   `./build`, provision a real (or disposable) VPS with
   `cloud:init --ssh-port=443` (or whatever port the collision decision
   above lands on), confirm `ssh -p 443` reaches it while `ssh -p 22` times
   out (simulate the blocked-22 network with a local firewall rule if a real
   restricted network isn't available), then run a downstream SSH-dependent
   command (`dashboard:trust`, or an image sideload via `cloud:deploy`) and
   confirm it transparently uses the recorded port with no extra flags.
3. Desktop: create/import a server with a non-22 port recorded, open the
   interactive dev-box shell, confirm it connects on the recorded port.
