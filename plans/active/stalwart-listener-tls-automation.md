# Automate Stalwart's listener TLS certificate (stop relying on a manual admin-UI step)

## Status

Deferred follow-up, not started. Raised after a live incident on
`larakube-159.89.205.239`: Stalwart's SMTP listener for `send.luchtech.dev`
was presenting `*.ourfridays.com`'s certificate (a different tenant on the
same shared cluster), silently breaking Zitadel's outbound SMTP for email
2FA — a workmate never received her OTP code. `mail:check` was extended
(`fix(mail): catch a wrong-tenant TLS cert on Stalwart's mail ports`) to
detect this class of failure going forward, but the underlying cause — this
setting is 100% manual — is still unfixed.

## Context

`StalwartInitCommand::handle()` (`app/Commands/Stalwart/StalwartInitCommand.php`,
the printed post-install instructions around line 245) tells the operator to
go into Stalwart's own admin web UI and manually set this up:

> **2. Valid TLS on the mail ports** (so Apple Mail/Thunderbird don't warn).
> The web UI cert is handled by the ingress; the mail ports (465/993) need
> Stalwart to hold its own cert. In Settings → Server → TLS → ACME Providers:
> - Create provider · Challenge = DNS-01
> - DNS provider = Cloudflare · paste your API token
> - Subject names = `{host}`

This has no CLI automation and no verification anywhere, which is exactly
how it silently drifted: on a shared, multi-tenant cluster it's easy to
either skip this step, or copy/paste the wrong "Subject names" into an
existing provider meant for a different tenant.

## What already exists and should be reused

`app/Traits/InteractsWithStalwartApi.php` already automates the *domain's*
ACME certificate (used for DKIM/mail-routing domains) via Stalwart's JMAP
settings API, and `mail:domain` (`app/Commands/Mail/MailDomainCommand.php:103-117`)
already calls it end-to-end:

- `stalwartAcmeProviders()` — lists configured `x:AcmeProvider` objects.
- `stalwartEnsureAcmeProvider($kubectl, $ns, $contactEmail, $directory)` —
  find-or-create, idempotent by `directory` (one ACME account legitimately
  issues certs for many domains). Already deliberately overrides Stalwart's
  default `challengeType` (`TlsAlpn01`, which needs port 443 — owned by
  Traefik on this cluster) to `Dns01`, validated by writing an
  `_acme-challenge.<domain>` TXT record through the domain's own
  `x:DnsServer` — fully programmatic, no port contention. This same
  reasoning applies identically to the listener cert.
- `stalwartUpsertDomain($kubectl, $ns, $zone, $dnsServerId, $acmeProviderId)`
  — idempotent by domain name, sets `certificateManagement: {'@type':
  'Automatic', 'acmeProviderId': ...}`.

None of this currently touches the **listener-level** TLS certificate (what
`Settings → Network → Listeners` controls, per Stalwart's own docs — each
`NetworkListener` carries its own TLS config, separate from a domain's
certificate management). That's the actual setting responsible for what
cert gets presented on 465/993, and is what this follow-up needs to wire.

## What's unresolved before implementing

1. **The exact JMAP object/property Stalwart uses for a listener's TLS
   binding.** `x:AcmeProvider`/`x:Domain` confirmed live against the real
   cluster during the incident, but the listener-side object (likely
   something like `x:NetworkListener` or a `server.tls.*` settings key,
   following Stalwart's naming convention) was NOT confirmed — only found
   via upstream docs that TLS is per-listener, configured under Settings →
   Network → Listeners, referencing either a manual certificate or an ACME
   provider. Needs direct inspection against the live cluster's JMAP schema
   (same method already used elsewhere in this trait — query the object,
   read its real shape) before writing any code.
2. **Where this wiring belongs**: likely `StalwartInitCommand` itself (so a
   fresh `stalwart:init` is fully automatic, no manual step at all) —
   reusing `stalwartEnsureAcmeProvider()` with the mail host as the single
   `Dns01` subject name, then setting that provider on the relevant
   listener(s) (465/993, and optionally 587/4190). Replaces step 2 of the
   printed instructions rather than leaving it as documentation.
3. **Idempotency on re-run** (hard project rule, ADR-level): re-running
   `stalwart:init` must patch the existing listener's cert binding in place
   — same pattern as `stalwartUpsertDomain`'s "idempotent by name, patches
   in place" — never create a duplicate provider or silently leave a stale
   binding from a previous host.
4. **`mail:check`'s new TLS-identity check becomes the verification step**
   for this automation once built — no new check needed, just confirm the
   existing one (added in `fix(mail): catch a wrong-tenant TLS cert on
   Stalwart's mail ports`) goes green after `stalwart:init` wires it
   automatically.

## Suggested next step

Before writing code: `mail:check`-style read-only JMAP inspection against a
real cluster (same method as `stalwartAcmeProviders()`) to confirm the
listener object's actual schema, then extend `InteractsWithStalwartApi`
with the equivalent `stalwartEnsureListenerTls()`-style helper and wire it
into `StalwartInitCommand`.
