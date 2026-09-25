# Drive (oCIS) — live migration onto the canonical naming

Runbook for moving the deployed Drive install from its as-shipped names to the
canonical ones. The code side landed in `fad6f5a`; this is the cluster side.

**Read step 1 before starting.** Skipping it strands every Drive SSO grant.

## Why this one is different

Every rename so far moved workloads. Drive holds real data in **two** stores,
and one of them cannot be copied while the pod is running:

| | |
|---|---|
| PVC `drive-ocis-storage` | 10Gi, **ReadWriteOnce**, `local-path` — oCIS metadata, spaces index, users |
| Bucket `drive-ocis` | every uploaded file, via `STORAGE_USERS_DRIVER=s3ng` against Commons SeaweedFS |

A bucket sync taken while oCIS is writing loses whatever lands after the copy
starts, and the metadata PVC and blob store must agree — oCIS resolves blob ids
from the metadata, so a metadata snapshot older than the blob set orphans
files. **Both copies happen with oCIS scaled to 0.** This is real downtime, not
a restart blip.

## Current state

```
host        drive.luchtech.dev          → instance slug  drive-luchtech-dev
context     larakube-159.89.205.239     namespace        larakube-shared
```

| now | canonical |
|---|---|
| `deployment/drive-ocis` · `service/drive-ocis` · `ingress/drive-ocis` | `ocis-drive-luchtech-dev` |
| `pvc/drive-ocis-storage` | `ocis-storage-drive-luchtech-dev` |
| `configmap/drive-ocis-csp` | `ocis-csp-drive-luchtech-dev` |
| `secret/drive-secrets` | `ocis-secrets-drive-luchtech-dev` |
| `secret/drive-ocis-oidc` | `ocis-oidc-drive-luchtech-dev` |
| `secret/drive-ocis-smtp` | `ocis-smtp-drive-luchtech-dev` |
| `secret/sso-app-drive` *(in `larakube-sso`)* | `ocis-sso-drive-luchtech-dev` |
| bucket `drive-ocis` | `ocis-storage-drive-luchtech-dev` |

Collabora is not deployed on this cluster, so nothing here touches
`code-drive-luchtech-dev`.

Shell helper used throughout (`lkube` rather than `k`, which is already an
alias in this shell):

```zsh
lkube() { kubectl --context=larakube-159.89.205.239 -n larakube-shared "$@"; }
lsso()  { kubectl --context=larakube-159.89.205.239 -n larakube-sso "$@"; }
lplex() { kubectl --context=larakube-159.89.205.239 -n larakube-plex "$@"; }
```

## 0. Preflight

```zsh
lkube get deploy,svc,ingress,secret,pvc,configmap | grep -iE 'drive|ocis'
lsso get secret sso-app-drive -o jsonpath='{.data.project-id}' | base64 -d; echo
curl -s -o /dev/null -w '%{http_code}\n' https://drive.luchtech.dev/
```

Record the project id. It should be `387127351063871588`. Keep the terminal
open — step 6 verifies the same id comes back.

## 1. Copy the Secrets to their new names — before anything else

`sso:wire` finds the existing Zitadel project through the recorded id in
`sso-app-drive`. Under the new naming it looks for `ocis-sso-drive-luchtech-dev`
instead. If that Secret is missing, `zitadelEnsureProject()` falls back to
searching by project *name* — which is derived from the Deployment name, which
this migration just changed. No match means **a brand-new project, and every
existing Drive grant stranded on the old one**, with the host still serving 200
throughout. That is the failure that cost 6 people Forgejo access and all 9
Outline.

```zsh
lsso get secret sso-app-drive -o json \
  | jq '.metadata = {name:"ocis-sso-drive-luchtech-dev", namespace:"larakube-sso"}' \
  | lsso apply -f -

for pair in \
  "drive-secrets:ocis-secrets-drive-luchtech-dev" \
  "drive-ocis-oidc:ocis-oidc-drive-luchtech-dev" \
  "drive-ocis-smtp:ocis-smtp-drive-luchtech-dev"
do
  old="${pair%%:*}"; new="${pair##*:}"
  lkube get secret "$old" -o json \
    | jq --arg n "$new" '.metadata = {name:$n, namespace:"larakube-shared"}' \
    | lkube apply -f -
done
```

`drive-secrets` carries the **rekey key** oCIS wraps every file's encryption key
with. Copying it is what makes the rename survivable; a `drive:init` that
generates a fresh one leaves every uploaded file undecryptable.

Verify all four exist before continuing:

```zsh
lkube get secret ocis-secrets-drive-luchtech-dev ocis-oidc-drive-luchtech-dev ocis-smtp-drive-luchtech-dev
lsso  get secret ocis-sso-drive-luchtech-dev
```

## 2. Stop oCIS

```zsh
lkube scale deploy/drive-ocis --replicas=0
lkube wait --for=delete pod -l app=drive-ocis --timeout=120s
```

Drive is down from here until step 5.

## 3. Copy the metadata volume

The new PVC has to exist before the Job can mount it. `drive:init` would create
it, but not until step 5 — so create it here, with the same size and class.

```zsh
cat <<'YAML' | lkube apply -f -
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: ocis-storage-drive-luchtech-dev
  namespace: larakube-shared
spec:
  accessModes: [ReadWriteOnce]
  storageClassName: local-path
  resources:
    requests:
      storage: 10Gi
YAML

cat <<'YAML' | lkube apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: ocis-metadata-copy
  namespace: larakube-shared
spec:
  backoffLimit: 0
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: copy
          image: alpine:3.22
          command: ["sh", "-c", "cp -a /from/. /to/ && ls -la /to | head"]
          volumeMounts:
            - { name: from, mountPath: /from }
            - { name: to,   mountPath: /to }
      volumes:
        - name: from
          persistentVolumeClaim: { claimName: drive-ocis-storage }
        - name: to
          persistentVolumeClaim: { claimName: ocis-storage-drive-luchtech-dev }
YAML

lkube wait --for=condition=complete job/ocis-metadata-copy --timeout=600s
lkube logs job/ocis-metadata-copy
```

Both claims are `local-path` on the same node, so one pod can hold both. The
`--timeout` is generous: 10Gi of many small metadata files is slower than the
size suggests.

> **Check the image tag before running.** `alpine:3.22` is what this file was
> written against — confirm it is still current rather than trusting it.

## 4. Sync the bucket

Credentials come from the Commons admin Secret, not from the oCIS Deployment.

```zsh
lplex get secret plex-admin -o jsonpath='{.data}' | jq 'map_values(@base64d)'
```

Then, with those values:

```zsh
cat <<'YAML' | lkube apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: ocis-bucket-copy
  namespace: larakube-shared
spec:
  backoffLimit: 0
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: sync
          image: rclone/rclone:1.71
          env:
            - { name: RCLONE_CONFIG_S3_TYPE,              value: "s3" }
            - { name: RCLONE_CONFIG_S3_PROVIDER,          value: "Other" }
            - { name: RCLONE_CONFIG_S3_ENDPOINT,          value: "http://seaweedfs.larakube-plex.svc.cluster.local:8333" }
            - { name: RCLONE_CONFIG_S3_REGION,            value: "us-east-1" }
            - { name: RCLONE_CONFIG_S3_ACCESS_KEY_ID,     value: "REPLACE" }
            - { name: RCLONE_CONFIG_S3_SECRET_ACCESS_KEY, value: "REPLACE" }
          command:
            - sh
            - -c
            - |
              rclone mkdir s3:ocis-storage-drive-luchtech-dev
              rclone sync s3:drive-ocis s3:ocis-storage-drive-luchtech-dev --progress --checksum
              echo "--- counts ---"
              rclone size s3:drive-ocis
              rclone size s3:ocis-storage-drive-luchtech-dev
YAML

lkube wait --for=condition=complete job/ocis-bucket-copy --timeout=1800s
lkube logs job/ocis-bucket-copy | tail -20
```

**The two `rclone size` lines must match** — object count and bytes. Do not
continue if they do not. `--checksum` is deliberate: a size-and-time comparison
can call a truncated object identical.

> Check `rclone/rclone:1.71` against the current release before running.

## 5. Re-deploy under the canonical names

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube drive:init production --context=larakube-159.89.205.239
```

This creates the canonical Deployment, Service, Ingress, ConfigMap and the
Commons bucket allocation, reads the copied `ocis-secrets-…` back rather than
generating fresh keys, and registers the instance as `drive-luchtech-dev`.

It also re-applies the Deployment from the template, which **drops the env
`sso:wire` and `mail:wire` wrote** (ADR 0018) — hence the next step.

## 6. Re-wire SSO and SMTP

```zsh
./larakube sso:wire production --tool=drive --context=larakube-159.89.205.239
./larakube mail:wire production --tool=drive --context=larakube-159.89.205.239
```

Then confirm the project id is the **same one** recorded in step 0:

```zsh
lsso get secret ocis-sso-drive-luchtech-dev -o jsonpath='{.data.project-id}' | base64 -d; echo
```

A different id means a new project was created and the grants are stranded on
the old one. Stop and restore the recorded id before anyone tries to log in.

## 7. Verify before deleting anything

```zsh
lkube get deploy,svc,ingress,secret,pvc,configmap | grep -iE 'ocis|drive'
curl -s -o /dev/null -w '%{http_code}\n' https://drive.luchtech.dev/
```

Then, in a browser: log in through SSO, open a Space, and **open a file that
was uploaded before the migration**. That last one is the only check that
proves the metadata copy and the bucket copy agree — the host returning 200
proves neither.

## 8. Remove the old objects

Only after step 7 passes.

```zsh
lkube delete deployment/drive-ocis service/drive-ocis ingress/drive-ocis \
  configmap/drive-ocis-csp \
  secret/drive-secrets secret/drive-ocis-oidc secret/drive-ocis-smtp
lsso delete secret/sso-app-drive
lkube delete job/ocis-metadata-copy job/ocis-bucket-copy
```

Deliberately kept for now, deleted only once you are confident:

- `pvc/drive-ocis-storage` — the only remaining copy of the pre-migration
  metadata.
- bucket `drive-ocis` — likewise for the blobs. Drop it through
  `plex:evict --tenant=drive-ocis` rather than by hand, so the Commons registry
  row goes with it.

## 9. The stale registry row

Drive's tool-registry entry predates instance defaulting and carries
`instance: ""`. `drive:init` registers `drive-luchtech-dev` beside it rather
than replacing it, so the empty row is left behind. It is one of the corpses
covered by the registry cleanup — see
`plans/active/tool-registry-stray-rows-cleanup.md`.

## If something goes wrong before step 8

Nothing destructive has happened yet: the old Deployment is scaled to 0, not
deleted, and both original stores are untouched.

```zsh
lkube scale deploy/drive-ocis --replicas=1
```

brings the old install straight back. The canonical objects can then be deleted
and the migration retried.
