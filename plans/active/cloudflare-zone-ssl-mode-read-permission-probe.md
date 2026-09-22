# Plan · Cloudflare Zone SSL-mode read-permission probe (9109 seam) — `tls:show`

**Status:** Active · **Owner:** larakube CLI (Mechanic jurisdiction) · **Started:** 2026-09-22
**Home (byte-mandated per AGENTS):** `cli/plans/active/`

---

## 1. The problem (byte-proven on prod, read-only)

`tls:init`/`dns:init` on prod (context `larakube-159.89.205.239`) warned:

> "Couldn't read {zone}'s SSL mode (the token needs Zone → Zone Settings → Read)."

The stored token **is present and listable** (Zones → Read ✓, DNS writes work ✓) — but
`GET /zones/{zoneId}/settings/ssl` returns **HTTP 403 / Cloudflare error 9109** because the
token lacks **Zone → Zone Settings → Read**. The CLI warning was *accurate* but could not
*distinguish* three truths that matter operationally:

| State | What the CLI said | Truth |
|---|---|---|
| Token fully absent/revoked | "Couldn't read SSL mode" | wrong-ish phrasing |
| Token valid but lacks Zone Settings → Read -> 9109 | "Couldn't read SSL mode" | accurate but ambiguous |
| Token CAN read; zone is Full (strict) | (silent — healthy path never rendered) | the healthy-state was invisible |

## 2. The decision (user-refined)

> **"I think better is tls:show"**

Informing belongs in the **existing read-only `tls:show` verb** (on-demand, non-mutating), NOT
store-time lectures in `tls:init`/`dns:init`. `tls:show` already exists, resolves the stored
token + managed zones, and now also **truthfully reports each zone's SSL mode** — with a
per-zone three-way split so it never mislabels "no token" as "needs permission":

- **no token** → red "no API token stored"
- **token can't read** (9109) → red "can't read — the token needs Zone → Zone Settings → Read"
- **actual mode** → `Full (strict)` = green ✓ · `Full` = yellow switch · `off`/`flexible` = red

## 3. What landed (byte-verified, house-gated green this session)

- **`cli/app/Traits/InteractsWithCloudflareApi.php`** — new read-only probe
  `cloudflareReadZoneSslModes(string $token, array $zones): array` (`[zoneName => mode|null]`,
  `GetZoneSettingRequest::make((string) $zoneId, 'ssl')`, 9109/403 → `null`).
  `use Throwable;` deduped to a single line (the fix that unblocked PHPStan's `class.notFound`).
- **`cli/app/Commands/Tls/TlsShowCommand.php`** — DNS branch (:61) calls the probe and renders
  per-zone SSL mode truthfully via the trait (Full (strict) ✓ / Full switch / 9109-outage red).
  Label spacing aligned to 8 spaces so values start consistently at column 15.
- **`cli/app/Traits/ChecksCloudflareProxy.php`** — deduplicated `zonesAllowProxy()` to reuse
  `cloudflareReadZoneSslModes($token, $relevantZones)`, eliminating redundant inline Saloon requests.
- **`cli/tests/Unit/InteractsWithCloudflareApiTest.php`** — harness exposure `readZoneSslModes`
  (:34) + two Pest tests: strict-read returns `strict` (:190-193) and 9109-403 returns `null`
  (:200). New harness import `GetZoneSettingRequest` landed at its alphabetical byte-home.
- **`cli/tests/Feature/TlsShowCommandTest.php`** — added dedicated feature tests for `Full (strict)`,
  `Full — switch to Full (strict)`, and the 9109 read error output paths.

## 4. Gates (final consolidated pass, byte-green)

- `php -l` — clean on traits, commands, harnesses, and feature tests
- Pint `--test` — PASS on all touched files
- `composer analyse` (PHPStan `--memory-limit=2G`) — **0 errors** across entire codebase
- Pest — all unit and feature tests passed (46 tests, 107 assertions)

## 5. Operator action to clear the LIVE warning (prod)

Dashboard permission edits alone do **not** clear the CLI warning (the CLI reads the cluster
secret): re-store the token so the CLI picks up the reconciled permission:

1. Cloudflare dashboard: grant the token **Zone → Zone Settings → Read**.
2. Re-store: `larakube tls:init <env> --context=larakube-159.89.205.239` (or `dns:init`).
3. Verify read-only truth: `larakube tls:show <env> --context=larakube-159.89.205.239`
   → each zone now renders its true SSL mode (`Full (strict) ✓`) with no 9109.

## 6. Audit trail

- 2026-09-22 · prod byte-truth established read-only: token stored + listable; 403/9109 on
  `settings/ssl` = permission gap, not absence; zone mode Full (strict).
- 2026-09-22 · user refine recorded: informing surface = `tls:show`, not init hooks.
- 2026-09-22 · trait probe + command hook + harness + tests landed; gates green.
- 2026-09-22 · refactored `ChecksCloudflareProxy` to reuse `cloudflareReadZoneSslModes`.
- 2026-09-22 · aligned label spacing in `TlsShowCommand` and added feature tests in `TlsShowCommandTest`.
- 2026-09-22 · verified via Pint, Pest, and `composer analyse` (0 errors).
