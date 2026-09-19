# Walkthrough: proxying a Cluster Tool through Cloudflare

Verifies tool proxy Stages B and C on a cluster that uses the DNS challenge
(`tls:init` done) and whose zone ExternalDNS manages. Pick a tool with a web UI
and a host one level below the zone, e.g. n8n at `flow.luchtech.dev`.

## 1. Proxy it
```bash
larakube tool:proxy production --domain=flow.luchtech.dev
```
Expect `Updating Ingress …` ✔ and `now proxied through Cloudflare`. Within a
minute: `dig +short flow.luchtech.dev` returns Cloudflare addresses, and
`curl -sI https://flow.luchtech.dev | grep -i cf-ray` shows a `cf-ray` header.
The site still loads and logs in.

## 2. Re-running init keeps it
```bash
larakube flow:init production --engine=n8n --domain=flow.luchtech.dev
```
No `--proxied`: the Ingress keeps `cloudflare-proxied: "true"`
(`kubectl get ingress -n larakube-shared -o yaml | grep cloudflare-proxied`, read-only).

## 3. Refusals (nothing changes)
- `larakube git:init production --proxied` → refused: Git serves SSH.
- `larakube tool:proxy production --domain=<a VPN-only tool's host>` → refused.
- A host two labels below its zone with `--proxied` → refused (edge certificate).

## 4. Back to DNS-only
```bash
larakube tool:unproxy production --domain=flow.luchtech.dev
```
Within a minute `dig` returns the cluster's own IP again; the site loads.

## Result
- [ ] 1 proxied  - [ ] 2 kept on re-run  - [ ] 3 refusals  - [ ] 4 unproxied
