# 0021 — Every cluster-tool resource is named `{component}-{instance}`

**Status:** Accepted (2026-08-29) · **Amended (2026-09-20): the category is gone**

## Amendment — the category token was dropped

The original decision put the tool's category in front of the component:
`monitor-grafana-{instance}`, `vpn-management-{instance}`. With the instance slug already
derived from the tool's own host, the category repeated what the instance said —
`monitor-grafana-storage-monitor-luchtech-dev` names monitoring twice — so it was removed.
Names are `{component}[-{token}]-{instance}`, and everything below reads that way.

Two consequences that were not obvious when the category was there:

- **The component stem is the product**, because it is now the only thing in the name that
  says what the resource *is*: `grafana`, `forgejo`, `ocis`, `outline`, `netbird`. A role
  word like `management` or `dashboard` was fine behind a category and is not on its own.
- **Identity moved into labels.** Without the category, a component name can collide with
  the upstream project's own Deployments (`prometheus` against Prometheus's Helm chart), so
  discovery selects on `larakube.io/tool|component|instance` — `ToolInstance::labels()` —
  and the name only has to read well. `larakube.io/component` carries the name's own stem,
  not the registry key.

`ClusterTool::resourceNaming()` is the ledger of which generation a tool's live resources
use. A tool crosses in one change covering its manifests *and* its cluster; the ledger
disappears when the last one has.

## Context

A cluster tool creates resources across six layers — Deployments, Services, Ingresses,
Secrets, PVCs, and Commons database tenants. Each layer was historically named by whoever
wrote that tool's `*InitCommand`, usually after the upstream vendor: `netbird-management`,
`stalwart`, `chat-synapse`, `grafana`. The names were readable but arbitrary, and three
concrete failures came out of that:

**1. Multi-instance collision.** A name without an instance slug can only ever exist once
per cluster. Two projects wanting their own instance of a tool collide on the Deployment,
the PVC and the database at the same time.

**2. Silent purge failures.** `vpn:init` hardcoded its tenant as `vpn_netbird` while
`vpn:remove --purge` derived it from `commonsDatabases($instance)`. The two disagreed, and
because `DROP DATABASE IF EXISTS` on a name that never existed **reports success**, the
purge dropped nothing and reported success. The next `vpn:init` reconnected to a store that
still had its owner and failed with `412 setup already completed`. Confirmed live
2026-08-29. `sso:remove --purge` appears to have the same defect (`zitadel_main` computed
vs `zitadel` live), unfixed at time of writing.

**3. No way to reason about a cluster.** Given a Deployment there was no rule for finding
its database, its PVC, or its Secrets — you had to read that tool's code.

## Decision

**The instance slug is derived once, from the tool's host, and appended to every resource
that tool owns.** `drive.luchtech.dev` → `drive-luchtech-dev`.

**The leading token is the component, and it is the same token in every layer.** For a
Deployment named `{component}-{instance}`, the database is `{component}_{dbInstance}` —
same word, hyphens swapped for underscores.

| Layer | Pattern | Example |
|---|---|---|
| Deployment / Service / Ingress | `{component}-{instance}` | `netbird-vpn-luchtech-dev` |
| PVC | `{component}-storage-{instance}` | `netbird-storage-vpn-luchtech-dev` |
| Credentials Secret | `{component}-secrets-{instance}` | `netbird-secrets-vpn-luchtech-dev` |
| OIDC Secret | `{component}-oidc-{instance}` | `netbird-oidc-vpn-luchtech-dev` |
| SMTP Secret | `{component}-smtp-{instance}` | `grafana-smtp-monitor-luchtech-dev` |
| Config Secret | `{component}-config-{instance}` | `netbird-config-vpn-luchtech-dev` |
| DB Secret | `{component}-store-{instance}` | `netbird-store-vpn-luchtech-dev` |
| Database + role | `{component}_{dbInstance}` | `netbird_vpn_luchtech_dev` |
| S3 bucket | `{component}-{token}-{instance}` | `ocis-storage-drive-luchtech-dev` |
| Commons Redis tenant | `{component}_{instance}` | `kutt_link-luchtech-dev` |
| Backup archive | the Deployment's own name | `netbird-vpn-luchtech-dev` |
| Zitadel app Secret | `{component}-sso-{instance}` | `netbird-sso-vpn-luchtech-dev` |
| Zitadel project | `{component}-{instance}` | `netbird-vpn-luchtech-dev` |
| OpenBao static role | `{component}_{dbInstance}` | `netbird_vpn_luchtech_dev` |
| OpenBao KV keys | `{ENVIRONMENT}/{KEY}` | `production/GRAFANA_DB_PASSWORD` |

### Rules a new cluster tool must follow

1. **Never hardcode a resource name in a command.** Derive it — `commonsDatabases($instance)`
   for tenants, `deploymentName($instance)` / `components($instance)` for workloads,
   `dbSecretRef($instance)` for the DB Secret. A name computed independently in `*InitCommand`
   and in `*RemoveCommand` will drift, and the drift is invisible.
2. **The component stem names the product, and the tenant matches the workload.** NetBird's
   store belongs to the management component, which is deployed as `netbird-*`, so the
   tenant is `netbird_*`. Never a name with no matching workload: one cannot be reasoned
   about or safely dropped. (This rule read the other way round before the amendment above,
   when the category still carried the product and the component carried the role.)
3. **`components()` must be null-safe.** Called without an instance it returns the bare base
   names, which is what `ClusterTool::forDeployment()`'s reverse lookup (dynamic backup
   discovery) matches live Deployments against — it has no instance yet, because the instance
   is what it is trying to discover.
4. **Resolve the instance from the tool registry, not by threading a host through every
   signature.** `getToolHost($kubectl, $tool)` → `instanceSlugFromHost($host)`, memoised. The
   exception is `*InitCommand`, which renders and waits on its resources *before* it registers
   the tool, and so must derive the instance from the host it already has.
5. **A Secret written by a `:wire` command gets its own name**, never a key added to the
   tool's credentials Secret. ESO's ExternalSecret owns every key in the Secret it targets, so
   sharing lets a database rotation clobber unrelated credentials (see ADR 0017, ADR 0018).

### OpenBao: dynamic rotation is authoritative, static KV is the fallback

Two mechanisms can populate a tool's database password, and they must never both be active:

- **Dynamic** — `dbSecretRef()` + `HasRotatableDatabasePassword`. `secrets:wire` registers an
  OpenBao static role and an ExternalSecret named `{secret}-db`. The role name is the tenant
  name, so it inherits this ADR's database naming for free.
- **Static KV** — `openbaoSyncConfig()`. `secrets:init` creates an ExternalSecret that mirrors
  `{environment}/{KEY}` out of OpenBao's KV store into the tool's Secret.

`secrets:init` skips the static sync whenever `{secret}-db` exists, because otherwise the two
overwrite each other every reconcile and the KV one — refreshing more often — usually wins,
silently reinstating a stale password. Confirmed live 2026-08-17 on Forgejo, sustained 28P01.

**That guard was broken by this ADR's own naming.** The loop asked for the *unsuffixed*
deployment and secret, so once a tool adopted `{category}-{component}-{instance}` its
`deploymentExists()` check could never match and the sync stopped being created at all —
while the guard looked for the wrong ExternalSecret name too. Fixed 2026-08-29 by resolving
each tool's instance from the registry first.

**Declare `openbaoSyncConfig()` only for values that can actually be rotated from KV, and seed
them at `:init`.** NetBird declares exactly one — its PAT — because that is the only credential
with no other rotation path and a real operator need: updating it from the OpenBao UI when the
CLI is not to hand. Its database password is excluded (dynamic rotation owns it) and so are its
OIDC credentials (Zitadel owns those).

Two rules make that safe:

- **`keyMap`, not `keys`, when the Secret key cannot be the KV name.** The CLI reads `pat` from
  the Secret; `production/pat` as a KV name would collide with every other tool in the store.
  The KV side carries the instance slug — `VPN_VPN_LUCHTECH_DEV_PAT` — so two instances never
  read the same entry.
- **`:init` seeds the KV key with the value it just minted.** `creationPolicy: Merge` leaves an
  unpopulated key's Secret value alone but parks the ExternalSecret at `SecretMissing` forever.
  Seeding means green from the first install, and a later paste into OpenBao is a real rotation.

**A credential the workload reads only once must not be offered here.** NetBird's setup key
lives in the same Secret and is deliberately excluded: the gateway reads it at enrolment and
never again, so a new value in OpenBao would not re-home an enrolled peer — that still needs
`vpn:setup-key` to clear the daemon's `config.json`. Exposing it would look like a rotation path
that silently does nothing.

### Two traps every migrating tool hits

**1. A KV-synced key makes the CLI a second writer, and ESO wins.**

Once a key is in `openbaoSyncConfig()`, its ExternalSecret rewrites it every 60s with
`creationPolicy: Merge`. Any command that `kubectl patch`es that same key is reverted within a
minute — and reports success, because the patch itself worked. NetBird hit this the moment the
PAT sync was declared: `vpn:rotate` and `vpn:setup-key --pat=` both patch `pat`.

The rule: **for a KV-synced key, OpenBao is the source of truth — write there first, then patch
the Secret for immediate effect.** `persistVpnPat()` is the reference implementation, and the
Secret patch is still what makes it work on clusters with no OpenBao at all.

Keys *not* in the sync (NetBird's `setup-key`, `admin-password`) stay plain Secret patches.
Mixing both in one `kubectl patch` is what to look for when auditing an existing tool.

**2. `reloader.stakater.com/auto` on a Secret does nothing.**

`eso-sync.blade.php` and `eso-db-static.blade.php` put that annotation in the ExternalSecret's
`target.template.metadata`, so it lands on the **Secret**. Reloader runs here with no args —
default mode — where the trigger is the annotation on the **workload**. The Secret-level one is
inert.

What actually restarts pods is the explicit step in `SecretsWireCommand::wireTool()`:

```php
$kubectl annotate deployment {$deployment} reloader.stakater.com/auto=true --overwrite
```

So: **if a KV-synced key is read by a pod, that tool's Deployment must carry the annotation**,
or a rotation updates the Secret and nothing picks it up until the next unrelated restart. It
does not matter for NetBird's PAT — nothing in a pod reads it, only the CLI — which is why
this went unnoticed. It will matter for most other tools, whose synced keys are database
passwords their Deployment consumes.

### Backup archive names

`backup:run` writes every volume to `{work}/vol-{name}.tar.gz`, so the name is a key, not a
label — two targets resolving to one name means the second silently overwrites the first
inside the same archive, and one instance's data never reaches the backup at all.

**The name is the Deployment's own name.** That name already IS
`{category}-{app}-{instance}`, so the archive inherits this ADR instead of restating it
through some parallel scheme — `secrets-openbao-{instance}`, not `secrets-app`. It is also the
discovery key itself, so collisions are impossible by construction rather than by convention.

The payoff is that archive naming needs no migration of its own: a tool still on a legacy
Deployment name (`openbao-backend`) keeps archiving under it, and the moment that tool adopts
the convention its archives follow automatically.

A hand-maintained alias map (`LEGACY_VOLUME_NAMES`) used to preserve six pre-dynamic names.
It was deleted along with this change rather than extended — it is exactly the temporary
compatibility code this repo refuses elsewhere, and archives predating the rename were
disposable. **Renaming strands old archives by design:** `restoreVolume()` looks up by name,
so a backup taken before a tool's rename cannot be restored after it. That is the accepted
cost of not carrying a fallback; take a fresh backup immediately after any rename.

## Consequences

Renaming an existing tool is a hard cutover: Kubernetes cannot rename a Deployment or a PVC
in place, so the workload is recreated and any volume it owned starts empty. Postgres is the
exception — `ALTER DATABASE … RENAME TO` and `ALTER ROLE … RENAME TO` are atomic and
in-place. Migration sequencing per tool lives in
`plans/active/unified-resource-naming-and-migration.md`.

Do the database first when both are being changed. A tenant is created at `:init` and is
expensive to rename afterwards; workload names can be changed on any later re-init, so
getting the tenant right first avoids a second teardown.

Tools migrated so far are listed by `ClusterTool::resourceNaming()`. VPN went first
(2026-08-29) under the original category-ful shape and again under the amended one, with a
live migration that preserved its store, both PVCs and its Zitadel grants —
`plans/active/vpn-canonical-naming-live-migration.md` is the worked example for a tool that
holds data, and Drive's is the one for a tool that also holds a bucket.

Cross-cutting fixes made alongside it, which benefit every tool on a fresh cluster:

- `sso-app-{tool}` gained the instance suffix (`SsoWireCommand` ×2, `SsoUnwireCommand`,
  `InteractsWithSsoGrants`), via `ssoAppSecretName()`. Existing clusters keep both shapes
  until migrated — `sso-app-notes` alongside `sso-app-notes-notes-luchtech-dev`.
- `secrets:init`'s OpenBao sync loop became instance-aware, restoring the
  dynamic-beats-static precedence guard for every suffixed tool.

Everything else is tracked in `plans/active/unified-resource-naming-and-migration.md`.
