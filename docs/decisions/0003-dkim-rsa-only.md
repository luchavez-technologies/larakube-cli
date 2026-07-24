# 0003 — DKIM is RSA-only

**Status:** Accepted (2026-07-25)

## Context

When a domain is added through the Stalwart admin wizard, Stalwart mints **both** an
RSA and an Ed25519 DKIM key and signs outbound mail with **both**. Some relays —
notably Amazon SES — reject a message carrying two `DKIM-Signature` headers with a
`554` duplicate-header bounce. Deleting either key (leaving one algorithm) fixes
delivery.

The `x:DkimSignature` schema has **four** variants — `Dkim1`/`Dkim2` ×
`Ed25519`/`RSA` — and keys have a rotation lifecycle (`active` / `pending` /
`retiring` / `retired`), so a domain legitimately holds more than one key mid-rotation.

## Decision

**Standardise on RSA; prune Ed25519.** RSA is the broadly-compatible algorithm and
what the live servers already publish.

- `larakube mail:dkim [--fix]` lists signing keys and, with `--fix`, destroys every
  Ed25519 key (all four variants, at every stage).
- `larakube mail:check` detects the duplicate and points at the fix.
- `larakube mail:relay` enforces it as part of wiring an outbound relay.

Duplicates are judged on **`active` keys only** — a `pending`/`retiring` key
alongside the active one is normal rotation, not a fault, so counting those would
cry wolf every quarter.

## Consequences

- No more SES `554` duplicate-`DKIM-Signature` bounces.
- After a prune, the stale selector's TXT record should be removed from DNS (the
  command says so; it can't edit DNS itself).
- An earlier `MailInitCommand` attempt to set Ed25519-only DKIM was removed: it used
  the `POST /api/settings` REST endpoint that Stalwart 0.16 dropped (so it silently
  no-op'd), and `dkimManagement` is a per-`x:Domain` field with no domain present at
  init anyway.
