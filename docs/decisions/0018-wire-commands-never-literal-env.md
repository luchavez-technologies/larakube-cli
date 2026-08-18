# 0018 — `:wire` commands deliver env changes via Secret + rollout restart, never a literal `kubectl set env` value

**Status:** Accepted (2026-08-18)

## Context

`{tool}:init`'s base Deployment manifest declares OIDC/SMTP env vars as
*optional* `valueFrom: secretKeyRef` entries pointing at the tool's wiring
Secret (e.g. `design-oidc-<instance>`). `sso:wire`/`mail:wire` write the real
values into that Secret — then, separately, also apply the same values a
second time as **literal** `kubectl set env deployment/X KEY=value` pairs
directly on the live Deployment, converting those entries from
`valueFrom: secretKeyRef` to a hardcoded `value:`.

That literal write was never recorded in `kubectl apply`'s
`last-applied-configuration` bookkeeping (only `kubectl apply` maintains it;
`kubectl set env` doesn't touch it). So the next time `{tool}:init` re-applies
its base manifest — for any reason, including an unrelated change like adding
resource limits — `kubectl` computes an invalid three-way merge patch for
every env entry `:wire` had literally overwritten:

```
Deployment.apps "design-penpot-backend-design-luchtech-dev" is invalid:
[spec.template.spec.containers[0].env[24].valueFrom: Invalid value: "":
may not be specified when `value` is not empty, ...]
```

Confirmed live 2026-08-18 on Design, via a read-only `--dry-run=server`
(not applied) after `sso:wire` had run. The mechanism is generic, not
Design-specific: `SsoWireCommand::applyToolEnv()` and `MailWireCommand`'s
equivalent are the **only** two places that apply any tool's OIDC/SMTP wiring,
and both do this same redundant literal-write pass unconditionally whenever a
tool's schema declares `'static' => [...]`. Every one of the 20 tools
implementing `HasOidcWiring`/`HasSmtpWiring` does, so every one was equally
exposed — Design was simply the first `{tool}:init` re-run to actually land
after `:wire` had touched it.

`docs/decisions/0013-design-init-idempotent-flags.md` chose the literal
`kubectl set env` write deliberately, to guarantee the value "actually reach[es]
the running pod regardless of which shape... is currently live" — a real
constraint (env sourced from a Secret is not hot-reloaded into an already
running container). That constraint still holds. What ADR 0013 didn't
anticipate is that the literal write itself creates a *second*, worse problem:
it makes the tool's own `:init` permanently unable to re-apply its base
manifest afterward.

## Decision

1. A `:wire` command may write values into the tool's wiring Secret — always
   via `kubectl apply` (a full-replace `create secret ... --dry-run=client -o
   yaml | kubectl apply -f -`, or `kubectl patch secret --type=merge`), never
   `kubectl set env`. Secrets don't have this problem: nothing else ever
   applies them imperatively, so their 3-way merge stays consistent on its
   own.
2. A `:wire` command may pull the **entire** secret onto the Deployment via
   `kubectl set env deployment/X --from=secret/Y`. This preserves
   `valueFrom: secretKeyRef` shape for every key — the same shape the tool's
   base manifest already declares as optional — so it can never conflict with
   a future `{tool}:init` re-apply.
3. To force a running pod to pick up a changed value **immediately** (the
   reason the literal write existed), a `:wire` command issues
   `kubectl rollout restart deployment/X` after the Secret write — never a
   literal `KEY=value` pair. This delivers the same guarantee ADR 0013 needed,
   without ever touching the env array's shape.
4. The one narrow, unavoidable exception: **removing** an env var has no
   declarative equivalent (an absent Secret key just means an optional
   `secretKeyRef` resolves to nothing — it doesn't retroactively remove a
   value a *previous* run wrote literally). `--sso-only` toggling off
   (`sso_only_vars`, e.g. Monitor's `GF_AUTH_DISABLE_LOGIN_FORM`, Sign's
   `NEXT_PUBLIC_DISABLE_EMAIL_PASS_SIGNIN`) still uses
   `kubectl set env deployment/X KEY-`. This stays safe: removing a key never
   produces the "both `value` and `valueFrom` populated" conflict — only
   *adding* a literal value does.
5. Consequently, `SsoWireCommand::applyToolEnv()`'s and `MailWireCommand`'s
   second literal-write pass for `static` vars is deleted outright — those
   values are already in the Secret and already reach the Deployment via
   decision 2's `--from=secret`. Only the unset (`KEY-`) portion survives, for
   the 4 tools with `sso_only_vars` (Data, Password, Sign, Monitor).
6. `sso_only_vars`, when `--sso-only` is active, must be merged into the
   static-var set **before** the Secret's `--from-literal=` list is built —
   previously the merge happened after, so those vars only ever reached the
   Deployment through the now-deleted literal pass and were never actually in
   the Secret.
7. `ReconcilesPenpotFlags::applyDesignPenpotFlags()` (ADR 0013 point 4) is
   revised the same way: it still refreshes the Secret's `PENPOT_FLAGS` key
   first, but the delivery step becomes `kubectl rollout restart` instead of
   a literal `kubectl set env ... PENPOT_FLAGS=<value>`.

## Consequences

- `{tool}:init` can be re-run at any time — regardless of how many times
  `:wire` ran in between, or in what order — without risking an invalid
  strategic-merge-patch error. This was previously untrue for any tool
  `sso:wire`/`mail:wire` had touched.
- This applies uniformly to all 20 tools implementing
  `HasOidcWiring`/`HasSmtpWiring` today (Chat, CRM, Dashboard, Data, Design,
  Drive, Errors, Flow, GitForge, Link, Monitor, Notes, Password, Record,
  Resume, Secrets, Sheet, Sign, Support, Task) — the fix lives entirely in the
  two shared wiring methods, not per-tool code, so a **future** tool that
  implements either contract inherits correct, idempotent-safe behavior
  automatically. There is nothing a new tool's author needs to remember or
  opt into.
- A Deployment's `env` array is now touched by exactly two mechanisms across
  the whole codebase: `kubectl apply` (declarative, from `{tool}:init`'s base
  manifest) and `kubectl set env --from=secret`/`KEY-` (imperative, but always
  either `valueFrom`-shaped or a pure removal). Neither can ever produce the
  mixed `value`+`valueFrom` state that broke the merge patch.
