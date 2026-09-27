# `cloud:shield` — restrict the origin's 80/443 to Cloudflare

## Context

Orange-clouding a host hides the origin IP, absorbs DDoS and puts the WAF in
front of it. None of that stops someone who already knows the IP from
connecting to it directly and bypassing Cloudflare entirely — and the IP is
discoverable from DNS history, old records, mail headers, and any host that has
to stay grey.

The protection that actually closes it is refusing 80/443 at the origin to
everything except Cloudflare's own ranges. Today that is impossible, and
deliberately so — `InteractsWithServerHardening` says as much:

> `6443 (k3s API) is admin-restrictable alongside SSH when given; 80/443 are
> never restricted here — they're the public web ports.`

Only `6443` and the SSH port take an `--admin-cidr`.

### What already exists

| | |
|---|---|
| `tls:init` | Moves Traefik's Let's Encrypt to the Cloudflare DNS-01 challenge, so a proxied host can still renew. Also sets Traefik's `trustedIps` from `cloudflareTrustedRanges()`, which is what keeps real client IPs — and therefore VPN-only whitelists — working behind the proxy. |
| `cloud:proxy` / `cloud:unproxy` | Orange-clouds an environment's **project app** hosts in bulk. Requires a LaraKube CLI project; gated by `ChecksCloudflareProxy::proxyChecksPass()`. |
| `tool:proxy` / `tool:unproxy` | Orange-clouds **one Cluster Tool host**, annotating its Ingresses and recording the choice in the registry so `{tool}:init` preserves it. |
| `cloud:harden` | UFW, fail2ban, key-only SSH. Idempotent, safe to re-run. Leaves 80/443 open. |

So proxying is solved for both apps and tools. Closing the bypass is not.

## Why this is not a flag on `cloud:harden`

**The dependency runs the wrong way.** `cloud:harden` is server-level, reaches
the box over SSH, and is built to run on a freshly provisioned server before the
cluster serves anything. Whether restricting 80/443 is *safe* depends on whether
every web host resolving to that IP is already proxied — a cluster-and-Cloudflare
fact. Harden would have to learn to query both just to know whether it is about
to break the web tier.

**They have different failure shapes.** Harden's value is that it is always safe
to re-run. This operation is only *conditionally* safe: correct today, wrong the
moment someone runs a `{tool}:init` for a new host or a `tool:unproxy`. Folding a
conditionally-safe step into the always-safe command damages the one already
trusted.

**Cloudflare's ranges drift.** A rule written once goes stale silently; when
Cloudflare adds a range, some edge POPs start being refused and the result is a
partial outage that looks geographically random. That is a refresh lifecycle, not
a hardening step.

`cloud:harden` should instead **name the next step** — one line at the end saying
80/443 are open to the internet and `cloud:shield` restricts them. That buys the
discoverability of a merge with none of the coupling.

## The commands

Two, matching the `cloud:proxy` / `cloud:unproxy` precedent rather than hiding a
reversal behind a flag:

```
larakube cloud:shield {environment?}
    --admin-cidr=   Also keep 80/443 reachable from here (an office, a bastion)
    --force         Skip the confirmation
    --dry-run       Print the rules that would be applied, change nothing

larakube cloud:unshield {environment?}
    --force
```

One positional, and it is the environment.

## The preflight is the feature

Applying the rules is a dozen lines of UFW. Everything that matters is deciding
whether it is safe, and that has to be decided from live state, never from
configuration:

1. **Enumerate every Ingress host on the cluster.** `clusterIngresses()` +
   `letsEncryptHosts()` already do this for `tls:init`.
2. **Ask Cloudflare the proxy status of each.** `ChecksCloudflareProxy` has the
   shape; the DNS record's `proxied` field is the truth, not the Ingress
   annotation, because ExternalDNS may not have reconciled yet.
3. **Resolve each host and compare to this origin's IP.** A host on another
   origin is not this command's business.
4. **Refuse, naming them, if any host resolving here is still grey**, and point
   at `tool:proxy` / `cloud:proxy` per host. This is the whole safety story.
5. **Confirm the DNS challenge is active.** Shielding a cluster still on HTTP-01
   guarantees certificate expiry roughly sixty days later, silently. Refuse and
   point at `tls:init`.

### What is *not* a blocker

Worth stating plainly, because it makes this far more achievable than it sounds:
**restricting 80/443 only affects HTTP.** A host that must stay grey for a
non-HTTP reason is unaffected —

- `send.luchtech.dev` — SMTP is 25/465/587
- coturn — UDP/3478 and TLS/5349
- LiveKit media — its own hostPorts

Only a **web** host that must stay grey can block a shield, and with `tls:init`
setting Traefik's `trustedIps`, even VPN-only hosts keep their IP whitelists
working while proxied. There may be no blockers at all.

## Ranges, and keeping them current

Fetch from `https://www.cloudflare.com/ips-v4` and `.../ips-v6` at run time.
Never vendor them — a stale allowlist is the failure mode this section exists to
avoid.

Record the fetched set in the cluster (a small ConfigMap beside the other
LaraKube state) so a later run can diff it. Then:

- **`cloud:shield` is re-runnable** and reconciles to the current set. That is
  the refresh mechanism.
- **`doctor` warns** when the recorded set differs from what Cloudflare now
  publishes, so drift surfaces before it becomes a partial outage.

**A node-side timer was considered and rejected.** It would mean a systemd unit
on the host mutating UFW without the CLI knowing, which contradicts the
command-driven workflow and leaves firewall state nothing can explain. An
explicit re-run plus a `doctor` warning keeps every rule traceable to a command
someone ran.

## Lockout analysis

Less alarming than it first appears, and worth writing down so the
implementation does not over-engineer around a risk that is not there:

- **SSH is unaffected.** Harden manages the SSH port separately, and
  `preferredSshIp()` may route it over the VPN overlay. Nothing here touches it.
- **The k3s API is unaffected** — 6443 has its own rule.
- **So the blast radius is the web tier, not admin access.** Worst case every
  tool becomes unreachable from browsers while SSH still works, and
  `cloud:unshield` restores the blanket allows.

The one genuine trap: an operator whose *only* route in is an admin CIDR that
this command narrows. Hence `--admin-cidr` here too, and hence `--dry-run`
printing the exact rule set first.

## Implementation notes

- `hardenServerScript()` builds the allows from `$publicPorts`, which currently
  always contains 80/443 unconditionally. Shielding has to remove those blanket
  rules first — the same `ufw --force delete allow …/tcp || true` dance the
  `--admin-cidr` path already performs, and for the same reason: UFW permits a
  connection if *any* rule matches, so a scoped rule added beside an open one
  does nothing.
- Emit `ufw allow from <range> to any port 80,443 proto tcp` per range. IPv6
  ranges need the v6 rules; confirm UFW has IPv6 enabled on the box, since a
  silently-ignored v6 rule set means v6 clients are refused while v4 works.
- Pod and service CIDR allows must survive — they are what keeps in-cluster
  traffic flowing through the host firewall.
- Reuse `runRemoteCommand()` and `preferredSshIp()`; this is a
  `cloud:harden`-shaped command, not a kubectl one.

## Files

- `app/Commands/Cloud/CloudShieldCommand.php`, `CloudUnshieldCommand.php` — new.
- `app/Traits/InteractsWithServerHardening.php` — teach the script builder about
  source-scoped 80/443, replacing the unconditional public rule.
- `app/Traits/ChecksCloudflareProxy.php` — reuse for the per-host proxy check.
- `app/Commands/Cloud/CloudHardenCommand.php` — the closing pointer line.
- `app/Commands/DoctorCommand.php` — the range-drift warning.
- Tests: preflight refuses on a grey web host; refuses without the DNS
  challenge; ignores a grey non-web host; `--dry-run` writes nothing; the
  rendered UFW script deletes the blanket rules before adding scoped ones.

## Open questions

1. **Scope.** Shield the whole origin, or only when *every* environment on the
   stack agrees? A stack shared by two environments has one firewall; a second
   environment adding a grey host later silently breaks it. Probably: the
   preflight covers every Ingress on the cluster regardless of environment, and
   the environment argument only selects which server to reach.
2. **Should `cloud:shield` offer to proxy the grey hosts it finds** rather than
   only refusing? Convenient, but it turns a firewall command into a DNS-mutating
   one. Leaning no — refuse and name the command.
3. **Interaction with `cloud:configure:tunnel`.** A Cloudflare Tunnel removes the
   need for *any* inbound port, which is strictly stronger than an allowlist. If
   tunnels ever cover Cluster Tools — they are project-scoped today — this
   command becomes the fallback for the non-tunnel path rather than the answer.

## Sequencing

After the canonical-naming migration, and after `tls:init` has actually been run
on the cluster. The documented order is `tls:init` → proxy the hosts →
`cloud:shield`, and the preflight enforces exactly that order.
