# Mail (Stalwart) — live migration onto the canonical naming

Cluster side of the change that moves Mail onto `ToolInstance` names. Context
`larakube-159.89.205.239`, namespace `larakube-shared`, host `send.luchtech.dev`,
instance slug `send-luchtech-dev`. Follows
`plans/active/chat-canonical-naming-live-migration.md` and
`plans/completed/vpn-canonical-naming-live-migration.md`.

**Every tool's outbound mail goes through this server, and it holds your
mailboxes. Read all of it first.**

**Do not run `stalwart:init` or `mail:init` before this runbook.** The code now
deploys the canonical names, so on its own it would start a second Stalwart with an
empty database, and it would not even schedule: the live pod holds host ports
25, 465, 587, 993 and 4190.

**Before step 6, `larakube mail:*` cannot find the old install** (the new code looks
for the canonical labels and names), so `mail:check`, `mail:show` and `mail:test`
report "not installed". Skip them until step 6; the JMAP and `kubectl` checks below
need no CLI. Run `./larakube` from `cli/`, or your installed build if it is current.

## What is at stake

| | |
|---|---|
| Postgres `stalwart` | 16 MB: 35 accounts, 3 domains, 3 DKIM keys, settings, certificates, the queue (0 queued) |
| bucket `stalwart` | 26 MB: every message body and attachment (Stalwart's BlobStore) |
| PVC `stalwart-data` (5Gi) | `etc/config.json` (361 bytes: **the pointer to the database**) and an empty `data/` |
| Secret `mail-secrets-send-luchtech-dev` | the recovery admin, admin password and the automation API key |

The volume is nearly empty, so the mail itself is safe in Postgres and the bucket.
The risk is not data loss; it is that **Stalwart persists the old names in three
places**, and a pod that cannot reach its store does not start:

| where | holds |
|---|---|
| `/etc/stalwart/config.json` on the volume | `DataStore`: database `stalwart`, role `stalwart` |
| the `SearchStore` object, in the store | database `stalwart`, role `stalwart` (the same env var for the password) |
| the `BlobStore` object, in the store | bucket `stalwart` |

A rename cannot touch those from outside. This plan therefore **copies** the
database and the bucket and leaves the old ones intact until the end, repoints
`config.json` on the *new* volume before the new pod starts, and repoints the two
store objects through Stalwart's own API. Until step 8 nothing the old install needs
is changed, so rolling back is a scale-up.

## What moves

| now | canonical |
|---|---|
| `deployment`/`service`/`ingress` `mail-stalwart-send-luchtech-dev` | `stalwart-send-luchtech-dev` |
| `service/mail-stalwart-mail-send-luchtech-dev` | `stalwart-mail-send-luchtech-dev` |
| `secret/mail-secrets-send-luchtech-dev` | `stalwart-secrets-send-luchtech-dev` |
| `secret/stalwart-send-luchtech-dev` (OpenBao-synced, 6 keys) | `stalwart-store-send-luchtech-dev` |
| `secret/mail-sender` · `secret/mail-relay` | `stalwart-sender-` · `stalwart-relay-send-luchtech-dev` |
| `pvc/stalwart-data` | `stalwart-storage-send-luchtech-dev` |
| database + role `stalwart` | `stalwart_send_luchtech_dev` |
| bucket `stalwart` | `stalwart-storage-send-luchtech-dev` |
| Redis index 0 (**no registry row today**) | tenant `stalwart_send_luchtech_dev`, still index 0 |
| `externalsecret` `stalwart-send-luchtech-dev`, static role `stalwart` | `…-store-…-db`, role `stalwart_send_luchtech_dev` |

Also left behind by an older generation, deleted at the end: `deployment/stalwart`
(0 replicas), `service/stalwart`, `service/stalwart-mail`, `ingress/stalwart`,
`secret/mail-secrets`, `secret/stalwart-openbao-auth`.

Downtime: **mail is down from step 3 to step 6**, a few minutes. Other servers
retry delivery for days, so nothing is lost; your own tools' notification mail
fails during the gap. Tell the team.

## Four traps specific to this one

1. **Stalwart will not start if its store is unreachable.** The new pod reads the
   database from `config.json`, so step 4 repoints that file on the new volume
   *before* the pod exists. Skipping it leaves a crash loop.
2. **`SearchStore` shares the password variable with `DataStore`.** After the init
   the new role has its own password, which would be wrong for a `SearchStore`
   still naming the old role, and the pod would fail to connect. Step 1 switches it
   to `Default` (it reuses the DataStore) *before* anything else, which is what
   `mail:init` itself sets in its local bootstrap.
3. **Do not repoint the `BlobStore` early.** The new bucket is only filled in step 5;
   repointing before then makes every message unreadable. Step 7 does it, after.
4. **The hostPorts cannot overlap.** The new pod stays Pending while the old one
   runs, so step 3 stops the old one first and accepts the gap.

Helpers (the `jmap` one uses the automation API key and never prints it):

```zsh
CTX=larakube-159.89.205.239
lmail() { kubectl --context=$CTX -n larakube-shared "$@"; }
lplex() { kubectl --context=$CTX -n larakube-plex "$@"; }

# jmap '<methodCalls json>'  -> prints methodResponses; $1 is the JSON array of calls
jmap() {
  local svc=$1 body=$2 port=$((31800 + RANDOM % 100))
  local key; key=$(lmail get secret $3 -o jsonpath='{.data.api-key}' | base64 -d)
  kubectl --context=$CTX -n larakube-shared port-forward svc/$svc $port:8080 >/dev/null 2>&1 &
  local pf=$!; sleep 4
  curl -s -m 20 -H "Authorization: Bearer $key" -H 'Content-Type: application/json' \
    -X POST http://localhost:$port/jmap \
    -d "{\"using\":[\"urn:ietf:params:jmap:core\",\"urn:stalwart:jmap\"],\"methodCalls\":$body}" | jq -c '.methodResponses'
  kill $pf 2>/dev/null
}

waitjob() {
  local job=$1 timeout=${2:-600} waited=0 cond=''
  while [ "$waited" -lt "$timeout" ]; do
    cond=$(lmail get job/"$job" -o jsonpath='{.status.conditions[?(@.status=="True")].type}' 2>/dev/null)
    case "$cond" in
      *Complete*) echo "✅ $job completed"; return 0 ;;
      *Failed*)   echo "❌ $job FAILED — see: lmail logs job/$job"; return 1 ;;
    esac
    sleep 5; waited=$((waited + 5))
  done
  echo "⏱  $job still running after ${timeout}s"; return 2
}
```

Images: `alpine:3.24.2` and `rclone/rclone:1.75.1` (both current).

## 0. Preflight, and your own copy

Record these; steps 4, 7 and 9 compare against them. (`mail:check` cannot find the
old install yet; run it after step 6 and compare with these.) The live numbers:

```zsh
for q in x:Account x:Domain x:QueuedMessage x:DkimSignature; do
  printf "%s " $q; jmap mail-stalwart-send-luchtech-dev "[[\"$q/query\",{\"filter\":{}},\"c0\"]]" mail-secrets-send-luchtech-dev | jq -c '.[0][1].ids | length'
done
lplex exec deploy/postgres -c postgres -- psql -U postgres -d stalwart -tAc \
  "select count(*) from information_schema.tables where table_schema='public'; select pg_size_pretty(pg_database_size('stalwart'));"
lmail exec deploy/mail-stalwart-send-luchtech-dev -- sh -c 'cat /etc/stalwart/config.json | tr -d "\n"; echo'
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc "show password_encryption;"
```

Expect `35 3 0 3` (accounts, domains, queued, DKIM keys), 27 tables and 16 MB,
`config.json` naming `stalwart` twice, and `scram-sha-256`. The queue **must** be 0;
if it is not, wait for it to drain.

Record the three DKIM DNS records and the MX, which must be identical afterwards,
and capture the persisted store objects (the passwords are not in them):

```zsh
dig +short MX luchtech.dev
dig +short TXT luchtech.dev | grep -i spf
for obj in BlobStore SearchStore DataStore InMemoryStore; do
  printf "%s " $obj; jmap mail-stalwart-send-luchtech-dev "[[\"x:$obj/get\",{\"ids\":[\"singleton\"]},\"c0\"]]" mail-secrets-send-luchtech-dev \
    | jq -c '.[0][1].list[0] | del(.secretKey,.authSecret,.accessKey)'
done | tee ~/stalwart-stores-before.txt
```

Take your own copy of the database and the credentials:

```zsh
STAMP=$(date +%Y%m%d-%H%M%S)
kubectl --context=$CTX -n larakube-plex exec deploy/postgres -c postgres -- pg_dump -U postgres stalwart | gzip > ~/stalwart-db-$STAMP.sql.gz
kubectl --context=$CTX -n larakube-shared get secret mail-secrets-send-luchtech-dev mail-sender mail-relay stalwart-send-luchtech-dev -o yaml > ~/stalwart-secrets-$STAMP.yaml
ls -la ~/stalwart-db-$STAMP.sql.gz ~/stalwart-secrets-$STAMP.yaml
```

Both files must be non-trivial in size. They hold secrets; keep them somewhere safe.

## 1. Switch the SearchStore to Default — while Stalwart is still serving

This removes the second place that names the old database and role. It is what
`mail:init` sets itself when it bootstraps locally ("reusing the Data store"), and it
is reversible with the object printed in step 0.

```zsh
jmap mail-stalwart-send-luchtech-dev '[["x:SearchStore/set",{"update":{"singleton":{"@type":"Default"}}},"c0"]]' mail-secrets-send-luchtech-dev
jmap mail-stalwart-send-luchtech-dev '[["x:SearchStore/get",{"ids":["singleton"]},"c0"]]' mail-secrets-send-luchtech-dev | jq -c '.[0][1].list[0]'
lmail rollout restart deploy/mail-stalwart-send-luchtech-dev && lmail rollout status deploy/mail-stalwart-send-luchtech-dev --timeout=180s
```

The response must say `updated` and the `get` must show `"@type":"Default"`. Then
prove search still works: in the Bulwark webmail (or any IMAP client), **search for a
word that appears in an old message** and confirm it is found. If search is empty,
restore the previous object from `~/stalwart-stores-before.txt` before going on.

To revert (only if search broke):

```zsh
jmap mail-stalwart-send-luchtech-dev '[["x:SearchStore/set",{"update":{"singleton":{"@type":"PostgreSql","host":"postgres.larakube-plex.svc.cluster.local","port":5432,"database":"stalwart","authUsername":"stalwart","authSecret":{"@type":"EnvironmentVariable","variableName":"STALWART_STORE_PASSWORD"}}}},"c0"]]' mail-secrets-send-luchtech-dev
```

## 2. Copy the Secrets, and create the new claim

The credentials Secret first, because it holds the recovery admin and the API key
that every later step authenticates with:

```zsh
for pair in "mail-secrets-send-luchtech-dev:stalwart-secrets-send-luchtech-dev" \
            "stalwart-send-luchtech-dev:stalwart-store-send-luchtech-dev" \
            "mail-sender:stalwart-sender-send-luchtech-dev" \
            "mail-relay:stalwart-relay-send-luchtech-dev"; do
  old="${pair%%:*}"; new="${pair##*:}"
  lmail get secret "$old" -o json \
    | jq --arg n "$new" '.metadata = {name:$n, namespace:"larakube-shared"}' \
    | lmail apply -f -
done

lmail get secret stalwart-secrets-send-luchtech-dev -o jsonpath='{.data}' | jq -r 'keys|join(",")'
lmail get secret stalwart-store-send-luchtech-dev -o jsonpath='{.data}' | jq -r 'keys|join(",")'
```

The first must list `admin-email, admin-password, api-key, recovery-admin`; the second
`STALWART_CLOUDFLARE_TOKEN, STALWART_MAIL_PASSWORD, STALWART_MAIL_SENDER,
STALWART_S3_KEY_ID, STALWART_S3_SECRET_KEY, STALWART_STORE_PASSWORD`. The Cloudflare
token is what renews the TLS certificate, so it must come across.

The claim, exactly as the template writes it (no `storageClassName`, identity labels):

```zsh
SIZE=$(lmail get pvc stalwart-data -o jsonpath='{.spec.resources.requests.storage}')

cat <<YAML | lmail apply -f -
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: stalwart-storage-send-luchtech-dev
  namespace: larakube-shared
  labels:
    larakube.io/managed-by: larakube
    larakube.io/tool: mail
    larakube.io/component: stalwart
    larakube.io/instance: send-luchtech-dev
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: ${SIZE}
YAML
```

## 3. Stop Stalwart

Mail is down from here. Everything that holds a Postgres connection or a host port:

```zsh
lmail scale deploy/mail-stalwart-send-luchtech-dev --replicas=0
until [ -z "$(lmail get pods --no-headers | grep -E '^mail-stalwart-send')" ]; do sleep 3; done
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc "select count(*) from pg_stat_activity where datname='stalwart';"
```

The last command must print `0` (a database cannot be copied while it is in use).

## 4. Copy the database, and repoint `config.json` on the new volume

The new role and the copy. The init sets the real password; this one is only a
placeholder, and the objects are made to belong to the new role so Stalwart can
write to them:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c "CREATE ROLE stalwart_send_luchtech_dev LOGIN PASSWORD 'placeholder-not-used';" \
  -c 'CREATE DATABASE stalwart_send_luchtech_dev OWNER stalwart_send_luchtech_dev TEMPLATE stalwart;'

lplex exec deploy/postgres -c postgres -- psql -U postgres -d stalwart_send_luchtech_dev \
  -c 'REASSIGN OWNED BY stalwart TO stalwart_send_luchtech_dev;'

lplex exec deploy/postgres -c postgres -- psql -U postgres -d stalwart_send_luchtech_dev -tAc \
  "select count(*) from information_schema.tables where table_schema='public'; select count(*) from pg_tables where schemaname='public' and tableowner='stalwart_send_luchtech_dev';"
```

27 tables, **and the same number owned by the new role**. If the second number is
lower, stop. The old database and role are untouched; `stalwart` still owns its own
tables.

Now the volume. This Job copies the old one, repoints `config.json` and checks it:

```zsh
lmail delete job/stalwart-pvc-copy --ignore-not-found --wait=true

cat <<'YAML' | lmail apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: stalwart-pvc-copy
  namespace: larakube-shared
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
              sed -i -E 's/"database": *"stalwart"/"database":"stalwart_send_luchtech_dev"/; s/"authUsername": *"stalwart"/"authUsername":"stalwart_send_luchtech_dev"/' /to/etc/config.json
              chown 2000:2000 /to/etc/config.json
              cat /to/etc/config.json; echo
              grep -q '"database":"stalwart_send_luchtech_dev"' /to/etc/config.json
              grep -q '"authUsername":"stalwart_send_luchtech_dev"' /to/etc/config.json
              ! grep -qE '"(database|authUsername)": *"stalwart"' /to/etc/config.json
              ls -la /to/etc /to
          volumeMounts:
            - { name: from, mountPath: /from, readOnly: true }
            - { name: to,   mountPath: /to }
      volumes:
        - name: from
          persistentVolumeClaim: { claimName: stalwart-data }
        - name: to
          persistentVolumeClaim: { claimName: stalwart-storage-send-luchtech-dev }
YAML

waitjob stalwart-pvc-copy 300
lmail logs job/stalwart-pvc-copy | tail -12
```

`from` equals `to`, the printed `config.json` shows `"database":"stalwart_send_luchtech_dev"`
and `"authUsername":"stalwart_send_luchtech_dev"`, and the file is larger than 361 bytes
(the Job now fails if either name is missing). `config.json` is owned by `2000`.

## 5. Copy the bucket

Stalwart is stopped, so nothing writes while it is copied. The credentials are the
pair the old pod has been using:

```zsh
S3_KEY=$(lmail get secret stalwart-store-send-luchtech-dev -o jsonpath='{.data.STALWART_S3_KEY_ID}' | base64 -d)
S3_SECRET=$(lmail get secret stalwart-store-send-luchtech-dev -o jsonpath='{.data.STALWART_S3_SECRET_KEY}' | base64 -d)
[ -n "$S3_KEY" ] && [ -n "$S3_SECRET" ] && echo "ok: credentials read" || echo "STOP: could not read the S3 credentials"
```

Do not continue unless that prints `ok:`.

```zsh
lmail delete job/stalwart-bucket-copy --ignore-not-found --wait=true

cat <<YAML | lmail apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: stalwart-bucket-copy
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
              rclone mkdir s3:stalwart-storage-send-luchtech-dev
              rclone sync s3:stalwart s3:stalwart-storage-send-luchtech-dev --checksum
              echo "--- counts ---"
              rclone size s3:stalwart
              rclone size s3:stalwart-storage-send-luchtech-dev
YAML

waitjob stalwart-bucket-copy 600
lmail logs job/stalwart-bucket-copy | tail -8
```

The two `rclone size` lines must match in object count and bytes.

## 6. Deploy under the new names

Run the **new** CLI from `cli/` now:

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube stalwart:init production --context=$CTX --domain=send.luchtech.dev
```

It reads the admin email and password back from the copied Secret (the login it prints
must be the one you already use), allocates the database (already there), sets the new
role's real password, registers the OpenBao role and its ExternalSecret, and applies
the manifests. The pod starts against the new database through the repointed
`config.json`.

```zsh
lmail get deploy,svc,ingress,pvc | grep -E 'stalwart'
lmail rollout status deploy/stalwart-send-luchtech-dev --timeout=240s
lmail logs deploy/stalwart-send-luchtech-dev --tail=15 | grep -iE "error|fail|panic|listening|ready" | head
```

If the pod crash-loops with a database or authentication error, stop: check step 4's
`config.json` lines. Remove the old Ingress as soon as the new pod is Ready, since two
Ingresses claim the host:

```zsh
lmail delete ingress mail-stalwart-send-luchtech-dev stalwart --ignore-not-found
curl -s -o /dev/null -w 'send %{http_code}\n' https://send.luchtech.dev/
```

## 7. Repoint the BlobStore, and reserve Redis index 0

Stalwart is up on the new database but its `BlobStore` still names the old bucket,
which still exists, so mail works. Point it at the copy:

```zsh
jmap stalwart-send-luchtech-dev '[["x:BlobStore/set",{"update":{"singleton":{"bucket":"stalwart-storage-send-luchtech-dev"}}},"c0"]]' stalwart-secrets-send-luchtech-dev
jmap stalwart-send-luchtech-dev '[["x:BlobStore/get",{"ids":["singleton"]},"c0"]]' stalwart-secrets-send-luchtech-dev | jq -c '.[0][1].list[0] | del(.secretKey,.accessKey)'
lmail rollout restart deploy/stalwart-send-luchtech-dev && lmail rollout status deploy/stalwart-send-luchtech-dev --timeout=240s
```

The update must report `updated` and the `get` must show `"bucket":"stalwart-storage-send-luchtech-dev"`.
If the response says `notUpdated`, nothing changed and mail still reads the old
bucket; stop and tell me the error.

Stalwart's in-memory store (rate limits, auth bans) already uses Redis index 0 with no
registry row, so a later tool could be handed index 0 and collide. Record it against
the new tenant:

```zsh
lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -c '.tenants["stalwart_send_luchtech_dev"]'
lplex get cm plex-registry -o json \
  | jq '.data["registry.json"] |= (fromjson | .tenants["stalwart_send_luchtech_dev"].redis_index = 0 | tojson)' \
  | lplex apply -f -
lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -c '.tenants["stalwart_send_luchtech_dev"]'
```

The first prints the database row; the last must now also contain `"redis_index":0`.

## 8. Re-point the OpenBao sync, and verify before deleting anything

```zsh
./larakube secrets:init production --context=$CTX
lmail get externalsecret --no-headers | grep -iE 'stalwart'
```

`stalwart-store-send-luchtech-dev-db` must exist and be `SecretSynced`. Prove the
database password works over the network path:

```zsh
PW=$(lmail get secret stalwart-store-send-luchtech-dev -o jsonpath='{.data.STALWART_STORE_PASSWORD}' | base64 -d)
kubectl --context=$CTX -n larakube-plex exec deploy/postgres -c postgres -- env PGPASSWORD="$PW" \
  psql -h postgres.larakube-plex.svc.cluster.local -U stalwart_send_luchtech_dev -d stalwart_send_luchtech_dev -tAc "select 'auth ok'"
```

Now the checks that matter. Run `mail:check` again (new CLI), then compare the counts:

```zsh
cd ~/<your project folder>
~/Codes/Ideas/laravel-k8s/cli/larakube mail:check production --context=$CTX
for q in x:Account x:Domain x:QueuedMessage x:DkimSignature; do
  printf "%s " $q; jmap stalwart-send-luchtech-dev "[[\"$q/query\",{\"filter\":{}},\"c0\"]]" stalwart-secrets-send-luchtech-dev | jq -c '.[0][1].ids | length'
done
dig +short MX luchtech.dev
```

Counts must equal step 0 (`35 3 0 3`), and the MX the same. Then, with people:

1. **Open an old message that has an attachment** in the webmail. This is the only
   check that proves the bucket copy and the `BlobStore` repoint agree.
2. **Send a message to an outside address** (Gmail) and **receive one from outside**,
   and check the received headers show `dkim=pass`.
3. Log in to the admin console and to IMAP (port 993) with a normal account.
4. `larakube mail:test production` from your project folder.
5. Confirm another tool still sends: trigger a password-reset email from one of them.
6. Search in webmail finds an old message (the `Default` SearchStore).

If any check fails, stop: the old database, bucket, claim and Secrets are untouched.

## 9. Remove the old objects

Only after step 8 passed **and** mail has flowed both ways.

```zsh
lmail delete job/stalwart-pvc-copy job/stalwart-bucket-copy --ignore-not-found --wait=true

lmail delete deploy/mail-stalwart-send-luchtech-dev deploy/stalwart --ignore-not-found
lmail delete service/mail-stalwart-send-luchtech-dev service/mail-stalwart-mail-send-luchtech-dev \
  service/stalwart service/stalwart-mail --ignore-not-found
lmail delete externalsecret stalwart-send-luchtech-dev --ignore-not-found
lmail delete vaultdynamicsecret.generators.external-secrets.io stalwart-send-luchtech-dev --ignore-not-found
lmail delete secret mail-secrets mail-secrets-send-luchtech-dev mail-sender mail-relay \
  stalwart-send-luchtech-dev --ignore-not-found

# last, and only once you are sure
lmail delete pvc/stalwart-data
```

`stalwart-openbao-auth` (a lone `token`) is not referenced by any workload; confirm
that, then delete it too:

```zsh
lmail get deploy,cronjob,externalsecret -o json | jq -r '[.items[] | select(tostring | contains("stalwart-openbao-auth")) | .metadata.name] | length'
```

`0`, then `lmail delete secret stalwart-openbao-auth`.

## 10. Clear the old rotation role, the old database and bucket, and the backup

```zsh
./larakube secrets:prune production --dry-run --context=$CTX
```

It must list `stalwart` and nothing that belongs to an installed tool. Then:

```zsh
./larakube secrets:prune production --context=$CTX
```

Now drop the old database, its role and its bucket in one step. The registry row
`stalwart` holds the database and the bucket and no Redis index; `plex:evict` takes a
SQL dump first, drops them and clears the row. By exact name, never the picker:

```zsh
lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -c '.tenants["stalwart"]'
./larakube plex:evict production --tenant=stalwart --context=$CTX --force
```

Expect `{"db":"stalwart","db_service":"postgres","s3_bucket":"stalwart","s3_service":"seaweedfs"}`
with no `redis_index`, and afterwards only `stalwart_send_luchtech_dev` and
`stalwart-storage-send-luchtech-dev` remain. Keep the `stalwart-commons.sql` it
writes for a few days.

Last, the backup:

```zsh
./larakube backup:schedule production --cron="17 3 * * *" --timezone=Asia/Manila --context=$CTX
./larakube backup:run production --context=$CTX
./larakube backup:restore production --dry-run --context=$CTX
```

It must report the same number of volumes as before, now with Stalwart's under its new
name (`/var/lib/stalwart` is archived).

## If it goes wrong

Nothing before step 8 changes anything the old install needs, except the `SearchStore`
switch in step 1 (its revert is printed there):

```zsh
lmail delete deploy/stalwart-send-luchtech-dev --ignore-not-found
lmail scale deploy/mail-stalwart-send-luchtech-dev --replicas=1
```

If the old Ingress was already removed in step 6, re-create it from the previous
commit. The old database, bucket and `config.json` are intact, so the old pod starts
exactly as before. After the new pod has served mail it writes to the new database and
bucket, so a rollback after that point loses what arrived since. If step 7 already
repointed the `BlobStore`, set it back (`"bucket":"stalwart"`) first. The two files from
step 0 are the last resort.

Safe to retry from step 2 as long as step 9 has not run. Before a second attempt:

```zsh
lmail delete job/stalwart-pvc-copy job/stalwart-bucket-copy --ignore-not-found --wait=true
lmail delete deploy/stalwart-send-luchtech-dev --ignore-not-found
lmail delete pvc/stalwart-storage-send-luchtech-dev --ignore-not-found
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c 'DROP DATABASE IF EXISTS stalwart_send_luchtech_dev;' -c 'DROP ROLE IF EXISTS stalwart_send_luchtech_dev;'
```
