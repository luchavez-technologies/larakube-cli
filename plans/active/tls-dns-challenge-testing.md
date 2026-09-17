# Test Plan: `tls:init` / `tls:remove` (Cloudflare DNS challenge)

**Status:** ⛔ NOT STARTED (the feature isn't built yet).
**Plan:** `plans/active/tls-dns-challenge.md`

After `./build`. Never route a check through a picker or confirm over live data:
use a scratch host, `tls:show`, and preflight refusals. Keep a copy of
Traefik's `acme.json` before Phase 1 step 2.

## Phase 0: Traefik upgrade
- [ ] `larakube traefik:setup production` rolls out the pinned version, and the
  pod runs the new image.
- [ ] Spot-check a few existing hosts: `https://portal.luchtech.dev`,
  `https://cli.larakube.app`, `https://git.luchtech.dev`. All serve 200 with
  their existing Let's Encrypt certificates (same `notBefore`).
- [ ] HTTP→HTTPS redirect still works: `curl -sI http://portal.luchtech.dev`
  returns a redirect to https.

## Phase 1a: refusals (nothing changes)
- [ ] `larakube tls:init local` refuses.
- [ ] With `LARAKUBE_CLOUDFLARE_TOKEN` set to a **read-only** token (Zone Read
  only), a non-interactive run fails at the write probe, and
  `kubectl -n traefik get secret traefik-acme-cloudflare` is still NotFound.
- [ ] A token that can't see one of the cluster's zones refuses and lists
  the uncovered hosts.

## Phase 1b: switch production
1. `larakube tls:init production`: it offers to reuse the `dns:init` token
   (group `luchtech-dev`), shows the zones, the host count, "existing
   certificates are kept", then asks to confirm.
2. Confirm.

- [ ] The Traefik args show `dnschallenge.provider=cloudflare` and no
  `httpchallenge`.
- [ ] `larakube tls:show production` reports the DNS challenge, source
  `dns:luchtech-dev`, and no uncovered hosts.
- [ ] The same spot-check hosts as Phase 0 still serve their **unchanged**
  certificates (no re-issue).
- [ ] Re-run `larakube traefik:setup production`: the args **still** use the DNS
  challenge (render path regression check).

## Phase 1c: prove issuance through DNS
Use a scratch host that has never had a certificate, e.g. deploy a scratch
static site at `tls-check.luchtech.dev`.
- [ ] Traefik logs show a DNS-01 order for `tls-check.luchtech.dev`, and
  `dig TXT _acme-challenge.tls-check.luchtech.dev @1.1.1.1` shows the record
  appear during issuance, then disappear.
- [ ] ExternalDNS logs show **no** create/delete for `_acme-challenge`.
- [ ] The host serves a Let's Encrypt certificate issued today.
- [ ] **Proxied:** set the scratch environment's
  `external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"`, regenerate and
  push. `dig +short` returns Cloudflare IPs, the page loads with no 526, and
  `curl -sI` shows `cf-ray`.
- [ ] **Forced renewal under proxy:** delete only the scratch host's entry from
  `acme.json`, restart Traefik, and confirm a fresh certificate arrives while
  still proxied.

## Phase 1d: `dns:init` never ran (fresh or non-ExternalDNS cluster)
On a scratch cluster with no `cloudflare-token-*` Secrets:
- [ ] Interactive `tls:init production` explains ExternalDNS isn't needed,
  lists the required token permissions, and prompts for a hidden token.
- [ ] Non-interactive without `LARAKUBE_CLOUDFLARE_TOKEN` fails and names the
  variable.
- [ ] After success, `tls:show` reports source `standalone`, and a hand-made A
  record host still gets a certificate through DNS.

## Phase 1e: `tls:remove`
- [ ] While the scratch host is proxied, `tls:remove production` refuses and
  names it.
- [ ] Un-proxy it, then `tls:remove production`: Traefik is back on the HTTP
  challenge, `traefik-acme-cloudflare` is gone, and
  `larakube-shared/cloudflare-token-luchtech-dev` still exists.

## Cleanup
Remove the scratch site and its namespace. Decide whether production stays on
the DNS challenge (the intended end state) or goes back via `tls:remove`.

## Report back
Which step failed and its output, or "all passed".
