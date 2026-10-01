# CRM (Twenty) — live migration onto the canonical naming

Cluster side of the change that moves CRM onto `ToolInstance` names. Context
`larakube-159.89.205.239`, namespace `larakube-shared`, host `crm.luchtech.dev`,
instance slug `crm-luchtech-dev`. Follows the order of
`plans/completed/drive-canonical-naming-live-migration.md` and
`plans/completed/vpn-canonical-naming-live-migration.md`.

CRM holds real data in three places and a queue:

| | |
|---|---|
| Postgres `crm_twenty_crm_luchtech_dev` | 25 MB, the whole workspace |
| bucket `crm-twenty-storage-crm-luchtech-dev` | attachments, 25 MB |
| Redis index 7 | 1,450 keys: BullMQ queues, sessions, cache |
| Secret `crm-secrets-…` | the token secrets and `encryption-key` |

No PVC, no OpenBao rotation (verified: no ExternalSecret, no static role), no
SSO (Twenty paywalls it), no Zitadel project. So **no step here can strand an SSO
grant**, and no `secrets:wire` is needed.

## What moves

| now | canonical |
|---|---|
| `deployment/crm-twenty-crm-luchtech-dev` | `twenty-crm-luchtech-dev` |
| `deployment/crm-twenty-worker-crm-luchtech-dev` | `twenty-worker-crm-luchtech-dev` |
| `service` + `ingress` `crm-crm-luchtech-dev` | `twenty-crm-luchtech-dev` |
| `secret/crm-secrets-crm-luchtech-dev` | `twenty-secrets-crm-luchtech-dev` |
| `secret/crm-smtp-crm-luchtech-dev` | `twenty-smtp-crm-luchtech-dev` |
| database + role `crm_twenty_crm_luchtech_dev` | `twenty_crm_luchtech_dev` |
| bucket `crm-twenty-storage-crm-luchtech-dev` | `twenty-storage-crm-luchtech-dev` |
| Redis tenant `crm_twenty_crm-luchtech-dev` (index 7) | `twenty_crm_luchtech_dev` (**still index 7**) |

Downtime: CRM is down from step 3 until step 6 finishes (the boot runs schema
upgrades and takes several minutes). Redis keeps its keys, so queued jobs
survive.

## Three traps specific to this one

1. **Copy `crm-secrets` first.** `twenty:init` reads each value back *by name*.
   A missing `twenty-secrets-…` makes it generate fresh token secrets and a fresh
   `encryption-key` against a live database: every session dies and anything
   Twenty encrypted becomes unreadable.
2. **Keep Redis index 7.** `twenty:init` allocates the index under the new tenant
   name and picks a free one if that name is not in the registry, which would
   point the new Deployment at an empty Redis. Step 2 renames the registry row so
   the index carries over.
3. **Never `plex:evict` the Redis row.** It flushes the index, which is live. Step
   2 renames that row away, so there is nothing left under the old name to evict;
   step 9 evicts only the two rows that hold no Redis index.

Helpers:

```zsh
CTX=larakube-159.89.205.239
lkube() { kubectl --context=$CTX -n larakube-shared "$@"; }
lplex() { kubectl --context=$CTX -n larakube-plex "$@"; }

waitjob() {
  local job=$1 timeout=${2:-600} waited=0 cond=''
  while [ "$waited" -lt "$timeout" ]; do
    cond=$(lkube get job/"$job" -o jsonpath='{.status.conditions[?(@.status=="True")].type}' 2>/dev/null)
    case "$cond" in
      *Complete*) echo "✅ $job completed"; return 0 ;;
      *Failed*)   echo "❌ $job FAILED — see: lkube logs job/$job"; return 1 ;;
    esac
    sleep 5; waited=$((waited + 5))
  done
  echo "⏱  $job still running after ${timeout}s"; return 2
}
```

Images: `rclone/rclone:1.75.1` (current release) and `alpine:3.24.2`.

## 0. Preflight

```zsh
lkube get deploy,svc,ingress,secret | grep -iE 'twenty|crm'
curl -s -o /dev/null -w 'crm %{http_code}\n' https://crm.luchtech.dev/healthz

# is the host --vpn-only? (empty = public; anything else, pass --vpn-only to the init)
lkube get ingress crm-crm-luchtech-dev -o jsonpath='{.metadata.annotations.traefik\.ingress\.kubernetes\.io/router\.middlewares}'; echo "(end)"

# what mail:wire wrote (checked live: this prints NOTHING today, see step 7)
lkube get deploy crm-twenty-crm-luchtech-dev -o jsonpath='{range .spec.template.spec.containers[0].env[*]}{.name}{"\n"}{end}' | grep -E 'EMAIL' | sort

# the numbers to compare against after the move
lplex exec deploy/postgres -c postgres -- psql -U postgres -d crm_twenty_crm_luchtech_dev -tAc \
  "select count(*) from information_schema.schemata; select pg_size_pretty(pg_database_size(current_database()));"
lplex exec -c redis deploy/redis -- redis-cli -n 7 dbsize

# SCRAM, so ALTER ROLE ... RENAME keeps the password
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc "show password_encryption;"
```

Record the schema count, the size and the Redis key count. The last command must
say `scram-sha-256`; if it says `md5`, stop.

## 1. Copy the Secrets — before anything else

```zsh
for pair in "crm-secrets-crm-luchtech-dev:twenty-secrets-crm-luchtech-dev" \
            "crm-smtp-crm-luchtech-dev:twenty-smtp-crm-luchtech-dev"; do
  old="${pair%%:*}"; new="${pair##*:}"
  lkube get secret "$old" -o json \
    | jq --arg n "$new" '.metadata = {name:$n, namespace:"larakube-shared"}' \
    | lkube apply -f -
done

lkube get secret twenty-secrets-crm-luchtech-dev -o jsonpath='{.data}' | jq -r 'keys|join(",")'
```

It must list `access-token-secret, db-password, encryption-key, file-token-secret,
login-token-secret, refresh-token-secret, s3-key, s3-secret`. There is no
`crm-oidc-…` Secret to copy (Twenty's OIDC env is optional and was never wired).

## 2. Carry Redis index 7 over to the new tenant name

Back the registry up first, then rename only the Redis row's key:

```zsh
lplex get cm plex-registry -o json > ~/plex-registry.before-crm.json

lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -c '.tenants["crm_twenty_crm-luchtech-dev"]'
```

It must print exactly `{"redis_index":7}`. If it prints anything else, stop.

```zsh
lplex get cm plex-registry -o json \
  | jq '.data["registry.json"] |= (fromjson
        | .tenants["twenty_crm_luchtech_dev"] = .tenants["crm_twenty_crm-luchtech-dev"]
        | del(.tenants["crm_twenty_crm-luchtech-dev"])
        | tojson)' \
  | lplex apply -f -

lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' \
  | jq -c '.tenants | {new:.["twenty_crm_luchtech_dev"], old:.["crm_twenty_crm-luchtech-dev"]}'
```

Expect `{"new":{"redis_index":7},"old":null}`.

## 3. Stop the old Deployments

```zsh
lkube scale deploy/crm-twenty-crm-luchtech-dev deploy/crm-twenty-worker-crm-luchtech-dev --replicas=0
until [ -z "$(lkube get pods --no-headers | grep -E '^crm-twenty-')" ]; do sleep 3; done
```

CRM is down from here. The worker must be gone too, or the next step cannot
rename a database that still has a connection.

## 4. Rename the database and the role

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc \
  "select count(*) from pg_stat_activity where datname='crm_twenty_crm_luchtech_dev';"
```

Must be `0`. Then:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c 'ALTER DATABASE crm_twenty_crm_luchtech_dev RENAME TO twenty_crm_luchtech_dev;' \
  -c 'ALTER ROLE crm_twenty_crm_luchtech_dev RENAME TO twenty_crm_luchtech_dev;'

lplex exec deploy/postgres -c postgres -- psql -U postgres -d twenty_crm_luchtech_dev -tAc \
  "select count(*) from information_schema.schemata; select pg_size_pretty(pg_database_size(current_database()));"
```

The schema count and size must match step 0. Unlike `DROP … IF EXISTS`, `RENAME`
fails loudly on a missing name, so a typo cannot pass silently.

## 5. Copy the bucket

CRM is stopped, so nothing writes while it is copied. The credentials come from
the Secret the old Deployment has been using:

```zsh
S3_KEY=$(lkube get secret twenty-secrets-crm-luchtech-dev -o jsonpath='{.data.s3-key}' | base64 -d)
S3_SECRET=$(lkube get secret twenty-secrets-crm-luchtech-dev -o jsonpath='{.data.s3-secret}' | base64 -d)
SRC_BUCKET=$(lkube get deploy crm-twenty-crm-luchtech-dev -o jsonpath='{.spec.template.spec.containers[0].env[?(@.name=="STORAGE_S3_NAME")].value}')

[ -n "$S3_KEY" ] && [ -n "$S3_SECRET" ] && [ "$SRC_BUCKET" = "crm-twenty-storage-crm-luchtech-dev" ] \
  && echo "ok: copying '$SRC_BUCKET'" || echo "STOP: could not read the credentials or the source bucket"
```

Do not continue unless that prints `ok:`.

```zsh
lkube delete job/twenty-bucket-copy --ignore-not-found --wait=true

cat <<YAML | lkube apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: twenty-bucket-copy
  namespace: larakube-shared
spec:
  backoffLimit: 0
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: sync
          image: rclone/rclone:1.75.1
          env:
            - { name: RCLONE_CONFIG_S3_TYPE,              value: "s3" }
            - { name: RCLONE_CONFIG_S3_PROVIDER,          value: "Other" }
            - { name: RCLONE_CONFIG_S3_ENDPOINT,          value: "http://seaweedfs.larakube-plex.svc.cluster.local:8333" }
            - { name: RCLONE_CONFIG_S3_REGION,            value: "us-east-1" }
            - { name: RCLONE_CONFIG_S3_ACCESS_KEY_ID,     value: "${S3_KEY}" }
            - { name: RCLONE_CONFIG_S3_SECRET_ACCESS_KEY, value: "${S3_SECRET}" }
          command:
            - sh
            - -c
            - |
              set -e
              rclone mkdir s3:twenty-storage-crm-luchtech-dev
              rclone sync s3:${SRC_BUCKET} s3:twenty-storage-crm-luchtech-dev --checksum
              echo "--- counts ---"
              rclone size s3:${SRC_BUCKET}
              rclone size s3:twenty-storage-crm-luchtech-dev
YAML

waitjob twenty-bucket-copy 900
lkube logs job/twenty-bucket-copy | tail -8
```

The two `rclone size` lines must match in object count and bytes. `--checksum`
is deliberate: a size-and-time comparison can call a truncated object identical.

## 6. Deploy under the new names

Add `--vpn-only` if step 0 printed a Middleware. The boot runs schema upgrades,
so the command can take up to ~7 minutes.

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube twenty:init production --context=$CTX --domain=crm.luchtech.dev
```

It must report the database as `twenty_crm_luchtech_dev`, Redis DB `7`, and the
bucket `twenty-storage-crm-luchtech-dev`. **If it reports any other Redis DB,
stop**: step 2 did not take, and the new Deployment would run on an empty Redis.

```zsh
lkube get deploy,svc,ingress | grep -E 'twenty|crm'
```

## 7. Re-wire mail

`twenty:init` re-applies the Deployments from the template, which drops the env
`mail:wire` wrote.

```zsh
./larakube mail:wire production --tool=crm --domain=crm.luchtech.dev --context=$CTX

lkube get deploy twenty-crm-luchtech-dev -o jsonpath='{range .spec.template.spec.containers[0].env[*]}{.name}{"\n"}{end}' | grep -E 'EMAIL' | sort
```

Step 0 printed no `EMAIL_*` names: the live Deployment has the SMTP Secret but
not the env that reads it, so CRM cannot send mail today (an earlier re-apply
dropped the wiring and `mail:wire` was never re-run). After this step the
listing must show `EMAIL_DRIVER`, `EMAIL_FROM_ADDRESS`, `EMAIL_SMTP_HOST`,
`EMAIL_SMTP_PASSWORD`, `EMAIL_SMTP_PORT` and `EMAIL_SMTP_USER`.

## 8. Verify before deleting anything

```zsh
curl -s -o /dev/null -w 'crm %{http_code}\n' https://crm.luchtech.dev/healthz
lkube get deploy twenty-crm-luchtech-dev twenty-worker-crm-luchtech-dev
lplex exec -c redis deploy/redis -- redis-cli -n 7 dbsize
```

Then in a browser: log in, open a record, and **open an attachment that was
uploaded before the move**. That last one is the only check that proves the
bucket copy and the database agree. The Redis key count should be close to step
0 (queues keep moving). If any check fails, stop: the old objects are untouched.

The old Ingress still routes the same host to a Service with no endpoints. Remove
it as soon as the new one is serving:

```zsh
lkube delete ingress crm-crm-luchtech-dev
curl -s -o /dev/null -w 'crm %{http_code}\n' https://crm.luchtech.dev/healthz
```

## 9. Remove the old objects

```zsh
lkube delete deploy/crm-twenty-crm-luchtech-dev deploy/crm-twenty-worker-crm-luchtech-dev \
  service/crm-crm-luchtech-dev \
  secret/crm-secrets-crm-luchtech-dev secret/crm-smtp-crm-luchtech-dev \
  job/twenty-bucket-copy --ignore-not-found
```

Then clear the two stale Commons registry rows. Neither holds a Redis index
(step 2 moved that one), and `--force` is needed only because the tool registry
claims the instance. Check first, then evict by exact name, **never the picker**:

```zsh
lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' \
  | jq -c '.tenants | {db:.["crm_twenty_crm_luchtech_dev"], bucket:.["crm-twenty-storage-crm-luchtech-dev"]}'
```

Expect `{"db":{"db":"crm_twenty_crm_luchtech_dev","db_service":"postgres"},"bucket":{"s3_bucket":"crm-twenty-storage-crm-luchtech-dev","s3_service":"seaweedfs"}}`
with **no** `redis_index` in either. Then:

```zsh
./larakube plex:evict production --tenant=crm_twenty_crm_luchtech_dev --context=$CTX --force
./larakube plex:evict production --tenant=crm-twenty-storage-crm-luchtech-dev --context=$CTX --force
```

The first finds no database (it was renamed) and only clears the row. The second
deletes the old bucket, which is safe because step 5 compared the copy. Confirm
the live rows survived and that Redis index 7 is still there:

```zsh
lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -c '.tenants | with_entries(select(.key|test("twenty|crm")))'
lplex exec -c redis deploy/redis -- redis-cli -n 7 dbsize
```

`twenty_crm_luchtech_dev` must hold `db`, `redis_index: 7`, and a bucket row
`twenty-storage-crm-luchtech-dev` must exist. No `crm_*` rows should remain.

## 10. Backup and rotation check

The nightly backup discovers databases at run time, so it picks up the renamed
one. Prove it, and confirm nothing was left dead in OpenBao:

```zsh
./larakube backup:run production --context=$CTX
./larakube secrets:prune production --dry-run --context=$CTX
```

`backup:list` should show a fresh set, and the dry run must say there is nothing
to prune.

## If it goes wrong

Nothing before step 6 destroys anything the old install needs, except the
database rename in step 4, which is reversible:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c 'ALTER DATABASE twenty_crm_luchtech_dev RENAME TO crm_twenty_crm_luchtech_dev;' \
  -c 'ALTER ROLE twenty_crm_luchtech_dev RENAME TO crm_twenty_crm_luchtech_dev;'
lkube scale deploy/crm-twenty-crm-luchtech-dev deploy/crm-twenty-worker-crm-luchtech-dev --replicas=1
```

Scale the new Deployments to 0 first if `twenty:init` already ran, and put the
registry row back from `~/plex-registry.before-crm.json`. Once the new
Deployment has served traffic it writes to the new database and bucket, so a
rollback after that point loses what was written since.

Safe to retry from step 1 as long as step 9 has not run. Before a second attempt
delete the copy Job and the new bucket's contents are reconciled by the next
`rclone sync`, so it needs no cleanup of its own.
