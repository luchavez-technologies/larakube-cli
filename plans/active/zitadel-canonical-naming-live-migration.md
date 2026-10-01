# Zitadel — live migration onto the canonical naming

Cluster side of the change that moves Zitadel onto `ToolInstance` names. Context
`larakube-159.89.205.239`, namespace `larakube-sso`, host `sso.luchtech.dev`,
instance slug `sso-luchtech-dev`. **Run it after**
`plans/active/openbao-canonical-naming-live-migration.md`: this one re-points every
OpenBao generator and deletes the bridge that runbook left, so OpenBao must already
be on its new names. Follows `plans/active/mail-canonical-naming-live-migration.md`.

**Every tool's SSO login goes through this server, so read all of it first. Do not
run `zitadel:init` or `sso:init` before this runbook:** the code now deploys the
canonical names and database, so on its own it would start a second Zitadel on an
empty database.

Until step 4, `larakube sso:*` and `larakube zitadel:*` commands cannot find the old
install (the registry row has an empty instance and the new code looks for the
registered one). Everything before that uses `kubectl` and the API.

## What is at stake

| | |
|---|---|
| Postgres `zitadel` | 22 MB, 143 tables, 5030 events: 3 orgs, 22 users, 11 projects, 15 apps, every grant |
| Secret `sso-secrets` | `masterkey` (**decrypts everything in the database**), `db-password`, `admin-password`, `admin-email`, `machine-pat` (the CLI's own API token) |
| 15 apps | Documenso, Forgejo, Grafana, Headlamp, Matrix, Matrix (MAS), NetBird, OpenBao, Outline, Vaultwarden, oCIS and the console's own |

Zitadel is stateless apart from the database and `sso-secrets`: no claim, no bucket.
The risk is not the volume; it is that **a wrong or missing `masterkey` makes the
database unreadable for good**, and that a pod that cannot reach its database does
not start.

## What moves

| now | canonical |
|---|---|
| `deployment` / `service` / `ingress` `sso-zitadel` | `zitadel-sso-luchtech-dev` |
| `secret/sso-secrets` | `zitadel-secrets-sso-luchtech-dev` |
| database + role `zitadel` | `zitadel_sso_luchtech_dev` |
| `externalsecret` and generator `sso-secrets-db` | `zitadel-secrets-sso-luchtech-dev-db` |
| OpenBao static role `zitadel` | `zitadel_sso_luchtech_dev` |
| Plex registry row `zitadel` | `zitadel_sso_luchtech_dev` |
| tools registry row `zitadel`, instance `""` | instance `sso-luchtech-dev` |
| 8 generators naming `openbao-backend` | the new OpenBao Service |
| `secret/openbao-oidc`, `secret/sso-app-secrets` | removed once the OpenBao project is renamed in place |

Downtime: **logins through Zitadel fail from step 2 to step 4**, a few minutes.
Sessions already open in the tools keep working; OpenBao's SSO login waits, but its
userpass admin works.

## Six traps specific to this one

1. **Copy `sso-secrets` first and check the master key hash.** Step 1 compares the
   SHA-256 of the key in both Secrets. Skipping it risks the one unrecoverable
   mistake.
2. **The Postgres role keeps the password.** `zitadel:init` sets the new role's
   password from the copied Secret, then registers the new static role, which rotates
   it and writes the real one back. Step 5 wires the sync at once, because the role
   rotates weekly and an unwired Secret goes stale.
3. **Zitadel needs `CREATEDB` on its role** and crash-loops without it; the init
   grants it, so do not skip step 4.
4. **The tools registry row has an empty instance.** The init heals it to
   `sso-luchtech-dev`; step 4 checks.
5. **The OpenBao login project is renamed in place.** It is `openbao-backend` today
   and `openbao` after. Without the recorded id, `sso:wire` makes a second project
   and every grant stays on the first. Step 9 checks the id survives.
6. **`secrets:wire --all` restarts every tool once** (it re-applies each generator
   and forces a rotation). Do it when that is acceptable.

## Helpers

```zsh
CTX=larakube-159.89.205.239
lsso() { kubectl --context=$CTX -n larakube-sso "$@"; }
lsec() { kubectl --context=$CTX -n larakube-secrets "$@"; }
lplex() { kubectl --context=$CTX -n larakube-plex "$@"; }
lshared() { kubectl --context=$CTX -n larakube-shared "$@"; }

# zapi SERVICE SECRET ENDPOINT [JSON-BODY]: the Zitadel API with the CLI own PAT
zapi() {
  local svc=$1 sec=$2 endpoint=$3 body=${4:-'{}'} port=$((31900 + RANDOM % 90))
  local pat; pat=$(lsso get secret $sec -o jsonpath='{.data.machine-pat}' | base64 -d)
  kubectl --context=$CTX -n larakube-sso port-forward svc/$svc $port:8080 >/dev/null 2>&1 &
  local pf=$!; sleep 4
  curl -s -m 20 -H "Host: sso.luchtech.dev" -H "Authorization: Bearer $pat" -H 'Content-Type: application/json' \
    -X POST "http://localhost:$port$endpoint" -d "$body"
  kill $pf 2>/dev/null
}

waitjob() {
  local job=$1 timeout=${2:-600} waited=0 cond=''
  while [ "$waited" -lt "$timeout" ]; do
    cond=$(lsso get job/"$job" -o jsonpath='{.status.conditions[?(@.status=="True")].type}' 2>/dev/null)
    case "$cond" in
      *Complete*) echo "✅ $job completed"; return 0 ;;
      *Failed*)   echo "❌ $job FAILED"; return 1 ;;
    esac
    sleep 5; waited=$((waited + 5))
  done
  echo "⏱  $job still running after ${timeout}s"; return 2
}
```

## 0. Preflight, and your own copy

Record these; steps 4, 5 and 9 compare against them:

```zsh
zapi sso-zitadel sso-secrets /management/v1/projects/_search | jq -c '{projects:(.result|length), names:[.result[].name]}'
zapi sso-zitadel sso-secrets /v2/users | jq -c '{users:(.result|length)}'
zapi sso-zitadel sso-secrets /admin/v1/orgs/_search | jq -c '{orgs:(.result|length)}'
for id in $(zapi sso-zitadel sso-secrets /management/v1/projects/_search | jq -r '.result[].id'); do
  zapi sso-zitadel sso-secrets /management/v1/projects/$id/apps/_search | jq -r '.result[]?.name'
done | sort | tr '\n' ','; echo
lplex exec deploy/postgres -c postgres -- psql -U postgres -d zitadel -tAc \
  "select count(*) from pg_tables where schemaname not in ('pg_catalog','information_schema'); select pg_size_pretty(pg_database_size('zitadel')); select count(*) from eventstore.events2;"
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc "show password_encryption;"
lsso get secret sso-secrets -o jsonpath='{.data}' | jq -r 'keys|join(",")'
lsso get secret sso-secrets -o jsonpath='{.data.masterkey}' | base64 -d | shasum -a 256 | cut -c1-16
lsso get secret sso-app-secrets -o jsonpath='{.data.project-id}' | base64 -d; echo
lsso get externalsecret --no-headers | awk '{print $1, $4}'
```

Expect **11** projects (`ZITADEL`, the tools' own, `LaraKube Shared Tools`,
`dashboard-headlamp` and `openbao-backend`), **22** users, **3** orgs, 15 apps
(`Admin-API, Auth-API, Documenso, Forgejo, Grafana, Headlamp, Management Console,
Management-API, Matrix, Matrix (MAS), NetBird, OpenBao, Outline, Vaultwarden, oCIS`),
**143** tables, 22 MB and about **5030** events (it grows), `scram-sha-256`, the five
keys `admin-email,admin-password,db-password,machine-pat,masterkey`, **write down the
16-character master key hash and the OpenBao project id** (the id is
`387109665529397348` at the time of writing), and `sso-secrets-db` `SecretSynced`.

Your own copy of the database and the Secrets (they hold the master key; keep them
somewhere safe):

```zsh
STAMP=$(date +%Y%m%d-%H%M%S)
lplex exec deploy/postgres -c postgres -- pg_dump -U postgres zitadel | gzip > ~/zitadel-db-$STAMP.sql.gz
lsso get secret sso-secrets sso-app-secrets -o yaml > ~/zitadel-secrets-$STAMP.yaml
ls -la ~/zitadel-db-$STAMP.sql.gz ~/zitadel-secrets-$STAMP.yaml
```

Both must be non-trivial in size.

## 1. Copy the Secret, and prove the master key came across

The copy uses `create`, not `apply`: `apply` records every key in a last-applied note,
and the init's later rewrite of the Secret then deletes any key it does not list
(that is how `machine-pat` was lost once).

```zsh
lsso get secret sso-secrets -o json \
  | jq '.metadata = {name:"zitadel-secrets-sso-luchtech-dev", namespace:"larakube-sso"}' | lsso create -f -

lsso get secret zitadel-secrets-sso-luchtech-dev -o jsonpath='{.data}' | jq -r 'keys|join(",")'
for s in sso-secrets zitadel-secrets-sso-luchtech-dev; do
  lsso get secret $s -o jsonpath='{.data.masterkey}' | base64 -d | shasum -a 256 | cut -c1-16
done
```

Five keys, and **the two hashes must be identical**. If they are not, stop and do not
continue.

## 2. Stop Zitadel

Logins fail from here. The database cannot be copied while it is in use:

```zsh
lsso scale deploy/sso-zitadel --replicas=0
until [ -z "$(lsso get pods --no-headers | grep -E '^sso-zitadel')" ]; do sleep 3; done
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc "select count(*) from pg_stat_activity where datname='zitadel';"
```

The last line must print `0`.

## 3. Copy the database

The new role and the copy. The password is a placeholder; step 4 sets the real one:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c "CREATE ROLE zitadel_sso_luchtech_dev LOGIN CREATEDB PASSWORD 'placeholder-not-used';" \
  -c 'CREATE DATABASE zitadel_sso_luchtech_dev OWNER zitadel_sso_luchtech_dev TEMPLATE zitadel;'

lplex exec deploy/postgres -c postgres -- psql -U postgres -d zitadel_sso_luchtech_dev \
  -c 'REASSIGN OWNED BY zitadel TO zitadel_sso_luchtech_dev;'

lplex exec deploy/postgres -c postgres -- psql -U postgres -d zitadel_sso_luchtech_dev -tAc \
  "select count(*) from pg_tables where schemaname not in ('pg_catalog','information_schema'); select count(*) from pg_tables where tableowner='zitadel_sso_luchtech_dev'; select count(*) from eventstore.events2;"
```

**143**, **143** (every table owned by the new role) and the same event count as
step 0. If the second number is lower, stop: the old database and role are untouched.

## 4. Deploy under the new names

Run the new CLI from `cli/`:

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube zitadel:init production --context=$CTX --domain=sso.luchtech.dev --force
```

It reads the master key, admin login and PAT back from the copied Secret (the
admin login it prints is the one you already use), gives the new role its password,
registers its OpenBao rotation role, and applies the manifests. The pod starts on the
copied database.

```zsh
lsso get deploy,svc,ingress | grep -E 'zitadel'
lsso rollout status deploy/zitadel-sso-luchtech-dev --timeout=300s
lsso logs deploy/zitadel-sso-luchtech-dev -c zitadel --tail=20 | grep -iE "error|fatal|panic" | head
```

No error lines. Remove the old Ingress as soon as the new pod is Ready, since two
Ingresses claim the host:

```zsh
lsso delete ingress sso-zitadel --ignore-not-found
curl -s -o /dev/null -w 'console %{http_code}\n' -m 10 https://sso.luchtech.dev/ui/console/
```

Check the registry healed and the role rotated into the Secret:

```zsh
kubectl --context=$CTX -n larakube-shared get secret larakube-tools-registry -o jsonpath='{.data.registry\.json}' | base64 -d \
  | jq -c '.[] | select(.tool=="zitadel") | {tool,host,instance}'
lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -c '.tenants["zitadel_sso_luchtech_dev"]'
```

`"instance":"sso-luchtech-dev"`, and a registry row for the new tenant. Check the
Secret kept all five keys, `machine-pat` included (the API token every later step uses):

```zsh
lsso get secret zitadel-secrets-sso-luchtech-dev -o jsonpath='{.data}' | jq -r 'keys|join(",")'
```

If `machine-pat` is missing, copy it back from the old Secret (it still exists) with a
patch, which `apply` never removes:

```zsh
lsso get secret sso-secrets -o jsonpath='{.data.machine-pat}' \
  | { read -r v; printf '{"data":{"machine-pat":"%s"}}' "$v"; } \
  | lsso patch secret zitadel-secrets-sso-luchtech-dev --type=merge --patch-file=/dev/stdin
```

## 5. Re-wire OpenBao rotation, and re-point every generator

Zitadel's new role rotates weekly, so its ExternalSecret has to exist now, not
later. `--all` also re-points the 7 other generators that name the old OpenBao
Service, and restarts each tool once:

```zsh
./larakube secrets:wire production --all --context=$CTX
lsso get externalsecret --no-headers | awk '{print $1, $4}'
lshared get externalsecret --no-headers | awk '{print $1, $4}'
```

`zitadel-secrets-sso-luchtech-dev-db` appears and every ExternalSecret is
`SecretSynced`. Then confirm no generator names the old Service:

```zsh
kubectl --context=$CTX get vaultdynamicsecret.generators.external-secrets.io -A -o json \
  | jq -r '.items[] | select(tostring|contains("openbao-backend")) | .metadata.namespace + "/" + .metadata.name'
```

It must print **only** `larakube-sso/sso-secrets-db`: Zitadel's OLD generator, which
step 7 deletes. Any other name is a tool `secrets:wire --all` did not re-point (its
own `secrets:wire --tool=<tool>` does); do not continue until only that one is left.

`--all` also wires NetBird's database password for rotation for the first time
(`netbird-store-vpn-luchtech-dev-db`), so expect one more ExternalSecret and a
static role `netbird_vpn_luchtech_dev` (10 roles in all, the old `zitadel` included
until step 8). Check NetBird is healthy:

```zsh
kubectl --context=$CTX -n larakube-vpn get pods --no-headers | awk '{print $1,$2,$3}'
```

## 6. Verify before deleting anything

```zsh
zapi zitadel-sso-luchtech-dev zitadel-secrets-sso-luchtech-dev /management/v1/projects/_search | jq -c '{projects:(.result|length)}'
zapi zitadel-sso-luchtech-dev zitadel-secrets-sso-luchtech-dev /v2/users | jq -c '{users:(.result|length)}'
zapi zitadel-sso-luchtech-dev zitadel-secrets-sso-luchtech-dev /admin/v1/orgs/_search | jq -c '{orgs:(.result|length)}'
lplex exec deploy/postgres -c postgres -- psql -U postgres -d zitadel_sso_luchtech_dev -tAc "select count(*) from eventstore.events2;"
```

**11**, **22**, **3**, and the event count equal to or above step 0. Then, with
people:

1. **Log in to the console** at `https://sso.luchtech.dev/ui/console` as the admin.
2. **Sign in to one tool through SSO** (Outline or Forgejo): an existing user, not a
   new one.
3. **Sign in to Element Web through SSO** (it uses the MAS client).
4. Open the **Projects** page in the console: all 11 are there with their roles.

If any check fails, stop: the old database, role and Secret are untouched. Roll back
with the section at the end.

## 7. Remove the old Zitadel objects

Only after step 6 passed.

```zsh
lsso delete deploy/sso-zitadel service/sso-zitadel --ignore-not-found
lsso delete externalsecret sso-secrets-db --ignore-not-found
lsso delete vaultdynamicsecret.generators.external-secrets.io sso-secrets-db --ignore-not-found
lsso delete secret sso-secrets --ignore-not-found
```

## 8. Drop the old database, clear the old rotation role

Evict first, then prune: `secrets:prune` only removes a static role whose Postgres
role is gone, and the eviction drops it. By exact name, never the picker:

```zsh
lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -c '.tenants["zitadel"]'
./larakube plex:evict production --tenant=zitadel --context=$CTX --force
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc "select datname from pg_database where datname like 'zitadel%'; select rolname from pg_roles where rolname like 'zitadel%';"
./larakube secrets:prune production --dry-run --context=$CTX
```

The registry row is `{"db":"zitadel","db_service":"postgres"}` with no bucket or
Redis index. Afterwards only `zitadel_sso_luchtech_dev` remains, as both database and
role, and the dry run lists `zitadel`. Then:

```zsh
./larakube secrets:prune production --context=$CTX
```

Keep the `zitadel-commons.sql` the eviction writes for a few days.

## 9. Re-wire OpenBao's own SSO login, renaming its project in place

OpenBao's Zitadel project is `openbao-backend` today and `openbao-secrets-luchtech-dev` after. The record
copied in OpenBao's runbook (`openbao-sso-secrets-luchtech-dev`) is what lets
`sso:wire` rename it instead of creating a second one:

```zsh
./larakube sso:wire production --tool=secrets --context=$CTX
lsso get secret openbao-sso-secrets-luchtech-dev -o jsonpath='{.data.project-id}' | base64 -d; echo
zapi zitadel-sso-luchtech-dev zitadel-secrets-sso-luchtech-dev /management/v1/projects/_search | jq -c '[.result[].name]'
```

The id is **the same one as step 0**, and the project list shows `openbao-secrets-luchtech-dev` and no
`openbao-backend`, still 11 in all. If the id changed, stop: grants are on the old
project; do not delete anything and tell me.

Then log in to OpenBao through SSO at `https://secrets.luchtech.dev` (method OIDC).

## 10. Remove the bridge and the old OpenBao records

Only after step 5 showed no generator but the old Zitadel one (deleted in step 7)
and step 9 passed:

```zsh
lsec delete service openbao-backend --ignore-not-found          # the bridge
lsec delete secret openbao-oidc --ignore-not-found
lsso delete secret sso-app-secrets --ignore-not-found
lsec get svc,secret | grep -E 'openbao'
```

Only the canonical objects remain: `openbao-secrets-luchtech-dev`, and the
`openbao-secrets-…` / `openbao-oidc-…` Secrets, plus `eso-openbao-token`.

## 11. Backup

```zsh
./larakube backup:schedule production --cron="17 3 * * *" --timezone=Asia/Manila --context=$CTX
./larakube backup:run production --context=$CTX
./larakube backup:restore production --dry-run --context=$CTX
```

It must report the same number of volumes as before, and one more database or the
same: Zitadel's is now `zitadel_sso_luchtech_dev`.

## If it goes wrong

Nothing before step 7 changes anything the old install needs, except the Ingress
deleted in step 4:

```zsh
lsso delete deploy/zitadel-sso-luchtech-dev --ignore-not-found
lsso scale deploy/sso-zitadel --replicas=1
```

Re-create the old Ingress from the previous commit's
`resources/views/k8s/sso/ingress.blade.php`. The old database, role and Secret are
intact, so the old pod starts exactly as before. Rotation of the old role stops after
step 5 re-wires OpenBao, so after a rollback run the previous commit's
`secrets:wire --tool=sso` once. Logins and changes made on the new instance after
step 4 are lost on a rollback. The two files from step 0 are the last resort.

Safe to retry from step 3 as long as step 7 has not run:

```zsh
lsso delete deploy/zitadel-sso-luchtech-dev --ignore-not-found
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c 'DROP DATABASE IF EXISTS zitadel_sso_luchtech_dev;' -c 'DROP ROLE IF EXISTS zitadel_sso_luchtech_dev;'
```
