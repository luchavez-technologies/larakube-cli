# 0013 — Tool `:init`/`:wire` reconcile feature flags from truth, never union with history

**Status:** Accepted (2026-08-10, revised same day after a live incident)

## Context

Penpot's behavior (which login methods exist, whether personal access tokens
work, whether optional server features are enabled) is controlled by a
single space-separated `PENPOT_FLAGS` environment variable. `design:init`'s
Deployment template never sets a literal value for it — it only wires an
*optional* `secretKeyRef` pointing at `design-penpot-oidc`'s `PENPOT_FLAGS`
key (`resources/views/k8s/design/shared.blade.php`). That secret didn't exist
until `sso:wire` or `mail:wire` created it as a side effect of wiring OIDC or
SMTP.

The consequence: a Penpot install that only ever ran `design:init` — never
`sso:wire` or `mail:wire` — booted with `PENPOT_FLAGS` entirely unset.
`design:init` — the command that's supposed to be the source of truth for
"Penpot is installed and ready" — was not idempotent with respect to its own
baseline feature set.

**First attempt (superseded below):** seed a baseline flag set directly into
`design-penpot-oidc` from `design:init`, and have `sso:wire`/`mail:wire`
*union* their own contribution with whatever string was already stored. This
shipped with `enable-mcp` in the baseline. Enabling it took down
`design.luchtech.dev` in production: Penpot's frontend image bakes in an
nginx location block that proxies MCP traffic to an upstream literally named
`penpot-mcp` — a first-party MCP backend container this cluster never
deploys. Without it nginx fails to start at all, crash-looping the *entire*
frontend, not just MCP. `enable-mcp` was reverted from the baseline, but
reverting the code and re-running `design:init` did **not** self-heal the
live secret: the union-with-history mechanism can only ever *grow* the flag
set. `{enable-access-tokens, enable-mcp}` unioned with a new baseline of
`{enable-access-tokens}` is still `{enable-access-tokens, enable-mcp}` — a
flag, once written by any run, is immortal. Restoring service required a
manual `kubectl patch` against production, exactly what
[[feedback_no_manual_kubectl]] exists to prevent.

Kubernetes doesn't merge duplicate `env:` entries in a Deployment spec (last
one wins), so a literal `PENPOT_FLAGS` value in the template and an optional
`secretKeyRef` for the same name can't coexist and merge on the cluster
side — any union of flags contributed by multiple commands has to be
computed in PHP and written back as a single value. A second, independent
issue compounded this: `sso:wire`/`mail:wire` write `PENPOT_FLAGS` via
`kubectl set env deployment/... KEY=VALUE`, which sets a **literal** `value:`
on the live Deployment — not the `valueFrom: secretKeyRef` the declarative
template declares. Whichever mechanism (declarative `apply` vs. imperative
`set env`) touched the object last determined what shape was live, so
patching only the Secret was not reliably observed by the running pod
either.

## Decision

1. `ClusterTool::baselineFlags()` remains the single declared source of
   truth for flags a tool needs unconditionally (for `DESIGN`:
   `enable-access-tokens` only — `enable-mcp` is deliberately excluded until
   the actual `penpot-mcp` companion container is deployed alongside
   backend/frontend/exporter).
2. `PENPOT_FLAGS` is never unioned with whatever was previously stored.
   `App\Traits\ReconcilesPenpotFlags::resolveDesignPenpotFlags()` recomputes
   it from scratch on every call: `baselineFlags()`, plus
   `enable-login-with-oidc` only if `design-penpot-oidc`'s
   `PENPOT_OIDC_CLIENT_ID` is genuinely non-empty, plus `enable-smtp` only if
   `design-penpot-smtp`'s `PENPOT_SMTP_HOST` is genuinely non-empty, plus
   `--sso-only`'s `disable-registration`/`disable-login-with-password` when
   that mode is explicitly requested (`sso:wire --sso-only`) or — when the
   caller doesn't know either way (`design:init`, plain `mail:wire`) —
   inferred from whether the live pod currently has
   `disable-login-with-password` set, so an unrelated run doesn't silently
   revert a previously-enabled `--sso-only` mode.
3. A flag with no corresponding live credential — like `enable-mcp` once its
   baseline entry was removed — drops out on the very next reconcile. No
   flag can persist past the run that stopped justifying it.
4. `ReconcilesPenpotFlags::applyDesignPenpotFlags()` is the one place that
   writes the result: it refreshes the Secret's `PENPOT_FLAGS` key (so a
   from-scratch install still gets a sane value via the template's
   `secretKeyRef` fallback) **and** issues a direct
   `kubectl set env deployment/{name} PENPOT_FLAGS=<value>` against both
   backend and frontend — the one mechanism proven to actually reach the
   running pod regardless of which shape (literal vs. `secretKeyRef`) is
   currently live. `kubectl set env` is idempotent by construction: a no-op
   when the value is unchanged, and only rolls the Deployment (whose
   strategy is `Recreate` — real downtime) when it actually changes.
5. `design:init` (`DesignInitCommand::ensureDesignBaselineFlags`),
   `sso:wire` (`SsoWireCommand::applyToolEnv`), and `mail:wire`
   (`MailWireCommand`'s SMTP-wiring method) all call the same
   `resolveDesignPenpotFlags()`/`applyDesignPenpotFlags()` pair instead of
   each keeping an independent copy of ad hoc merge logic — there were
   previously two near-duplicate implementations (one per wire command) in
   addition to `design:init`'s own, which is how the literal-vs-secretKeyRef
   divergence went unnoticed.

## Consequences

- A bare `larakube design:init`, with no `sso:wire`/`mail:wire` ever run,
  ships with `enable-access-tokens` already enabled.
- Removing a flag from `baselineFlags()` (or an integration losing its
  credentials) is enough to make it disappear from the live pod on the next
  `:init`/`:wire` run — no manual cluster intervention required, unlike the
  incident this ADR documents.
- The pattern generalizes: any other flag-gated tool with multiple
  contributing `:init`/`:wire` commands should compute its flag value from
  current, verifiable state each time, never by unioning with a persisted
  string.
