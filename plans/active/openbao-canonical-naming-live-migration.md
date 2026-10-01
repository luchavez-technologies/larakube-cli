# OpenBao — live migration onto the canonical naming

Cluster side of the change that moves OpenBao onto `ToolInstance` names. Context
`larakube-159.89.205.239`, namespace `larakube-secrets`, host `secrets.luchtech.dev`,
instance slug `secrets-luchtech-dev`. Follows
`plans/completed/chat-canonical-naming-live-migration.md` and
`plans/active/mail-canonical-naming-live-migration.md`. Zitadel follows it:
`plans/active/zitadel-canonical-naming-live-migration.md`.

**OpenBao holds every rotating database password in the cluster and 90 KV secrets.
Read all of it first, and finish Zitadel's runbook within a few days of this one**
(see "Why the bridge" below).

**Do not run `openbao:init` or `secrets:init` before this runbook.** The code now
deploys the canonical names, so on its own it would start a second, empty, sealed
OpenBao beside the live one.

Until step 5, `larakube secrets:*` and `larakube openbao:*` commands cannot find
the old install (the new code looks for the registered instance's names). Everything
below uses `kubectl`; the helper wraps the `bao` CLI inside the pod.

## What is at stake

| | |
|---|---|
| PVC `openbao-data` (5Gi) | `file` storage: 1167 files, 5.3 MB. **Every secret, role, policy and auth config.** |
| Secret `openbao-bootstrap` | `root-token`, `unseal-key`, `admin-username`, `admin-password` |
| Static roles (8) | `documenso_sign_luchtech_dev`, `forgejo_git_luchtech_dev`, `grafana_monitor_luchtech_dev`, `outline_notes_luchtech_dev`, `stalwart_send_luchtech_dev`, `synapse_chat_luchtech_dev`, `vaultwarden_vault_luchtech_dev`, `zitadel`; each rotates weekly |
| KV `secret/production/*` | 90 keys |
| Auth methods | `kubernetes` (ESO), `oidc` (Zitadel login), `userpass` (the baseline admin), `token` |
| 8 generators + 1 `ClusterSecretStore` | every one names `http://openbao-backend.larakube-secrets.svc.cluster.local:8200` |

OpenBao runs `file` storage, so there is nothing to export: the data is a directory.
This plan **copies the claim and leaves the old one untouched** until the end, so
rolling back is a scale-up.

## What moves

| now | canonical |
|---|---|
| `deployment` / `service` / `ingress` `openbao-backend` | `openbao-secrets-luchtech-dev` |
| `serviceaccount/openbao` | `openbao-secrets-luchtech-dev` |
| `clusterrolebinding/openbao-auth-delegator` | `openbao-auth-delegator-secrets-luchtech-dev` |
| `pvc/openbao-data` | `openbao-storage-secrets-luchtech-dev` |
| `configmap/openbao-config` | `openbao-config-secrets-luchtech-dev` |
| `secret/openbao-bootstrap` | `openbao-secrets-secrets-luchtech-dev` |
| `secret/openbao-oidc` | `openbao-oidc-secrets-luchtech-dev` |
| `secret/sso-app-secrets` (in `larakube-sso`) | `openbao-sso-secrets-luchtech-dev` |
| Zitadel project `openbao-backend` | `openbao`, renamed in place by `sso:wire` (Zitadel runbook) |

Left alone on purpose: the `ClusterSecretStore openbao` (its name is the contract
every ExternalSecret reads; only its server URL changes), `secret/eso-openbao-token`
(ESO's own credential, same value), and ESO and Reloader (separate operators).

Downtime: **OpenBao is down from step 3 to step 5**, a few minutes. Running tools do
not notice: their database passwords are already in their Secrets. What waits is
ExternalSecret refresh, rotation (the earliest is due Oct 4, 13:46 UTC, Grafana's), and
logging in to OpenBao.

## Five traps specific to this one

1. **The unseal key is the data.** If the copy is unreadable, nothing recovers it.
   Step 0 takes your own copy of the claim and the Secrets; keep both.
2. **The new pod unseals itself** from the copied `unseal-key` (the postStart hook
   mounts the new credentials Secret), so the Secret copy in step 1 must be complete.
3. **Two Ingresses on one host.** Step 6 deletes the old one as soon as the new pod
   is Ready.
4. **Why the bridge.** The 8 generators name the old Service, and only
   `secrets:wire` re-applies them. That re-wire has to wait for Zitadel's migration
   (its role cannot be wired before then), and a role due to rotate while its
   ExternalSecret errors freezes the old password in the Secret and breaks the tool
   on its next restart. The bridge (an `ExternalName` Service called
   `openbao-backend`) keeps every generator working until Zitadel's runbook
   re-wires them all and deletes it.
5. **Do not delete `sso-app-secrets`, `openbao-bootstrap` or `openbao-oidc` yet.**
   Step 9 leaves them; the Zitadel runbook removes `sso-app-secrets` once the
   Zitadel project has been renamed in place.

## Helpers

```zsh
CTX=larakube-159.89.205.239
lsec() { kubectl --context=$CTX -n larakube-secrets "$@"; }
lsso() { kubectl --context=$CTX -n larakube-sso "$@"; }
lshared() { kubectl --context=$CTX -n larakube-shared "$@"; }
OLD_D=openbao-backend;                OLD_S=openbao-bootstrap
NEW_D=openbao-secrets-luchtech-dev;   NEW_S=openbao-secrets-secrets-luchtech-dev

# bao DEPLOYMENT CREDENTIALS-SECRET BAO-ARGS
bao() {
  local d=$1 s=$2; shift 2
  local tok; tok=$(lsec get secret $s -o jsonpath='{.data.root-token}' | base64 -d)
  lsec exec deploy/$d -- env BAO_TOKEN=$tok BAO_ADDR=http://127.0.0.1:8200 bao "$@"
}

waitjob() {
  local job=$1 timeout=${2:-600} waited=0 cond=''
  while [ "$waited" -lt "$timeout" ]; do
    cond=$(lsec get job/"$job" -o jsonpath='{.status.conditions[?(@.status=="True")].type}' 2>/dev/null)
    case "$cond" in
      *Complete*) echo "✅ $job completed"; return 0 ;;
      *Failed*)   echo "❌ $job FAILED — see: lsec logs job/$job"; return 1 ;;
    esac
    sleep 5; waited=$((waited + 5))
  done
  echo "⏱  $job still running after ${timeout}s"; return 2
}
```

Image: `alpine:3.24.2`.

## 0. Preflight, and your own copy of everything

Record these; steps 5 and 6 compare against them:

```zsh
bao $OLD_D $OLD_S status -format=json | jq -c '{sealed,initialized,version,storage_type}'
bao $OLD_D $OLD_S auth list -format=json | jq -r 'keys|join(",")'
bao $OLD_D $OLD_S policy list | tr '\n' ' '; echo
bao $OLD_D $OLD_S kv list -format=json secret/production | jq 'length'
bao $OLD_D $OLD_S list -format=json database/static-roles | jq -c .
for r in $(bao $OLD_D $OLD_S list -format=json database/static-roles | jq -r '.[]'); do
  printf "%s " $r; bao $OLD_D $OLD_S read -format=json database/static-roles/$r | jq -r '.data.last_vault_rotation'
done | tee ~/openbao-rotations-before.txt
bao $OLD_D $OLD_S read -format=json auth/oidc/config | jq -c '.data|{oidc_discovery_url}'
lsec exec deploy/$OLD_D -- sh -c 'find /openbao/data | wc -l; du -sk /openbao/data'
lshared get externalsecret --no-headers | awk '{print $1, $4}'
lsso get externalsecret --no-headers | awk '{print $1, $4}'
lsso get secret sso-app-secrets -o jsonpath='{.data.project-id}' | base64 -d; echo
```

Expect `sealed:false`, `initialized:true`, `storage_type:file`; auth
`kubernetes/,oidc/,token/,userpass/`; policies `admin-policy auditor-policy
db-static-creds-reader-policy default operator-policy root`; **90** keys; the 8 roles
above with a rotation time each; discovery URL `https://sso.luchtech.dev`;
**1167** files and about 5304 kB; every ExternalSecret `SecretSynced`; and one
numeric project id for `sso-app-secrets`. **Write that project id down**: step 10
checks the same one survives.

Your own copy, the claim and the Secrets (they hold the unseal key and the root
token, so keep them somewhere safe):

```zsh
STAMP=$(date +%Y%m%d-%H%M%S)
lsec exec deploy/$OLD_D -- tar czf - -C /openbao data > ~/openbao-data-$STAMP.tgz
lsec get secret openbao-bootstrap openbao-oidc eso-openbao-token -o yaml > ~/openbao-secrets-$STAMP.yaml
lsso get secret sso-app-secrets -o yaml > ~/openbao-sso-app-$STAMP.yaml
ls -la ~/openbao-data-$STAMP.tgz ~/openbao-secrets-$STAMP.yaml ~/openbao-sso-app-$STAMP.yaml
tar tzf ~/openbao-data-$STAMP.tgz | wc -l
```

The archive must be non-trivial in size, and `tar tzf` must list about 1167 entries.

## 1. Copy the Secrets and the SSO record

The credentials Secret first, because it holds the unseal key the new pod mounts:

```zsh
lsec get secret $OLD_S -o json \
  | jq --arg n "$NEW_S" '.metadata = {name:$n, namespace:"larakube-secrets"}' | lsec apply -f -
lsec get secret openbao-oidc -o json \
  | jq '.metadata = {name:"openbao-oidc-secrets-luchtech-dev", namespace:"larakube-secrets"}' | lsec apply -f -
lsso get secret sso-app-secrets -o json \
  | jq '.metadata = {name:"openbao-sso-secrets-luchtech-dev", namespace:"larakube-sso"}' | lsso apply -f -

lsec get secret $NEW_S -o jsonpath='{.data}' | jq -r 'keys|join(",")'
lsec get secret openbao-oidc-secrets-luchtech-dev -o jsonpath='{.data}' | jq -r 'keys|join(",")'
lsso get secret openbao-sso-secrets-luchtech-dev -o jsonpath='{.data}' | jq -r 'keys|join(",")'
```

They must list `admin-password,admin-username,root-token,unseal-key`,
`client-id,client-secret` and `app-id,client-id,client-secret,project-id`.

## 2. Create the new claim

Exactly as the template writes it (no `storageClassName`, identity labels):

```zsh
SIZE=$(lsec get pvc openbao-data -o jsonpath='{.spec.resources.requests.storage}')

cat <<YAML | lsec apply -f -
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: openbao-storage-secrets-luchtech-dev
  namespace: larakube-secrets
  labels:
    larakube.io/managed-by: larakube
    larakube.io/tool: secrets
    larakube.io/component: openbao
    larakube.io/instance: secrets-luchtech-dev
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: ${SIZE}
YAML
```

## 3. Stop OpenBao

OpenBao is down from here. Nothing may write while the directory is copied:

```zsh
lsec scale deploy/$OLD_D --replicas=0
until [ -z "$(lsec get pods --no-headers | grep -E '^openbao-backend')" ]; do sleep 3; done
lsec get pods --no-headers | grep -c openbao
```

The last line must print `0`.

## 4. Copy the claim

```zsh
lsec delete job/openbao-pvc-copy --ignore-not-found --wait=true

cat <<'YAML' | lsec apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: openbao-pvc-copy
  namespace: larakube-secrets
spec:
  backoffLimit: 0
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: copy
          image: alpine:3.24.2
          command: ["/bin/sh", "-c"]
          args:
            - |
              set -e
              cp -a /from/. /to/
              a=$(find /from | wc -l); b=$(find /to | wc -l)
              echo "from=$a to=$b"
              [ "$a" = "$b" ]
              diff -r /from /to
              echo IDENTICAL
              du -sk /from /to
          volumeMounts:
            - { name: from, mountPath: /from, readOnly: true }
            - { name: to,   mountPath: /to }
      volumes:
        - name: from
          persistentVolumeClaim: { claimName: openbao-data }
        - name: to
          persistentVolumeClaim: { claimName: openbao-storage-secrets-luchtech-dev }
YAML

waitjob openbao-pvc-copy 300
lsec logs job/openbao-pvc-copy
```

`from` and `to` both read **1167** (the same count step 0 printed), the log ends
`IDENTICAL`, and the two sizes match. The Job fails on any
difference, so a green Job is the proof.

## 5. Deploy under the new names

Run the new CLI from `cli/`:

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube openbao:init production --context=$CTX --domain=secrets.luchtech.dev --force
```

It applies the ServiceAccount, binding, ConfigMap, Service, Deployment and Ingress,
re-applies ESO, finds OpenBao already initialised, unseals it from the copied key,
checks the `secret/` mount, **keeps** the existing admin login (no new password is
printed), and re-points the `ClusterSecretStore` at the new Service.

```zsh
lsec get deploy,svc,ingress,pvc,cm,sa | grep -E 'openbao'
lsec rollout status deploy/$NEW_D --timeout=180s
bao $NEW_D $NEW_S status -format=json | jq -c '{sealed,initialized,version,storage_type}'
```

`sealed:false`. If the pod runs but is sealed, unseal it with `./larakube
openbao:unseal production --context=$CTX`.

## 6. Retire the old Service and Ingress, put the bridge in

The old Service selects pods that no longer exist, and the old Ingress claims the
host the new one now owns:

```zsh
lsec delete ingress $OLD_D --ignore-not-found
lsec delete service $OLD_D --ignore-not-found

cat <<'YAML' | lsec apply -f -
apiVersion: v1
kind: Service
metadata:
  name: openbao-backend
  namespace: larakube-secrets
  labels:
    larakube.io/managed-by: larakube
    larakube.io/purpose: migration-bridge
spec:
  type: ExternalName
  externalName: openbao-secrets-luchtech-dev.larakube-secrets.svc.cluster.local
YAML

curl -s -o /dev/null -w 'secrets %{http_code}\n' -m 10 https://secrets.luchtech.dev/ui/
lsec run bridgecheck --rm -i --restart=Never --image=alpine:3.24.2 -- \
  sh -c 'wget -qO- http://openbao-backend.larakube-secrets.svc.cluster.local:8200/v1/sys/health'
```

The page answers 200 or a 30x to the login, and the bridge call prints a JSON
health body with `"sealed":false`.

## 7. Compare with step 0

```zsh
bao $NEW_D $NEW_S auth list -format=json | jq -r 'keys|join(",")'
bao $NEW_D $NEW_S policy list | tr '\n' ' '; echo
bao $NEW_D $NEW_S kv list -format=json secret/production | jq 'length'
for r in $(bao $NEW_D $NEW_S list -format=json database/static-roles | jq -r '.[]'); do
  printf "%s " $r; bao $NEW_D $NEW_S read -format=json database/static-roles/$r | jq -r '.data.last_vault_rotation'
done > ~/openbao-rotations-after.txt
diff ~/openbao-rotations-before.txt ~/openbao-rotations-after.txt && echo "rotation times unchanged"
bao $NEW_D $NEW_S read -format=json auth/oidc/config | jq -c '.data|{oidc_discovery_url}'
bao $NEW_D $NEW_S read -format=json auth/kubernetes/config | jq -c '.data|{kubernetes_host,has_ca:(.kubernetes_ca_cert|length>0)}'
lsec exec deploy/$NEW_D -- sh -c 'find /openbao/data | wc -l; du -sk /openbao/data'
```

Everything must equal step 0: the same auth methods, policies, **90** keys, the same
8 roles with **identical rotation times** (that is the proof no role was recreated or
rotated by the move), the same discovery URL, and the files and size within a few
entries.

Then, after about five minutes (ESO retries every refresh), every ExternalSecret must
be `SecretSynced` again:

```zsh
lshared get externalsecret --no-headers | awk '{print $1, $4}'
lsso get externalsecret --no-headers | awk '{print $1, $4}'
```

If one stays in error, check the bridge first (step 6's last command).

## 8. Check with a person

1. **Log in at `https://secrets.luchtech.dev`** with the userpass admin: the username
   and password are in the new credentials Secret.
2. **Log in with SSO** (the OIDC method). It still works: Zitadel has not moved.
3. Open `secret/production` and read one key.

```zsh
lsec get secret $NEW_S -o jsonpath='{.data.admin-username}' | base64 -d; echo
```

If any check fails, stop: the old claim and Secrets are untouched. Roll back with the
section at the end.

## 9. Remove the old objects

Only after step 8 passed.

```zsh
lsec delete job/openbao-pvc-copy --ignore-not-found --wait=true
lsec delete deploy/$OLD_D --ignore-not-found
lsec delete serviceaccount openbao --ignore-not-found
kubectl --context=$CTX delete clusterrolebinding openbao-auth-delegator --ignore-not-found
lsec delete cm openbao-config --ignore-not-found
lsec delete secret $OLD_S --ignore-not-found

# last, and only once you are sure
lsec delete pvc/openbao-data
```

Left for the Zitadel runbook: `secret/openbao-oidc` and `secret/sso-app-secrets`
(its step 11 removes them after the Zitadel project is renamed in place), and the
`openbao-backend` bridge Service (its step 10).

## 10. Backup

The nightly backup freezes its volume list, so re-schedule it, run one, and confirm
the new volume is in it:

```zsh
./larakube backup:schedule production --cron="17 3 * * *" --timezone=Asia/Manila --context=$CTX
./larakube backup:run production --context=$CTX
./larakube backup:restore production --dry-run --context=$CTX
```

It must report the same number of volumes as before. The OpenBao one is archived from
`/openbao` as `openbao-secrets-luchtech-dev`.

## If it goes wrong

Nothing before step 9 changes anything the old install needs, except the Service and
Ingress deleted in step 6:

```zsh
lsec delete deploy/$NEW_D --ignore-not-found
lsec delete service openbao-backend --ignore-not-found        # the bridge
lsec scale deploy/$OLD_D --replicas=1
```

Then re-create the old Service and Ingress from the previous commit's
`resources/views/k8s/secrets/openbao.blade.php`, and re-point the store with the
previous commit's `secrets:init`. The old claim is intact, so the old pod unseals
from `openbao-bootstrap` exactly as before. Writes made on the new instance after
step 5 are lost on a rollback. The three files from step 0 are the last resort: to
restore, unpack the archive into a fresh claim.

Safe to retry from step 2 as long as step 9 has not run:

```zsh
lsec delete job/openbao-pvc-copy --ignore-not-found --wait=true
lsec delete deploy/$NEW_D --ignore-not-found
lsec delete pvc/openbao-storage-secrets-luchtech-dev --ignore-not-found
```
