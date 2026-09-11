# 0023 — A volume's size is a request; growth requires an expandable StorageClass

**Status:** Accepted (2026-09-11)

## Context

*Which* tools get a PVC is already settled, and it is correct: a tool gets one exactly when
it holds state the Plex Commons cannot hold. Directus externalizes everything — Commons
Postgres, a Commons Valkey index, a SeaweedFS bucket — so its container is stateless and
claims nothing. PocketBase is the same tool category with the opposite architecture:
embedded SQLite plus local uploads, so it must have a volume. Same rule explains why
`errors`/`insights`/`desk`/`flow` only declare a PVC under `@if ($noPlex)`, and why
`crm`, `link`, `sign`, `notes`, `sheets`, `support`, `resume` and `paste` declare none.

Two questions underneath that rule were never decided, and both have bitten already.

**1. The declared size is not enforced.** Every cluster-tool PVC omits `storageClassName`
and lands on the cluster default. On `larakube-159.89.205.239` that is:

```
NAME         PROVISIONER             EXPANSION   DEFAULT
local-path   rancher.io/local-path   <none>      true
```

`local-path` is hostPath-backed. It bind-mounts a directory on the node and reports back
whatever was requested — `status.capacity` equals `spec.resources.requests` for all 17 live
PVCs because the provisioner echoes the number, not because anything measures it. Every
`storage: 5Gi` in `resources/views/k8s/**` is documentation with YAML syntax. Nothing stops
one tool's volume from consuming the node's whole filesystem, and when it does it takes down
every other tool sharing that filesystem. 17 PVCs declare ~70 GiB against a single ~154 GiB
node, with no component watching the gap.

**2. There is no growth path.** `allowVolumeExpansion` is unset on `local-path`, so the API
server rejects a resize patch outright. The one command that looks like the answer,
`storage:migrate`, cannot be: it resolves its namespace from `.larakube.json`
(`$config->getName()`), so it only ever addresses a project namespace, and it runs
`kubectl scale deployment --all --replicas=0` — pointed at `larakube-shared` that is a
full-fleet outage to resize one volume. It also inverts the positional-argument rule, taking
`{pvc}` positionally and `--environment=` as a flag.

The absence of both answers is already visible on the live cluster as three PVCs mounted by
no pod: `data-pocketbase-pvc` (pre-dates the ADR 0012 `main`-sentinel amendment),
`data-pocketbase-pvc-pocket-luchtech-dev` (the 2026-08-09 duplicate-instance deploy), and
`grafana-storage` (the pre-Commons-Postgres Grafana era).

## Decision

**1. A PVC's `storage:` is a request, and the StorageClass decides whether it is a limit.**
On `local-path` it is advisory and must be read as a sizing *intent*, not a quota. This is
stated rather than assumed, because the repo currently reads as though the numbers bind.

**2. Every cluster-tool volume is classified `fixed` or `growth`.**

| Class | Grows with | Examples |
|---|---|---|
| `fixed` | Configuration only — bounded by what the operator writes | `traefik-acme` (128Mi), `vpn-client-storage` (128Mi), `vpn-management-storage`, `grafana-storage` under `--no-plex` |
| `growth` | User activity — unbounded in principle | Synapse media, oCIS blobs, Stalwart mail spool, Forgejo repositories, Prometheus/Loki/Tempo TSDB, PocketBase SQLite + uploads |

A `fixed` volume on a non-expandable class is fine forever. A `growth` volume on one is a
deferred outage.

**3. A `growth` volume provisioned onto a non-expandable StorageClass warns at deploy time.**
It is not blocked — `local` is a throwaway environment and `local-path` is the right answer
there — but it is never silent, because the failure mode is a full node months later with no
prior signal.

**4. Growing a volume is `storage:resize <environment> --pvc= --size=`,** a real standalone
command rather than a flag on another one. Where the StorageClass supports expansion it
patches the claim in place and stops every workload zero times — the command it replaces
scaled the whole namespace down to touch one volume.

Where the StorageClass does **not** support expansion it refuses and explains, rather than
falling back to a copy-and-swap. Drafting that fallback showed it answers a different
question: on `local-path` the request is not enforced, so a claim copied to a "larger" one
is no larger in any sense that a running pod can observe — the volume already had the whole
filesystem. Copy-and-swap is how you change a volume's *StorageClass*, not its size, and
that is a migration, which is its own operation and does not exist today.

**5. A volume's declared size is a floor, never an overwrite.** A template renders
`{{ $volumeSize('claim', '5Gi', $growth) }}`, which resolves to whichever is larger — the
live claim or the template default. Without this a `storage:resize` is undone on the next
`{tool}:init`: Kubernetes permits a PVC only to grow, so re-applying the smaller literal is
rejected by the API server and the tool's `:init` breaks permanently. The live cluster is
therefore the source of truth for a claim that exists, and the template is the floor for one
that does not — which also means a raised default in a future release still takes effect.

## Consequences

- The numbers in `resources/views/k8s/**` become either enforced or explicitly advisory.
  Neither is the status quo, where they are silently neither.
- An expandable class costs money in managed environments (`do-block-storage`) and needs the
  NFS provisioner in self-hosted ones. `larakube-nfs` already ships with
  `allowVolumeExpansion: true`, so the self-hosted path exists and is unused.
- `local` stays on `local-path` and gains nothing but the warning. That is the correct
  trade — a local cluster is disposable.
- `storage:migrate` was **deleted** (2026-09-11) rather than re-scoped. Reading it to plan
  the supersession showed it never created a target claim and never copied any data: its
  entire "sync" step was a print statement between a `scale --all --replicas=0` and a
  `scale --all --replicas=1`, after which it reported a successful migration and verified
  health checks. There was no working behaviour to preserve, and hard-coding the resumed
  replica count to `1` silently degraded any Deployment running more than one.
- Reclaiming orphaned PVCs stays a **manual** operation. The CLI does not grow a
  drift-detection or legacy-cleanup path for it; a volume the naming convention no longer
  claims is a cluster-hygiene task, not a feature.
