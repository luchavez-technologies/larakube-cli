# Plan: proxying Cluster Tools through Cloudflare safely

**Status:** Stage A ✅ (`20d4328`: Traefik trusts Cloudflare's ranges on DNS-challenge clusters).
Stage B ✅ (`ChecksCloudflareProxy`): every tool's `--proxied` runs the checks
where its host is resolved (`resolveToolHost()` / `resolveInstanceAwareHost()`),
before anything deploys; `cloud:proxy` shares them and gains the host-depth
check. Explicit `--proxied` refuses; a default-on proxy (Link, Data) falls
back to DNS-only with a warning. Stage C ✅: `tool:proxy` / `tool:unproxy`
annotate the instance's Ingresses and record `proxied` in the registry;
`{tool}:init` without `--proxied` keeps the recorded choice. Unverified live:
`plans/active/tool-proxy-testing.md`.
**Builds on:** `tls:init` (DNS challenge) and `cloud:proxy` (apps), both shipped.

## Why

Every `{tool}:init` has `--proxied`, but unlike `cloud:proxy` it checks
nothing, the setting isn't remembered (re-running `:init` without the flag
un-proxies the tool), and behind the proxy every tool sees Cloudflare's IPs
instead of the visitor's. Found while planning to proxy Sign: Documenso would
record Cloudflare addresses in every signed document's audit trail.

## Stage A: real visitor IPs (Traefik trusts Cloudflare)
- When the cluster uses the Cloudflare DNS challenge, render Traefik with
  `--entrypoints.{web,websecure}.forwardedHeaders.trustedIPs=<Cloudflare ranges>`,
  so `X-Forwarded-For` carries the real client IP to every app.
- Ranges come from Cloudflare's public `GET /client/v4/ips` at render time;
  if that fails, keep the ranges the running Traefik already has, never render
  none while a host is proxied.
- `--vpn-only` stays safe: Traefik's `ipAllowList` matches the connection's
  own address unless an `ipStrategy` says otherwise, so a forged header can't
  claim a VPN IP. A test pins that the VPN middleware has no `ipStrategy`.

## Stage B: `--proxied` gets `cloud:proxy`'s checks, and refusals
One shared check (extracted from `CloudProxyCommand`) used by both:
- the cluster renews through the DNS challenge (`tls:init`),
- an ExternalDNS instance manages the host's zone,
- the zone's SSL mode isn't Off/Flexible,
- the host is at most one label below its zone (Cloudflare's free edge
  certificate covers `*.zone` only; deeper hosts fail the TLS handshake).

Plus tool-level refusals:
- **never proxiable:** Git (SSH on 2222, large registry pushes), Mail (SMTP/IMAP),
  VPN (WireGuard/gRPC), Meet (WebRTC media ports): a `proxiable()` flag on the
  vendor, false for these;
- **`--vpn-only` + `--proxied`** together: refused (proxying a VPN-only host has
  no upside, and in-cluster callers would arrive from Cloudflare IPs).

## Stage C: `tool:proxy` / `tool:unproxy`, and the setting is remembered
- `tool:proxy {environment} --domain=<host>`: runs the Stage B checks, then
  annotates the instance's Ingress(es) (`cloudflare-proxied: "true"`), no pod
  restart. `tool:unproxy` removes it.
- The tool registry row records `proxied`, and `{tool}:init` without `--proxied`
  keeps the recorded value (`--proxied=0` / `tool:unproxy` to turn it off), so a
  re-run never silently un-proxies.
- `tls:remove`'s proxied-host refusal and `tls:show` already read the live
  annotation, so they keep working.

## Order
A first (it's what makes proxying Sign acceptable), then B, then C. One commit
each. Walkthrough: `plans/active/tool-proxy-testing.md`, written with Stage C.
