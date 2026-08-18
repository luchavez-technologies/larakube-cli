# Plan: `dns:pin` — protect externally-generated DNS records from ExternalDNS's sync policy

## Goal Description

`dns:init` deploys ExternalDNS with `--policy=sync` (`resources/views/k8s/dns/zone.blade.php:90`), scoped to one zone via `--domain-filter`. Sync policy deletes any record in that zone ExternalDNS doesn't recognize as its own (tracked via its TXT ownership registry) — by design, so genuinely orphaned LaraKube-managed records get cleaned up when a tool is removed.

The problem: **any record added to the zone through a channel other than ExternalDNS itself is "unrecognized" and gets deleted on the next sync**, regardless of whether it's legitimate. Confirmed live 2026-08-16: AWS SES's Easy DKIM CNAME records for `luchtech.dev` (3 records, manually added to Cloudflare when SES relay was first set up) were silently deleted — AWS's own health notification reported the DKIM DNS records missing for 5+ days before flagging the domain as DKIM-unverified, which in turn caused every outbound message relayed through SES to external (non-`luchtech.dev`) recipients to bounce with `554 Message rejected: Email address is not verified`.

`mail:relay` (which wires Stalwart to SES) never created these records in the first place — SES's Easy DKIM setup was done manually in the AWS console, which is the normal/expected way to obtain the 3 CNAME tokens SES generates. There's no LaraKube CLI code path that provisions them today.

**Current state (2026-08-16): the 3 DKIM CNAME records were re-added manually to Cloudflare as a stopgap.** They are NOT protected — the next `dns:init`/ExternalDNS sync cycle that doesn't recognize them will delete them again. This plan is the durable fix; it hasn't been started.

## Why a generic `dns:pin`, not `dns:dkim` or `mail:dkim`

Considered and rejected: a DKIM-specific command (`dns:dkim` or extending the existing `mail:dkim`, which today only manages Stalwart's own signing keys and has never touched DNS). The actual failure mode — "a record was added to a LaraKube-managed zone through a channel ExternalDNS doesn't know about, and sync policy will eventually delete it" — isn't specific to DKIM. It will recur for any future externally-obtained DNS value that has to coexist in the zone: a third-party domain-verification TXT record, a future SPF/DMARC change, anything similar. A generic primitive solves all of these with one mechanism instead of reinventing the same fix per use case.

## Design

### `dns:pin {environment} --zone=<zone> [--file=<path>] [--name= --type= --value=] [--context=] [--dry-run]`

One primary positional (`environment`), everything else `--options` — consistent with the no-compound-positional-args rule.

Two input modes:
- **Bulk**: `--file=` points at a CSV (e.g. `~/Downloads/aws-ses-dkim.csv`, the AWS SES "Download .csv record set" export). Each row becomes one pinned record.
- **Ad-hoc**: `--name=` `--type=` `--value=` for a single record, no file needed.

For each record, applies a `DNSEndpoint` custom resource (ExternalDNS's own CRD-based source — the same mechanism it already uses for Ingress-derived records, just fed manually instead of discovered):

```yaml
apiVersion: externaldns.k8s.io/v1alpha1
kind: DNSEndpoint
metadata:
  name: <deterministic-slug-from-name-and-type>
  namespace: <dns:init's namespace>
  labels:
    larakube.dev/pinned-by: dns-pin
    larakube.dev/zone: <zone>
spec:
  endpoints:
    - dnsName: <name>
      recordType: <type>
      targets:
        - <value>
```

Deterministic naming (slug of record name + type) makes re-running `dns:pin` with the same input idempotent — updates in place, no duplicates.

Once ExternalDNS reconciles a `DNSEndpoint` object, it registers the resulting DNS record under its own TXT ownership registry, same as an Ingress-derived one — at that point `--policy=sync` recognizes it as owned and stops deleting it.

### CSV parsing

No confirmed sample of AWS SES's actual "Download .csv record set" column headers — couldn't find one in AWS's docs and don't have console access to download one directly. **Do not hardcode exact header strings.** Detect `type`/`name`/`value`-ish columns case-insensitively (e.g. matching on substring), and fail loudly with a clear error naming what wasn't recognized if the CSV's columns can't be confidently identified — never silently misparse and pin the wrong thing to the wrong record name.

If/when someone has a real sample CSV in hand, pin the parser to the exact format instead of the fuzzy matcher, and keep the fuzzy fallback only as a defensive check.

### Prerequisites — `dns:init` changes required first

None of these exist today; `dns:pin` cannot work until they ship, and **every existing `dns:init` installation needs a re-run** to pick them up (a live-cluster change, not just new code):

1. Add `--source=crd` to ExternalDNS's args in `zone.blade.php`, alongside the existing `--source=ingress` (sources are additive — doesn't disturb Ingress-driven records).
2. Apply the `DNSEndpoint` CRD definition (ExternalDNS's own upstream manifest) as part of `dns:init`.
3. Add `get`/`list`/`watch` on `dnsendpoints.externaldns.k8s.io` to ExternalDNS's ClusterRole.

### Possible future integration (not in scope for the first pass)

`mail:relay ses` could call `dns:pin` internally if the operator passes `--dkim-csv=` at setup time, auto-protecting the SES DKIM records as part of relay setup instead of requiring a manual follow-up step. Worth doing once `dns:pin` itself is proven, not before.

## Verification Plan

- Pest test: fake `kubectl apply` for the `DNSEndpoint` manifest, assert the rendered YAML shape and deterministic naming for both CSV and ad-hoc modes.
- Fixture CSV test cases: a well-formed 3-row DKIM-style CSV, a CSV with unrecognized/renamed columns (assert the loud failure, not a silent bad parse), a single-row ad-hoc invocation.
- Live: re-run `dns:init` against `luchtech.dev`, confirm `--source=crd` and the CRD/RBAC land without disturbing existing Ingress-derived records, then `dns:pin` the 3 already-manually-added SES DKIM CNAMEs and confirm they survive an ExternalDNS sync cycle (currently they do NOT — that's the bug this closes).

## Status

Not started. Design only. The 3 DKIM CNAME records are live in Cloudflare (manual, 2026-08-16) but unprotected — a routine `dns:init` re-run or ExternalDNS restart is a real risk of silently breaking SES sending again before this ships.
