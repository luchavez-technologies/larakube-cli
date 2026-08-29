# Resolver-cache poisoning after a tool teardown

**Status:** NetBird done 2026-08-29. Every other Ingress-backed tool still exposed.

## The failure

A `*:remove` deletes the tool's Ingress. ExternalDNS then deletes the DNS record. The machine
running the command caches that absence — on macOS as a NAT64-synthesised IPv6 (`64:ff9b::/96`)
with no IPv4 at all. The record comes back on the next `:init`, **but the cache does not expire
with it.**

From then on every CLI command that reaches the tool by hostname fails, while `dig` insists DNS
is fine — because `dig` queries DNS directly and bypasses the resolver cache that `curl`, PHP
and `getaddrinfo()` all use.

Confirmed live 2026-08-29 on `larakube-159.89.205.239`, across several teardown/init cycles:

```
dig                        159.89.205.239
macOS resolver cache       ipv6_address: 64:ff9b::9f59:cdef
curl --resolve <ip>        401   (service healthy the entire time)
curl by name               000
```

It surfaced every time as `vpn-client … CreateContainerConfigError` — three steps removed from
the cause, because an unreachable endpoint means bootstrap never writes the credentials Secret,
and the gateway then has no setup key to mount. Hours were lost to it.

## The two states look identical and are not

`curl` returns `000` for both. Public DNS is what separates them:

| public DNS | local resolution | meaning |
|---|---|---|
| no record | fails | **correct** — the tool is removed, nothing to flush |
| has the record | fails | **stale cache** — flush needed |

Any check must make that comparison. A bare "curl should not return 000" is wrong immediately
after a teardown, where `000` is the right answer — the first version of the `vpn:remove`
warning did exactly that and reported a healthy teardown as a fault.

## What NetBird does now (the pattern to copy)

1. **`vpn:init` aborts** rather than proceeding. `waitForTls()` returns a verdict; when this
   machine cannot resolve the host, the caller prints the remedy and returns 1 without rolling
   anything back. It previously printed the right remedy and carried on anyway, which is what
   turned a clear diagnosis into a misleading error three steps later.
2. **`vpn:remove` warns on the way out**, naming the host whose record it just removed and the
   flush command — the poisoning happens at teardown, so that is where it is cheapest to catch.
   It states plainly that the host no longer resolving is CORRECT, and frames the flush as
   conditional on a later `vpn:init` failing to reach it. It hands over no check to run there,
   because every check would correctly fail at that moment.
3. **`DeploysClusterTool::hostResolvesLocally()`** is the shared check. `gethostbyname()`
   travels the same path the HTTP client will and returns the hostname unchanged on failure.
   `reportStaleResolverCache()` prints the remedy.

Both helpers are already on the trait every `*:init` and `*:remove` uses, so adopting this
elsewhere is two call sites per tool, not new machinery.

## Remaining work

- Call `hostResolvesLocally()` before any `*:init` step that reaches the tool over its hostname,
  and abort with `reportStaleResolverCache()`.
- Warn at the end of every `*:remove` that deletes an Ingress.

Deliberately not done as a sweep: it means touching every tool's init/remove pair, and NetBird
is the reference implementation to copy from (see ADR 0021).

## Why the CLI cannot just fix it

`dscacheutil -flushcache` and `resolvectl flush-caches` both need root. The CLI cannot flush on
the operator's behalf, so telling them clearly — at the moment the cache is poisoned, and again
at the moment it bites — is the whole of the available fix.
