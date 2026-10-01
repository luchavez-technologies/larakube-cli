# Chat (Matrix) — live migration onto the canonical naming

Cluster side of the change that moves Chat onto `ToolInstance` names. Context
`larakube-159.89.205.239`, namespace `larakube-shared`, host `chat.luchtech.dev`,
instance slug `chat-luchtech-dev`. Follows
`plans/completed/vpn-canonical-naming-live-migration.md` and
`plans/completed/vaultwarden-canonical-naming-live-migration.md`.

**This is the team's chat history and its Element X sessions. Read all of it
first.**

**Do not run `matrix:init` or `chat:init` before this runbook.** The code now
deploys the canonical names, so on its own it would start a second, empty
Synapse (new volume, new empty databases) beside the live one, and a second
`coturn` that cannot bind the host ports the live one holds.

## What is at stake

| | |
|---|---|
| Postgres `chat_matrix` | 320 MB: 17 users, 51 rooms, 68,543 events, 165 local media |
| Postgres `chat_mas` | 22 MB: 17 users, 414 compat sessions, 35 OAuth2 sessions (every Element X login) |
| PVC `chat-synapse-data` (5Gi, ReadWriteOnce) | 87 MB, 4,512 files: **the server's signing keys**, `homeserver.yaml`, `media_store` (45 MB) |
| bucket `chat-media` | 60 MB: media offloaded to SeaweedFS |
| Secret `chat-mas-config-…` / `chat-mas-secrets-…` | MAS's own encryption and signing keys, and the Synapse trust secret |

The volume holds **two** signing keys, `chat.luchtech.dev.signing.key` (the real
one) and `chat.kube.signing.key` (a leftover from an earlier server name). Copy
the whole volume and compare checksums; the nightly backup only archives the
first.

Unlike the Vaultwarden and VPN runs, **no SSO grant can be stranded**: Chat's two
Zitadel apps (Synapse's, now inert, and MAS's) both live in the shared "LaraKube
Shared Tools" project, not in a project named after the Deployment.

## What moves

| now | canonical |
|---|---|
| `deployment`/`service` `chat-synapse` · host `ingress/chat-ingress` | `synapse-chat-luchtech-dev` |
| `chat-web-chat-luchtech-dev` | `element-web-chat-luchtech-dev` |
| `chat-admin-chat-luchtech-dev` · `ingress/chat-admin-ingress-…` | `element-admin-chat-luchtech-dev` |
| `chat-coturn-chat-luchtech-dev` | `coturn-chat-luchtech-dev` |
| `chat-mas-chat-luchtech-dev` · `ingress/chat-mas-ingress-…` | `mas-chat-luchtech-dev` |
| `secret/chat-secrets` | `synapse-secrets-chat-luchtech-dev` |
| `chat-smtp` · `chat-meet` · `chat-synapse-config` | `synapse-smtp-` · `synapse-meet-` · `synapse-config-chat-luchtech-dev` |
| `chat-mas-secrets-…` · `chat-mas-config-…` | `mas-secrets-` · `mas-config-chat-luchtech-dev` |
| `chat-coturn-config-chat-luchtech-dev` | `coturn-config-chat-luchtech-dev` |
| `configmap/chat-auth-mode` | `synapse-auth-mode-chat-luchtech-dev` |
| `pvc/chat-synapse-data` | `synapse-storage-chat-luchtech-dev` |
| `cronjob/chat-media-prune` | `synapse-media-prune-chat-luchtech-dev` |
| `middleware/chat-vpn-only` | `synapse-vpn-only-chat-luchtech-dev` |
| database + role `chat_matrix` | `synapse_chat_luchtech_dev` |
| database + role `chat_mas` | `mas_chat_luchtech_dev` |
| bucket `chat-media` | `synapse-media-chat-luchtech-dev` |
| `sso-app-chat-chat-luchtech-dev` · `sso-app-chat-mas-…` *(in `larakube-sso`)* | `synapse-sso-` · `mas-sso-chat-luchtech-dev` |
| OpenBao static role `chat_matrix`, `externalsecret` `chat-secrets` + `chat-secrets-db` | role `synapse_chat_luchtech_dev` (made by `secrets:wire`) |

`chat-oidc` (Synapse's classic OIDC credentials) is **not** carried: the install is
in MAS mode (`chat-auth-mode` says `mas`), `chat:init` ignores a leftover one on
purpose, and nothing reads it.

Downtime: Chat is down from step 3 to step 6. Element Web and Element X keep
their local state and reconnect afterwards; nobody loses a message, they just
cannot send or sync during the window. A call in progress ends. Tell the team.

## Five traps specific to this one

1. **Copy the MAS secrets exactly.** `mas-secrets-…` and `mas-config-…` hold MAS's
   encryption and signing keys. `chat:init` patches an existing config and keeps
   its `secrets:` block, but if the copy is missing it asks a throwaway pod to
   `mas-cli config generate` a fresh one, and every Element X session is dead.
2. **`coturn` binds host ports.** The live `coturn` holds hostPort 3478 and the relay
   range, so a second `coturn` stays Pending until the old one is gone. Step 3
   scales the old one to 0 before anything is applied.
3. **`chat:init` recreates MAS's Zitadel client on every run** (it deletes and
   re-creates the app, keeping the provider id from the SSO Secret). That is
   existing behaviour and MAS's config is re-rendered to match; the provider id
   only survives because step 1 copies the SSO Secret first.
4. **The old rotation role errors the moment the role is renamed.** Steps 8 and 11
   end it; do not leave them for later.
5. **Two ExternalSecrets target the old Secret.** `chat-secrets` and
   `chat-secrets-db` both merge into `chat-secrets`. Step 8 re-points them; until
   then they keep writing to an object that is about to be deleted.

Helpers:

```zsh
CTX=larakube-159.89.205.239
lchat() { kubectl --context=$CTX -n larakube-shared "$@"; }
lsso()  { kubectl --context=$CTX -n larakube-sso "$@"; }
lplex() { kubectl --context=$CTX -n larakube-plex "$@"; }

waitjob() {
  local job=$1 timeout=${2:-600} waited=0 cond=''
  while [ "$waited" -lt "$timeout" ]; do
    cond=$(lchat get job/"$job" -o jsonpath='{.status.conditions[?(@.status=="True")].type}' 2>/dev/null)
    case "$cond" in
      *Complete*) echo "✅ $job completed"; return 0 ;;
      *Failed*)   echo "❌ $job FAILED — see: lchat logs job/$job"; return 1 ;;
    esac
    sleep 5; waited=$((waited + 5))
  done
  echo "⏱  $job still running after ${timeout}s"; return 2
}
```

Images: `alpine:3.24.2` and `rclone/rclone:1.75.1` (both current).

## 0. Preflight, and your own copy of everything

Record these; steps 3, 4 and 9 compare against them.

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres -d chat_matrix -tAc \
  "select 'users='||count(*) from users; select 'rooms='||count(*) from rooms; select 'events='||count(*) from events; select 'local_media='||count(*) from local_media_repository;"
lplex exec deploy/postgres -c postgres -- psql -U postgres -d chat_mas -tAc \
  "select 'users='||count(*) from users; select 'compat_sessions='||count(*) from compat_sessions; select 'oauth2_sessions='||count(*) from oauth2_sessions;"
lchat exec deploy/chat-synapse -c synapse -- sh -c 'find /data | wc -l; du -sk /data; sha256sum /data/*.signing.key'
curl -s -m 10 https://chat.luchtech.dev/_matrix/client/versions | head -c 60; echo
```

Expect `users=17 rooms=51 events=68543 local_media=165`, MAS `users=17
compat_sessions=414 oauth2_sessions=35`, 4,512 files / 87,928 KB, and these two
signing-key checksums, which step 4 must reproduce:

```
1ad2c1bb93e5dd2779f2e6409bd1de1ffb13b69a9cffedc85f105228a28a8a30  chat.kube.signing.key
b4b14a32012c6cc731323436058da0372e3586b672119d70b5b603342efa1dab  chat.luchtech.dev.signing.key
```

Check the roles are SCRAM (the rename then keeps their passwords), and note the
two settings the init must be given again:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc "show password_encryption;"
lchat get cronjob chat-media-prune -o jsonpath='{.spec.schedule} tz={.spec.timeZone}{"\n"}'
lchat get cronjob chat-media-prune -o json | jq -r '.spec.jobTemplate.spec.template.spec.containers[0].command[2]' | grep -o 'update-db [0-9a-z]*'
lchat get ingress chat-ingress -o jsonpath='{.metadata.annotations.traefik\.ingress\.kubernetes\.io/router\.middlewares}'; echo "(end)"
```

`scram-sha-256`; `41 2 * * * tz=Asia/Manila` and `update-db 30d` (the init's
defaults, so no flag is needed); an empty Middleware on the host ingress (public,
so no `--vpn-only`). The admin console is VPN-only regardless.

Then take your own copy. The nightly backup covers the Synapse signing key but not
the other signing key or MAS's config. Both files below hold secrets; keep them
somewhere safe:

```zsh
STAMP=$(date +%Y%m%d-%H%M%S)
kubectl --context=$CTX -n larakube-shared exec deploy/chat-synapse -c synapse -- tar czf - -C / data > ~/chat-data-$STAMP.tar.gz
for db in chat_matrix chat_mas; do
  kubectl --context=$CTX -n larakube-plex exec deploy/postgres -c postgres -- pg_dump -U postgres $db | gzip > ~/chat-$db-$STAMP.sql.gz
done
kubectl --context=$CTX -n larakube-shared get secret chat-mas-config-chat-luchtech-dev chat-mas-secrets-chat-luchtech-dev -o yaml > ~/chat-mas-secrets-$STAMP.yaml
ls -la ~/chat-*-$STAMP.*
tar tzf ~/chat-data-$STAMP.tar.gz | grep -c signing.key
```

All four files must be non-trivial in size, and the last command must print `2`.
If any is empty, stop.

## 1. Copy the Secrets and the auth-mode marker — before anything else

The Zitadel app Secrets first. They carry the MAS provider id the init reads back:

```zsh
for pair in "sso-app-chat-chat-luchtech-dev:synapse-sso-chat-luchtech-dev" \
            "sso-app-chat-mas-chat-luchtech-dev:mas-sso-chat-luchtech-dev"; do
  old="${pair%%:*}"; new="${pair##*:}"
  lsso get secret "$old" -o json \
    | jq --arg n "$new" '.metadata = {name:$n, namespace:"larakube-sso"}' \
    | lsso apply -f -
done

lsso get secret mas-sso-chat-luchtech-dev -o jsonpath='{.data.provider-id}' | base64 -d; echo
```

It must print `01M0R41AN7DQ0Y967SK9WYB8WV`. Then the cluster Secrets:

```zsh
for pair in "chat-secrets:synapse-secrets-chat-luchtech-dev" \
            "chat-smtp:synapse-smtp-chat-luchtech-dev" \
            "chat-meet:synapse-meet-chat-luchtech-dev" \
            "chat-synapse-config:synapse-config-chat-luchtech-dev" \
            "chat-coturn-config-chat-luchtech-dev:coturn-config-chat-luchtech-dev" \
            "chat-mas-secrets-chat-luchtech-dev:mas-secrets-chat-luchtech-dev" \
            "chat-mas-config-chat-luchtech-dev:mas-config-chat-luchtech-dev"; do
  old="${pair%%:*}"; new="${pair##*:}"
  lchat get secret "$old" -o json \
    | jq --arg n "$new" '.metadata = {name:$n, namespace:"larakube-shared"}' \
    | lchat apply -f -
done

lchat get cm chat-auth-mode -o json \
  | jq '.metadata = {name:"synapse-auth-mode-chat-luchtech-dev", namespace:"larakube-shared"}' \
  | lchat apply -f -

lchat get secret synapse-secrets-chat-luchtech-dev -o jsonpath='{.data}' | jq -r 'keys|join(",")'
lchat get cm synapse-auth-mode-chat-luchtech-dev -o jsonpath='{.data.mode}'; echo
```

The credentials Secret must list `CHAT_MATRIX_DB_PASSWORD, db-password,
registration-secret, turn-secret`, and the marker must print `mas`.

## 2. Check what is about to be released

```zsh
lchat get deploy | grep -E '^(chat-|synapse|mas|coturn|element)'
```

Only the five `chat-*` Deployments should exist, and nothing named
`synapse-chat-luchtech-dev`, `mas-chat-luchtech-dev` or the like. If any canonical
Deployment is already there, stop: something already ran.

## 3. Stop Chat

Everything that holds a Postgres connection, the volume or a host port. Chat is
down from here.

```zsh
lchat scale deploy/chat-synapse deploy/chat-mas-chat-luchtech-dev deploy/chat-coturn-chat-luchtech-dev \
  deploy/chat-web-chat-luchtech-dev deploy/chat-admin-chat-luchtech-dev --replicas=0
until [ -z "$(lchat get pods --no-headers | grep -E '^chat-(synapse|mas|coturn|web|admin)')" ]; do sleep 3; done
lchat patch cronjob chat-media-prune -p '{"spec":{"suspend":true}}'
```

The prune CronJob is suspended so it cannot start mid-copy (a running job would
hold the volume). `init` re-creates it, un-suspended, in step 6.

## 4. Rename the databases and roles

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc \
  "select datname, count(*) from pg_stat_activity where datname in ('chat_matrix','chat_mas') group by datname;"
```

Must print **nothing** (no connections). Then:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c 'ALTER DATABASE chat_matrix RENAME TO synapse_chat_luchtech_dev;' \
  -c 'ALTER ROLE chat_matrix RENAME TO synapse_chat_luchtech_dev;' \
  -c 'ALTER DATABASE chat_mas RENAME TO mas_chat_luchtech_dev;' \
  -c 'ALTER ROLE chat_mas RENAME TO mas_chat_luchtech_dev;'

lplex exec deploy/postgres -c postgres -- psql -U postgres -d synapse_chat_luchtech_dev -tAc \
  "select 'users='||count(*) from users; select 'rooms='||count(*) from rooms; select 'events='||count(*) from events;"
lplex exec deploy/postgres -c postgres -- psql -U postgres -d mas_chat_luchtech_dev -tAc \
  "select 'users='||count(*) from users; select 'compat_sessions='||count(*) from compat_sessions;"
```

Counts must match step 0. From now on OpenBao's old `chat_matrix` rotation fails
every 10 seconds; step 8 and 11 end that.

## 5. Copy the data volume and the media bucket

Create the claim exactly as the template writes it (no `storageClassName`, with the
identity labels), sized from the old one:

```zsh
SIZE=$(lchat get pvc chat-synapse-data -o jsonpath='{.spec.resources.requests.storage}')

cat <<YAML | lchat apply -f -
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: synapse-storage-chat-luchtech-dev
  namespace: larakube-shared
  labels:
    larakube.io/managed-by: larakube
    larakube.io/tool: chat
    larakube.io/component: synapse
    larakube.io/instance: chat-luchtech-dev
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: ${SIZE}
YAML
```

The Job copies with `cp -a` (it keeps the `991:991` ownership and the `0640` mode
the signing keys need), fails unless the file counts match, and prints the
checksums:

```zsh
lchat delete job/synapse-pvc-copy --ignore-not-found --wait=true

cat <<'YAML' | lchat apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: synapse-pvc-copy
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
              echo "from=$a to=$b ($(du -sk /to | cut -f1) KiB)"
              [ "$a" = "$b" ]
              sha256sum /to/*.signing.key
              ls -la /to
          volumeMounts:
            - { name: from, mountPath: /from, readOnly: true }
            - { name: to,   mountPath: /to }
      volumes:
        - name: from
          persistentVolumeClaim: { claimName: chat-synapse-data }
        - name: to
          persistentVolumeClaim: { claimName: synapse-storage-chat-luchtech-dev }
YAML

waitjob synapse-pvc-copy 600
lchat logs job/synapse-pvc-copy | tail -16
```

`from=4512 to=4512`, the two checksums **identical to step 0**, ownership `991`.

Now the media bucket. Its S3 credentials are in the live `homeserver.yaml`:

```zsh
S3_KEY=$(lchat get secret synapse-config-chat-luchtech-dev -o jsonpath='{.data.homeserver\.yaml}' | base64 -d | grep -m1 access_key_id | sed -E 's/.*access_key_id: *"?([^"]+)"?.*/\1/')
S3_SECRET=$(lchat get secret synapse-config-chat-luchtech-dev -o jsonpath='{.data.homeserver\.yaml}' | base64 -d | grep -m1 secret_access_key | sed -E 's/.*secret_access_key: *"?([^"]+)"?.*/\1/')
[ -n "$S3_KEY" ] && [ -n "$S3_SECRET" ] && echo "ok: credentials read" || echo "STOP: could not read the S3 credentials"
```

Do not continue unless that prints `ok:`.

```zsh
lchat delete job/synapse-bucket-copy --ignore-not-found --wait=true

cat <<YAML | lchat apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: synapse-bucket-copy
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
              rclone mkdir s3:synapse-media-chat-luchtech-dev
              rclone sync s3:chat-media s3:synapse-media-chat-luchtech-dev --checksum
              echo "--- counts ---"
              rclone size s3:chat-media
              rclone size s3:synapse-media-chat-luchtech-dev
YAML

waitjob synapse-bucket-copy 900
lchat logs job/synapse-bucket-copy | tail -8
```

The two `rclone size` lines must match in object count and bytes.

## 6. Deploy under the new names

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube matrix:init production --context=$CTX --domain=chat.luchtech.dev
```

It prints the database as `synapse_chat_luchtech_dev`, deploys MAS (re-registering
its Zitadel client and re-rendering its config against the renamed database), and
deploys Element Admin. Do **not** let it run a fresh `mas-cli config generate`: if
you see "Generating Matrix Authentication Service config", stop and check step 1.
Synapse and MAS restart on their own when the MAS config changed.

```zsh
lchat get deploy,pvc,cronjob | grep -E 'synapse|mas|coturn|element'
lchat rollout status deploy/synapse-chat-luchtech-dev --timeout=240s
lchat rollout status deploy/mas-chat-luchtech-dev --timeout=180s
```

The old host ingress and the new one both claim `chat.luchtech.dev`, and the old
ones point at Services that no longer exist. Remove them once the new pods are
Ready:

```zsh
lchat delete ingress chat-ingress chat-mas-ingress-chat-luchtech-dev chat-admin-ingress-chat-luchtech-dev --ignore-not-found
curl -s -m 10 https://chat.luchtech.dev/_matrix/client/versions | head -c 60; echo
```

## 7. Check what the re-apply could have dropped

`matrix:init` re-renders `homeserver.yaml`, and re-reads the wiring from the copied
Secrets. Confirm each piece survived:

```zsh
lchat get secret synapse-config-chat-luchtech-dev -o jsonpath='{.data.homeserver\.yaml}' | base64 -d \
  | grep -E "^email:|matrix_authentication_service|extra_well_known_client_content|org.matrix.msc4143|database:|user:|bucket:" | sed -E 's/(password|secret).*/\1: ***/'
lchat get cm synapse-auth-mode-chat-luchtech-dev -o jsonpath='{.data.mode}'; echo
lchat get secret synapse-meet-chat-luchtech-dev -o jsonpath='{.data.jwt-url}' | base64 -d; echo
```

You need: the `email:` block (mail wiring), `matrix_authentication_service`, the
calling `extra_well_known_client_content` block, `user: "synapse_chat_luchtech_dev"`,
`bucket: "synapse-media-chat-luchtech-dev"`, mode `mas`, and the Meet URL. If the
`email:` block is missing, run:

```zsh
./larakube mail:wire production --tool=chat --domain=chat.luchtech.dev --context=$CTX
```

If calling is missing, run `./larakube meet:wire production --tool=chat --context=$CTX`.

## 8. Hand the database password back to OpenBao

`matrix:init` deliberately does not start rotation; `secrets:wire` does, and it also
creates the ExternalSecret that delivers each new password into the Secret.

```zsh
./larakube secrets:wire production --tool=chat --domain=chat.luchtech.dev --context=$CTX
lchat get externalsecret --no-headers | grep -iE 'synapse|chat'
lchat rollout status deploy/synapse-chat-luchtech-dev --timeout=240s
```

`synapse-secrets-chat-luchtech-dev-db` must exist and be `SecretSynced`. Prove the
password works over the network path (not `-h localhost`):

```zsh
PW=$(lchat get secret synapse-secrets-chat-luchtech-dev -o jsonpath='{.data.db-password}' | base64 -d)
kubectl --context=$CTX -n larakube-plex exec deploy/postgres -c postgres -- env PGPASSWORD="$PW" \
  psql -h postgres.larakube-plex.svc.cluster.local -U synapse_chat_luchtech_dev -d synapse_chat_luchtech_dev -tAc "select 'auth ok'"
```

Then re-create the OpenBao KV sync (`CHAT_MATRIX_DB_PASSWORD`) for the new Secret,
and remove the two ExternalSecrets that still merge into the old one:

```zsh
./larakube secrets:init production --context=$CTX
lchat delete externalsecret chat-secrets chat-secrets-db --ignore-not-found
lchat delete vaultdynamicsecret.generators.external-secrets.io chat-secrets-db --ignore-not-found
```

## 9. Verify before deleting anything

```zsh
curl -s -m 10 https://chat.luchtech.dev/_matrix/client/versions | head -c 60; echo
curl -s -m 10 https://chat.luchtech.dev/.well-known/matrix/client | head -c 300; echo
lchat get deploy | grep -E '^(synapse|mas|coturn|element)'
lchat exec deploy/synapse-chat-luchtech-dev -c synapse -- sh -c 'find /data | wc -l; sha256sum /data/*.signing.key'
lplex exec deploy/postgres -c postgres -- psql -U postgres -d synapse_chat_luchtech_dev -tAc \
  "select 'users='||count(*) from users; select 'rooms='||count(*) from rooms; select 'events='||count(*) from events;"
lplex exec deploy/postgres -c postgres -- psql -U postgres -d mas_chat_luchtech_dev -tAc \
  "select 'compat_sessions='||count(*) from compat_sessions; select 'oauth2_sessions='||count(*) from oauth2_sessions;"
```

The signing-key checksums must equal step 0, and the counts must equal or exceed
step 0 (events keep arriving). Then, with people, which are the only checks that
prove the data, the keys and the sessions agree:

1. **Element Web:** sign in through SSO, open an old room and **scroll back to old
   messages**, and **open an image or file sent before today** (that one proves the
   bucket copy and the volume agree).
2. **Element X on a phone that was already signed in:** it must reconnect **without
   asking you to sign in again**. If it asks, MAS's keys did not carry over: stop.
3. Send a message each way, and place a call (it exercises `coturn` and LiveKit).
4. Open `https://admin.chat.luchtech.dev` over the VPN.
5. The Element Web page still shows your branding (the registry's `LuchTech Chat`),
   not the default `Matrix`; the init reads it back unless you pass `--app-name`.

If any check fails, stop: the old Secrets, claim and bucket are untouched, and only
the database rename needs undoing.

## 10. Remove the old objects

Only after step 9 passed **and** Element X reconnected on an existing session.

```zsh
lchat delete job/synapse-pvc-copy job/synapse-bucket-copy --ignore-not-found --wait=true

lchat delete deploy/chat-synapse deploy/chat-mas-chat-luchtech-dev deploy/chat-coturn-chat-luchtech-dev \
  deploy/chat-web-chat-luchtech-dev deploy/chat-admin-chat-luchtech-dev --ignore-not-found
lchat delete service/chat-synapse service/chat-mas-chat-luchtech-dev service/chat-coturn-chat-luchtech-dev \
  service/chat-web-chat-luchtech-dev service/chat-admin-chat-luchtech-dev --ignore-not-found
lchat delete cronjob/chat-media-prune --ignore-not-found
lchat delete secret chat-secrets chat-smtp chat-oidc chat-meet chat-synapse-config \
  chat-coturn-config chat-coturn-config-chat-luchtech-dev \
  chat-mas-secrets-chat-luchtech-dev chat-mas-config-chat-luchtech-dev --ignore-not-found
lchat delete cm chat-auth-mode chat-web-config-chat-luchtech-dev chat-cinny-config --ignore-not-found
lchat delete middleware.traefik.io chat-vpn-only --ignore-not-found
lsso delete secret sso-app-chat-chat-luchtech-dev sso-app-chat-mas-chat-luchtech-dev --ignore-not-found

# last, and only once you are sure
lchat delete pvc/chat-synapse-data
```

`chat-oidc`, `chat-cinny-config` and the bare `chat-coturn-config` are older
leftovers nothing reads. Deleting the claim is permanent (`local-path` reclaims on
delete).

## 11. Clear the old rotation role and the registry rows

```zsh
./larakube secrets:prune production --dry-run --context=$CTX
```

It must list `chat_matrix` and nothing that belongs to an installed tool. Then:

```zsh
./larakube secrets:prune production --context=$CTX
```

Clear the three stale Commons rows. None holds a Redis index. Check, then evict by
exact name, never the picker; the two database rows only clear (their databases
were renamed), and `chat-media` deletes the old bucket, which step 5 compared:

```zsh
lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -c '.tenants | with_entries(select(.key|test("chat|synapse|mas")))'
./larakube plex:evict production --tenant=chat_matrix --context=$CTX --force
./larakube plex:evict production --tenant=chat_mas --context=$CTX --force
./larakube plex:evict production --tenant=chat-media --context=$CTX --force
```

Expect the new rows `synapse_chat_luchtech_dev`, `mas_chat_luchtech_dev` and
`synapse-media-chat-luchtech-dev`, and none named `chat*`. An empty bucket
`chat-storage` (0 bytes) is also left over from an earlier layout and has no
registry row; nothing uses it.

## 12. Backup

The nightly backup freezes its volume list, so re-schedule it, run one, and confirm
the new volume is in it:

```zsh
./larakube backup:schedule production --cron="17 3 * * *" --timezone=Asia/Manila --context=$CTX
./larakube backup:run production --context=$CTX
./larakube backup:restore production --dry-run --context=$CTX
```

It must still report the same number of volumes as before, now with the Synapse
one under its new name. (It archives only `chat.luchtech.dev.signing.key`; the
second key is a leftover.)

## If it goes wrong

Nothing before step 6 destroys anything the old install needs, except the database
renames in step 4, which are reversible:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c 'ALTER DATABASE synapse_chat_luchtech_dev RENAME TO chat_matrix;' \
  -c 'ALTER ROLE synapse_chat_luchtech_dev RENAME TO chat_matrix;' \
  -c 'ALTER DATABASE mas_chat_luchtech_dev RENAME TO chat_mas;' \
  -c 'ALTER ROLE mas_chat_luchtech_dev RENAME TO chat_mas;'
lchat patch cronjob chat-media-prune -p '{"spec":{"suspend":false}}'
lchat scale deploy/chat-synapse deploy/chat-mas-chat-luchtech-dev deploy/chat-coturn-chat-luchtech-dev \
  deploy/chat-web-chat-luchtech-dev deploy/chat-admin-chat-luchtech-dev --replicas=1
```

Scale the new Deployments to 0 first if `matrix:init` already ran, re-create the
old Ingresses from the previous commit if step 6 removed them, and note that
`matrix:init` re-created MAS's Zitadel client (its credentials changed; the old
`sso-app-chat-mas-…` Secret still holds the previous ones and MAS's old config
still names them). After the new Deployment has served traffic it writes to the new
database and volume, so a rollback loses what was written since. The four files from
step 0 are the last resort.

Safe to retry from step 1 as long as step 10 has not run. Before a second attempt:

```zsh
lchat delete job/synapse-pvc-copy job/synapse-bucket-copy --ignore-not-found --wait=true
lchat delete deploy -l larakube.io/tool=chat --ignore-not-found
lchat delete pvc/synapse-storage-chat-luchtech-dev --ignore-not-found
```
