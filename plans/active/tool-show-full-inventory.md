# `{tool}:show` — full resource inventory, verified against the cluster

## Context

`AbstractToolShowCommand` answers one question well — *where does this thing
live?* — and nothing else. Its table is whatever each subclass's `rows()`
returns: a URL, sometimes an admin password, occasionally a consumer list.

What it never shows is the thing an operator actually needs when something is
wrong: **which objects this instance owns, and whether they are there.**

That gap is not theoretical. During the canonical-naming migration two tools
passed every check and were broken:

- `dashboard`'s teardown targeted `dashboard-headlamp-oidc-…` and
  `ingress/dashboard-…` after the ledger flip renamed them. `dashboard:show`
  reported the tool healthy.
- `monitor`'s `--vpn-only` Middleware was created as
  `grafana-vpn-only-{instance}` while the Ingress annotation asked for
  `grafana-vpn-only`. Traefik fails the whole router on a missing middleware
  reference. `monitor:show` reported the tool healthy.

In both cases every name involved was derivable from `ToolInstance`. A `:show`
that derives names and prints them would have printed both wrong answers with
equal confidence. **The inventory is only worth building if it reads the
cluster.**

## Decisions taken

| Question | Decision |
|---|---|
| Derive names, or read the cluster? | **Read the cluster.** A few extra `kubectl get`s is an acceptable cost for a command that runs interactively. |
| Secret values | **Masked by default**, `--reveal` opts in, and the masked row carries a hint naming the flag. |
| `--json` | Same inventory, same verification, machine-readable. |

## Design

### The inventory

`ToolInstance` already knows every name this instance should own. The vendor's
`components()` already lists every Deployment and its `resources:` kind/name
pairs (this is what `teardownComponentsCommand()` deletes). Commons allocations
come from `commonsDatabases()` / `commonsRedisTenants()` / `commonsBuckets()`.
The Middleware comes from `vpnMiddleware()`.

So the *expected* set needs no new per-tool knowledge. The new work is
checking it:

```
Component                      Name                                    State
─────────────────────────────────────────────────────────────────────────────
Deployment  livekit             livekit-meet-luchtech-dev               ready 1/1
Deployment  lk-jwt              lk-jwt-meet-luchtech-dev                ready 1/1
Service                         livekit-meet-luchtech-dev               ok
Service     rtc                 livekit-rtc-meet-luchtech-dev           ok
Ingress                         livekit-meet-luchtech-dev               meet.luchtech.dev
Secret      config              livekit-config-meet-luchtech-dev        ok
Secret      credentials         livekit-secrets-meet-luchtech-dev       ok  (--reveal)
Middleware  stripprefix         lk-jwt-stripprefix-meet-luchtech-dev    ok
```

and, for a tool with Commons allocations:

```
Commons     database            outline_notes_luchtech_dev              ok
Commons     redis index         8                                       ok
Commons     bucket              outline-storage-notes-luchtech-dev      ok
```

### Three states, not two

- **ok / ready** — expected and present.
- **MISSING** — expected and absent. This is the dashboard case.
- **UNCLAIMED** — present, carries this instance's `larakube.io/*` labels, and
  is **not** in the expected set. This is the monitor case, and it is the row
  that earns the whole feature: a label-selector sweep over the namespace finds
  objects we own but no longer generate.

A `MISSING` or `UNCLAIMED` row must change the exit code, so `{tool}:show` is
usable as a health gate in a script.

### Masking

`webmail:show` prints an admin password in cleartext today, and this change
adds more secret surface to a command whose output people paste into issues.

- Default: `••••••••` plus the hint `values hidden — pass --reveal to show`.
- `--reveal`: prints values.
- `--json`: masked unless `--reveal`, so piping to a file is safe by default.

Existing `rows()` overrides that print secrets (`webmail`, `data`, `insights`,
`sso`, …) move onto the same masking helper rather than each deciding.

### What stays in `rows()`

The per-tool table keeps its current job — the human-facing "how do I log in"
lines. The inventory is a **second table** below it, suppressible with
`--no-inventory` for anyone who just wants the URL.

## Files

- `app/Commands/Tool/AbstractToolShowCommand.php` — the inventory table, the
  three states, `--reveal` / `--no-inventory`, the JSON shape.
- `app/Data/ToolInstance.php` — a method returning the full expected
  `ResourceRef` set for an instance (components' deployments + their
  `resources:`, the VPN Middleware). This is the same list
  `teardownComponentsCommand()` builds; both should read it from one place, so
  teardown and show can never disagree about what a tool owns.
- `app/Services/Kubectl.php` — a batched existence probe (one `get` per kind
  with `-o name`, not one per object).
- The ~10 `*ShowCommand.php` subclasses that print secret values.

## Phases

1. **Expected-set accessor on `ToolInstance`**, with
   `teardownComponentsCommand()` switched to consume it. No behaviour change;
   the ratchet test in `ClusterToolLifecycleTest` already guards the names.
2. **Inventory table** — expected set, existence probe, `ok` / `MISSING`.
3. **`UNCLAIMED` sweep** — label-selector query per kind, diffed against the
   expected set.
4. **Masking + `--reveal`**, and the JSON shape.
5. **Commons rows** — database / redis index / bucket, verified against the
   Plex registry *and* the live Commons (see the backend-choice plan: the
   registry currently records neither owner nor namespace, so "verified" here
   means the database/bucket exists, not that the registry agrees).

## Tests

- A migrated tool's inventory lists exactly the objects its manifests render
  (render the manifest, parse the names, compare) — this is the check that
  would have failed on dashboard.
- A Middleware whose name does not match the Ingress annotation surfaces as
  `MISSING` — the monitor case.
- An object carrying the instance's labels but absent from the expected set
  surfaces as `UNCLAIMED`.
- Secret values are masked without `--reveal`, in both table and JSON.
- `MISSING` / `UNCLAIMED` produce a non-zero exit.

## Sequencing

Independent of the backend-choice plan and much smaller. Can land any time
**after** the canonical-naming migration finishes — before that, half the tools
would legitimately report drift, which buries the signal.
