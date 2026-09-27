# VPN (NetBird) — live migration onto the canonical naming

Runbook for moving the deployed NetBird install from `vpn-*` to the canonical
`netbird-*` names. The code side is done; this is the cluster side.

**Read steps 1 and 2 before starting.** Skipping step 1 strands every VPN user
grant in Zitadel. Skipping step 2 makes the store unreadable.

## Why this one is different

Three of the four things a rename can destroy are all present here, and none of
them fails loudly:

| | |
|---|---|
| `DataStoreEncryptionKey` in the config Secret | NetBird encrypts columns in its store with it. A fresh key against an existing database does not error — it returns garbage. |
| `idp.db` on the management PVC | The embedded IdP's user database: the dashboard login, and the only way in when SSO is down. `vpn:password` needs an existing user and `/api/setup` refuses to re-bootstrap while the account exists in Postgres. Losing it is a lockout. |
| the Zitadel project | `rbacProjectName()` is derived from the Deployment name, so the rename renames the project. `sso:wire` only renames it **in place** when it can read the recorded `project-id`; otherwise it searches by the new name, finds nothing, and creates a second, empty project. Every existing grant stays on the first. |
| the Commons store | `netbird_vpn_luchtech_dev` does not exist until it is renamed. `vpn:init` would create it empty and NetBird would bootstrap a brand-new mesh — every peer, group, policy and setup key gone, while the host serves 200 throughout. |

Both PVCs are **ReadWriteOnce** on `local-path`, so the copies need the old
pods stopped. This is real downtime, not a restart blip: budget ~20 minutes
with the VPN down, and remember that any tool deployed `--vpn-only` is
unreachable for that whole window.

Peers do **not** re-enrol. The host, the Ingress and NetBird's store all
survive, so `vpn.luchtech.dev` keeps meaning the same thing to every device.

## Current state

```
host        vpn.luchtech.dev          → instance slug  vpn-luchtech-dev
context     larakube-159.89.205.239     namespace       larakube-vpn
```

| now | canonical |
|---|---|
| `deployment/vpn-management-vpn-luchtech-dev` · `service/…` · `ingress/…` | `netbird-vpn-luchtech-dev` |
| `deployment/vpn-signal-vpn-luchtech-dev` · `service/…` | `netbird-signal-vpn-luchtech-dev` |
| `deployment/vpn-relay-vpn-luchtech-dev` · `service/…` | `netbird-relay-vpn-luchtech-dev` |
| `deployment/vpn-dashboard-vpn-luchtech-dev` · `service/…` | `netbird-dashboard-vpn-luchtech-dev` |
| `deployment/vpn-client-vpn-luchtech-dev` | `netbird-client-vpn-luchtech-dev` |
| `pvc/vpn-management-storage-vpn-luchtech-dev` (2Gi) | `netbird-storage-vpn-luchtech-dev` |
| `pvc/vpn-client-storage-vpn-luchtech-dev` (128Mi) | `netbird-client-storage-vpn-luchtech-dev` |
| `configmap/vpn-resolver-config-vpn-luchtech-dev` | `netbird-client-resolver-vpn-luchtech-dev` |
| `secret/vpn-management-secrets-vpn-luchtech-dev` | `netbird-secrets-vpn-luchtech-dev` |
| `secret/vpn-management-store-vpn-luchtech-dev` | `netbird-store-vpn-luchtech-dev` |
| `secret/vpn-management-config-vpn-luchtech-dev` | `netbird-config-vpn-luchtech-dev` |
| `secret/vpn-management-oidc-vpn-luchtech-dev` | `netbird-oidc-vpn-luchtech-dev` |
| `secret/sso-app-vpn-vpn-luchtech-dev` *(in `larakube-sso`)* | `netbird-sso-vpn-luchtech-dev` |
| `externalsecret/vpn-management-secrets-vpn-luchtech-dev` | `netbird-secrets-vpn-luchtech-dev` |
| database + role `vpn_management_vpn_luchtech_dev` | `netbird_vpn_luchtech_dev` |
| Zitadel project `vpn-management-vpn-luchtech-dev` | `netbird-vpn-luchtech-dev` |

The Commons database is **not** under OpenBao static-role rotation — there is
no `…-db` ExternalSecret for VPN — so there is no stale static role to unwire
afterwards. The one ExternalSecret that does exist syncs the PAT.

Shell helpers used throughout:

```zsh
lvpn()  { kubectl --context=larakube-159.89.205.239 -n larakube-vpn "$@"; }
lsso()  { kubectl --context=larakube-159.89.205.239 -n larakube-sso "$@"; }
lplex() { kubectl --context=larakube-159.89.205.239 -n larakube-plex "$@"; }
```

And a helper for the copy Jobs. **Do not use `kubectl wait
--for=condition=complete`**: a Job that fails never gains that condition, so
the wait blocks for the full timeout while the Job has been dead the whole
time — and the VPN is down for every minute of it. This returns as soon as the
Job reaches *either* terminal state, and says which:

```zsh
waitjob() {
  local job=$1 timeout=${2:-600} waited=0 cond=''
  while [ "$waited" -lt "$timeout" ]; do
    cond=$(lvpn get job/"$job" -o jsonpath='{.status.conditions[?(@.status=="True")].type}' 2>/dev/null)
    case "$cond" in
      *Complete*) echo "✅ $job completed"; return 0 ;;
      *Failed*)   echo "❌ $job FAILED — see: lvpn logs job/$job"; return 1 ;;
    esac
    sleep 5; waited=$((waited + 5))
  done
  echo "⏱  $job still running after ${timeout}s"; return 2
}
```

## 0. Preflight

```zsh
lvpn get deploy,svc,ingress,secret,cm,pvc,externalsecret
lsso get secret sso-app-vpn-vpn-luchtech-dev -o jsonpath='{.data.project-id}' | base64 -d; echo
curl -s -o /dev/null -w '%{http_code}\n' https://vpn.luchtech.dev/
```

**Record the project id.** Step 7 verifies the same one comes back — that is
how you know the grants were kept rather than silently re-created.

Count what the store holds, so step 7 has something to compare against:

```zsh
PAT=$(lvpn get secret vpn-management-secrets-vpn-luchtech-dev -o jsonpath='{.data.pat}' | base64 -d)
curl -s -H "Authorization: Token $PAT" https://vpn.luchtech.dev/api/peers | jq 'length'
curl -s -H "Authorization: Token $PAT" https://vpn.luchtech.dev/api/setup-keys | jq 'length'
curl -s -H "Authorization: Token $PAT" https://vpn.luchtech.dev/api/users | jq 'length'
```

Confirm the Commons roles are SCRAM, not MD5. A SCRAM verifier does not
include the role name, so `ALTER ROLE … RENAME` keeps the password; an MD5
hash does include it, and Postgres **empties the password** on rename with
only a notice:

```zsh
lplex exec deploy/postgres -c postgres -- \
  psql -U postgres -tAc "show password_encryption;"
```

Expect `scram-sha-256`. If it says `md5`, stop and re-set the role's password
by hand after step 3 instead of trusting the rename.

## 1. Copy the Zitadel app Secret — before anything else

```zsh
lsso get secret sso-app-vpn-vpn-luchtech-dev -o json \
  | jq '.metadata = {name:"netbird-sso-vpn-luchtech-dev", namespace:"larakube-sso"}' \
  | lsso apply -f -

lsso get secret netbird-sso-vpn-luchtech-dev \
  -o jsonpath='{.data.project-id}' | base64 -d; echo
```

That id must match what step 0 printed. It is the only thing that makes
`sso:wire` rename the project rather than build a new one beside it, and the
failure is invisible: the dashboard still loads, SSO still redirects, and
every user who had access simply no longer does.

## 2. Copy the other four Secrets

```zsh
for pair in \
  "vpn-management-secrets-vpn-luchtech-dev:netbird-secrets-vpn-luchtech-dev" \
  "vpn-management-store-vpn-luchtech-dev:netbird-store-vpn-luchtech-dev" \
  "vpn-management-config-vpn-luchtech-dev:netbird-config-vpn-luchtech-dev" \
  "vpn-management-oidc-vpn-luchtech-dev:netbird-oidc-vpn-luchtech-dev"
do
  old="${pair%%:*}"; new="${pair##*:}"
  lvpn get secret "$old" -o json \
    | jq --arg n "$new" '.metadata = {name:$n, namespace:"larakube-vpn"}' \
    | lvpn apply -f -
done

lvpn get secret | grep netbird
```

All four must exist before continuing. `netbird-config-…` carries
`DataStoreEncryptionKey` and the relay secret; `netbird-store-…` carries the
database password the renamed role keeps; `netbird-secrets-…` carries the PAT,
the setup key and the dashboard login.

## 3. Stop the old stack

Everything from here until step 6 is downtime.

```zsh
for d in vpn-management vpn-signal vpn-relay vpn-dashboard vpn-client; do
  lvpn scale deploy/${d}-vpn-luchtech-dev --replicas=0
done
lvpn get pods -w   # ctrl-c once empty
```

The management pod has to be gone before the next step: Postgres refuses to
rename a database with an open connection.

## 4. Rename the database and the role

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres -c \
  "ALTER DATABASE vpn_management_vpn_luchtech_dev RENAME TO netbird_vpn_luchtech_dev;"

lplex exec deploy/postgres -c postgres -- psql -U postgres -c \
  "ALTER ROLE vpn_management_vpn_luchtech_dev RENAME TO netbird_vpn_luchtech_dev;"

lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc \
  "select datname, pg_size_pretty(pg_database_size(datname)) from pg_database where datname like '%netbird%' or datname like '%vpn%';"
```

Only `netbird_vpn_luchtech_dev` should remain. A `RENAME` that silently did
nothing is not possible here — unlike `DROP … IF EXISTS`, this errors on a
missing name — which is why it is worth doing before `vpn:init` rather than
letting `vpn:init` create an empty one.

## 5. Copy both PVCs

Create the claims **exactly as the template writes them** — no
`storageClassName`, and with the identity labels. A hand-made PVC that carries
a field the template omits makes the later `vpn:init` apply try to null an
immutable field, and the whole manifest is rejected:

```zsh
cat <<'YAML' | lvpn apply -f -
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: netbird-storage-vpn-luchtech-dev
  namespace: larakube-vpn
  labels:
    larakube.io/managed-by: larakube
    larakube.io/tool: vpn
    larakube.io/component: netbird
    larakube.io/instance: vpn-luchtech-dev
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 2Gi
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: netbird-client-storage-vpn-luchtech-dev
  namespace: larakube-vpn
  labels:
    larakube.io/managed-by: larakube
    larakube.io/tool: vpn
    larakube.io/component: netbird-client
    larakube.io/instance: vpn-luchtech-dev
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 128Mi
YAML
```

They stay `Pending` until something mounts them — `local-path` is
`WaitForFirstConsumer`. The copy Job is that something.

```zsh
lvpn delete job/netbird-pvc-copy --ignore-not-found --wait=true

cat <<'YAML' | lvpn apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: netbird-pvc-copy
  namespace: larakube-vpn
spec:
  backoffLimit: 0
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: copy
          image: alpine:3.22
          command: ["/bin/sh", "-c"]
          args:
            - |
              set -e
              cp -a /from-mgmt/. /to-mgmt/
              cp -a /from-client/. /to-client/
              echo "--- management ---";  ls -la /to-mgmt
              echo "--- client ---";      ls -la /to-client
          volumeMounts:
            - { name: from-mgmt,   mountPath: /from-mgmt,   readOnly: true }
            - { name: to-mgmt,     mountPath: /to-mgmt }
            - { name: from-client, mountPath: /from-client, readOnly: true }
            - { name: to-client,   mountPath: /to-client }
      volumes:
        - name: from-mgmt
          persistentVolumeClaim: { claimName: vpn-management-storage-vpn-luchtech-dev }
        - name: to-mgmt
          persistentVolumeClaim: { claimName: netbird-storage-vpn-luchtech-dev }
        - name: from-client
          persistentVolumeClaim: { claimName: vpn-client-storage-vpn-luchtech-dev }
        - name: to-client
          persistentVolumeClaim: { claimName: netbird-client-storage-vpn-luchtech-dev }
YAML

waitjob netbird-pvc-copy 600
lvpn logs job/netbird-pvc-copy
```

`idp.db` and `events.db` must both appear under `--- management ---`, and
`default.json` / `state.json` under `--- client ---`. `--- management ---`
will also show the two GeoLite/geonames databases; those are re-downloaded on
boot and do not matter.

> The `delete job` first is not optional on a retry: `spec.template` is
> immutable, so a second `apply` over an existing Job fails with
> `field is immutable` rather than re-running anything.

## 6. Deploy under the new names

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube vpn:init production \
  --context=larakube-159.89.205.239 \
  --domain=vpn.luchtech.dev
```

Then remove the old Ingress, so Traefik is not holding two routers for the
same host — the old one points at Services with no endpoints:

```zsh
lvpn delete ingress vpn-management-vpn-luchtech-dev
```

Deliberately *after* `vpn:init`, not before: ExternalDNS watches Ingresses,
and letting the record disappear even briefly leaves every machine that looked
it up in the gap caching the absence — on macOS as a NAT64 IPv6 nothing can
connect through, long after the record returns.

## 7. Re-wire SSO and verify

`vpn:init` re-applies the Deployment from its template, which drops the env
`sso:wire` wrote (ADR 0018). Re-run it:

```zsh
./larakube sso:wire production --tool=vpn \
  --context=larakube-159.89.205.239 \
  --domain=vpn.luchtech.dev
```

Then check that the project was **renamed, not replaced**:

```zsh
lsso get secret netbird-sso-vpn-luchtech-dev \
  -o jsonpath='{.data.project-id}' | base64 -d; echo
```

Same id as step 0. If it changed, stop — the grants are on the old project and
the new one is empty. Point `sso:wire` back at the recorded id before anyone
notices they have lost access.

Now the mesh itself:

```zsh
lvpn get deploy,svc,ingress,pvc,cm,secret
curl -s -o /dev/null -w '%{http_code}\n' https://vpn.luchtech.dev/

PAT=$(lvpn get secret netbird-secrets-vpn-luchtech-dev -o jsonpath='{.data.pat}' | base64 -d)
curl -s -H "Authorization: Token $PAT" https://vpn.luchtech.dev/api/peers | jq 'length'
curl -s -H "Authorization: Token $PAT" https://vpn.luchtech.dev/api/setup-keys | jq 'length'
curl -s -H "Authorization: Token $PAT" https://vpn.luchtech.dev/api/users | jq 'length'
```

The three counts must match step 0. A peer count of 0 against a 200 response
is the signature of a fresh store — the rename in step 4 did not take, and
NetBird bootstrapped an empty mesh.

Confirm the Deployment kept its OIDC env rather than losing it to the re-apply:

```zsh
lvpn get deploy netbird-vpn-luchtech-dev \
  -o jsonpath='{.spec.template.spec.containers[0].env[*].name}'; echo
```

Then check from a real device: connect a phone or laptop over the VPN and open
a `--vpn-only` host. The API counts prove the store survived; only a live peer
proves routing did.

## 8. Re-point the PAT sync and the nightly backup

The ExternalSecret has `creationPolicy: Merge` and cannot create its target, so
it has to be recreated against the Secret that now exists:

```zsh
./larakube secrets:init production --context=larakube-159.89.205.239
lvpn delete externalsecret vpn-management-secrets-vpn-luchtech-dev
lvpn get externalsecret
```

The backup CronJob freezes its volume list at schedule time, so it is still
archiving a Deployment name that no longer exists:

```zsh
./larakube backup:schedule production \
  --cron="17 3 * * *" --timezone=Asia/Manila \
  --context=larakube-159.89.205.239
```

Check the count it reports. It should be the same number of volumes as before
with `netbird-vpn-luchtech-dev` in place of `vpn-management-vpn-luchtech-dev` —
if it reports fewer, discovery failed and the CronJob now covers less than it
says. Re-run rather than accepting it.

## 9. Delete the old resources

Only after step 7 passed and someone has actually used the VPN. Until this
point the old PVCs still hold a complete copy, which is the rollback.

```zsh
for d in vpn-management vpn-signal vpn-relay vpn-dashboard vpn-client; do
  lvpn delete deploy/${d}-vpn-luchtech-dev --ignore-not-found
  lvpn delete svc/${d}-vpn-luchtech-dev --ignore-not-found
done

lvpn delete cm/vpn-resolver-config-vpn-luchtech-dev --ignore-not-found
lvpn delete secret/vpn-management-secrets-vpn-luchtech-dev \
                  secret/vpn-management-store-vpn-luchtech-dev \
                  secret/vpn-management-config-vpn-luchtech-dev \
                  secret/vpn-management-oidc-vpn-luchtech-dev --ignore-not-found
lsso delete secret/sso-app-vpn-vpn-luchtech-dev --ignore-not-found
lvpn delete job/netbird-pvc-copy --ignore-not-found

# last, and only once the new mesh has been used from a real device
lvpn delete pvc/vpn-management-storage-vpn-luchtech-dev \
            pvc/vpn-client-storage-vpn-luchtech-dev
```

`local-path` reclaims on `Delete`, so the old volumes are gone for good at that
last command. There is no undo.

## 10. Drop the stale Plex registry row

`vpn:init` registered `netbird_vpn_luchtech_dev`; the old row stays behind and
nothing in the CLI removes it (no cleanup code — the cluster is tidied by
hand):

```zsh
lplex get cm plex-registry -o json > /tmp/plex-registry.backup.json

lplex get cm plex-registry -o json \
  | jq '.data["registry.json"] |= (fromjson
        | .tenants |= del(.["vpn_management_vpn_luchtech_dev"])
        | tojson)' \
  | lplex apply -f -

lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' \
  | jq '.tenants | keys | map(select(contains("netbird") or contains("vpn")))'
```

Only `netbird_vpn_luchtech_dev` should be listed.

## If it goes wrong

Nothing before step 4 is destructive — the copies are additive. From step 4 on,
the rollback is to put the names back:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres -c \
  "ALTER DATABASE netbird_vpn_luchtech_dev RENAME TO vpn_management_vpn_luchtech_dev;"
lplex exec deploy/postgres -c postgres -- psql -U postgres -c \
  "ALTER ROLE netbird_vpn_luchtech_dev RENAME TO vpn_management_vpn_luchtech_dev;"

for d in vpn-management vpn-signal vpn-relay vpn-dashboard vpn-client; do
  lvpn scale deploy/${d}-vpn-luchtech-dev --replicas=1
done
```

The old Deployments, Services, Secrets and PVCs are untouched until step 9, so
this returns the cluster to exactly where it started. Scale the new
Deployments to 0 first if `vpn:init` already ran, or two NetBird clients will
enrol as two gateway peers.

## Retrying from scratch

Safe to re-run from step 1 as long as step 9 has not run. Before a second
attempt:

```zsh
lvpn delete job/netbird-pvc-copy --ignore-not-found --wait=true
lvpn delete deploy -l larakube.io/tool=vpn --ignore-not-found
lvpn delete pvc/netbird-storage-vpn-luchtech-dev \
            pvc/netbird-client-storage-vpn-luchtech-dev --ignore-not-found
```

Then check whether step 4 already ran (`\l` in psql) and skip it if the
database is already called `netbird_vpn_luchtech_dev`.
