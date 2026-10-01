# Vaultwarden (Passwords) — live migration onto the canonical naming

Cluster side of the change that moves Vaultwarden onto `ToolInstance` names.
Context `larakube-159.89.205.239`, namespace `larakube-vault`, host
`vault.luchtech.dev`, instance slug `vault-luchtech-dev`. Follows
`plans/completed/drive-canonical-naming-live-migration.md` and
`plans/completed/vpn-canonical-naming-live-migration.md`.

**This is the team's password vault. Read all of it before you start.**

**Do not run `vaultwarden:init` or `passwords:init` before this runbook.** The
code now deploys the canonical names, so on its own it would start a second,
empty Vaultwarden (new Deployment, new volume, new empty database) next to the
live one. Nothing in the old install would be harmed, but it would be confusing
and the new Deployment would claim the host.

## What is at stake

| | |
|---|---|
| Postgres `vaultwarden` | 10 users, 44 items, 3 organizations, 1 attachment |
| PVC `vaultwarden-storage` (2Gi, ReadWriteOnce) | `/data`: `rsa_key.pem`, attachments, sends, icon cache (42 files, 1.1 MB) |
| Zitadel project `vaultwarden-…` | SSO login for every user |
| OpenBao static role `vaultwarden` | rotates the database password |

**The nightly backup does not cover `/data`.** It archives volumes by resolving a
Deployment's name to a component, and the live Deployment is named just
`vaultwarden` with no instance, which matches nothing. Only the database is in
the backup. The attachment, any Sends and the signing key exist only on this one
volume. Step 0 takes your own copy first, and step 10 makes the next backup
include it.

## What moves

| now | canonical |
|---|---|
| `deployment`/`service`/`ingress` `vaultwarden` | `vaultwarden-vault-luchtech-dev` |
| `secret/vault-secrets` | `vaultwarden-secrets-vault-luchtech-dev` |
| `secret/vaultwarden-oidc` · `vaultwarden-smtp` | `vaultwarden-oidc-…` · `vaultwarden-smtp-vault-luchtech-dev` |
| `secret/vaultwarden-admin` | not carried: nothing uses it (checked), deleted in step 9 |
| `pvc/vaultwarden-storage` | `vaultwarden-storage-vault-luchtech-dev` |
| database + role `vaultwarden` | `vaultwarden_vault_luchtech_dev` |
| `externalsecret`/`vaultdynamicsecret` `vault-secrets-db` | `vaultwarden-secrets-vault-luchtech-dev-db` (made by `secrets:wire`) |
| OpenBao static role `vaultwarden` | `vaultwarden_vault_luchtech_dev` (made by `secrets:wire`) |
| `sso-app-passwords` *(in `larakube-sso`)* | `vaultwarden-sso-vault-luchtech-dev` |
| Zitadel project | renamed **in place**, same id |

Downtime: Vaultwarden is down from step 2 to step 5. Clients keep their offline
cache, so people can still read what they have already synced; they just cannot
sync or log in. Tell the team first.

## Four traps specific to this one

1. **Copy the SSO secret first and record the project id.** `sso:wire` renames the
   Zitadel project in place only when it can read the recorded `project-id`.
   Without it, it creates a second empty project and every grant stays on the
   first, while the host keeps returning 200.
2. **Copy `vault-secrets` first.** `vaultwarden:init` reads the admin token back
   *by name*; a missing copy generates a new one.
3. **The old rotation role goes stale at step 3.** Once the role is renamed, OpenBao
   keeps trying to rotate `vaultwarden`, which no longer exists, and logs an error
   every 10 seconds. Steps 7 and 10 end it; do not leave it for later.
4. **The ReadWriteOnce volume needs the old pod stopped** before the copy Job can
   mount it.

Helpers:

```zsh
CTX=larakube-159.89.205.239
lvault() { kubectl --context=$CTX -n larakube-vault "$@"; }
lsso()   { kubectl --context=$CTX -n larakube-sso "$@"; }
lplex()  { kubectl --context=$CTX -n larakube-plex "$@"; }

waitjob() {
  local job=$1 timeout=${2:-600} waited=0 cond=''
  while [ "$waited" -lt "$timeout" ]; do
    cond=$(lvault get job/"$job" -o jsonpath='{.status.conditions[?(@.status=="True")].type}' 2>/dev/null)
    case "$cond" in
      *Complete*) echo "✅ $job completed"; return 0 ;;
      *Failed*)   echo "❌ $job FAILED — see: lvault logs job/$job"; return 1 ;;
    esac
    sleep 5; waited=$((waited + 5))
  done
  echo "⏱  $job still running after ${timeout}s"; return 2
}
```

Image: `alpine:3.24.2` (current).

## 0. Preflight, and your own copy of the data

Record these; steps 3 and 8 compare against them.

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres -d vaultwarden -tAc \
  "select 'users='||count(*) from users; select 'ciphers='||count(*) from ciphers; select 'attachments='||count(*) from attachments; select 'sends='||count(*) from sends; select 'orgs='||count(*) from organizations;"
lvault exec deploy/vaultwarden -- sh -c 'find /data | wc -l; du -sk /data'
lsso get secret sso-app-passwords -o jsonpath='{.data.project-id}' | base64 -d; echo
```

Expect `users=10 ciphers=44 attachments=1 sends=0 orgs=3`, 42 files and a project
id (`387109467877015652` when this was written). Keep the terminal open.

Is the host `--vpn-only`? Empty means public; anything else means pass
`--vpn-only` to the init:

```zsh
lvault get ingress vaultwarden -o jsonpath='{.metadata.annotations.traefik\.ingress\.kubernetes\.io/router\.middlewares}'; echo "(end)"
lvault get deploy vaultwarden -o jsonpath='{range .spec.template.spec.containers[0].env[*]}{.name}{"\n"}{end}' | grep -E '^(SSO|SMTP)' | sort
```

The second command lists the SSO and SMTP env the re-apply will drop; step 6
must restore all of it.

Check the role is SCRAM, so the rename keeps its password:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc "show password_encryption;"
```

Then take your own copy, because no backup covers `/data`. Store both files
somewhere safe; they hold the vault's encrypted contents:

```zsh
STAMP=$(date +%Y%m%d-%H%M%S)
kubectl --context=$CTX -n larakube-vault exec deploy/vaultwarden -- tar czf - -C / data > ~/vaultwarden-data-$STAMP.tar.gz
kubectl --context=$CTX -n larakube-plex exec deploy/postgres -c postgres -- pg_dump -U postgres vaultwarden | gzip > ~/vaultwarden-db-$STAMP.sql.gz
ls -la ~/vaultwarden-*-$STAMP.*
tar tzf ~/vaultwarden-data-$STAMP.tar.gz | wc -l
```

The listing must show both files with a non-trivial size, and the `tar tzf`
count should be close to 42 (it counts the `data/` entries). If either file is
empty, stop.

## 1. Copy the Secrets — before anything else

The Zitadel app secret first, because it is the one that strands grants:

```zsh
lsso get secret sso-app-passwords -o json \
  | jq '.metadata = {name:"vaultwarden-sso-vault-luchtech-dev", namespace:"larakube-sso"}' \
  | lsso apply -f -

lsso get secret vaultwarden-sso-vault-luchtech-dev -o jsonpath='{.data.project-id}' | base64 -d; echo
```

That id must equal step 0's. Then the rest:

```zsh
for pair in "vault-secrets:vaultwarden-secrets-vault-luchtech-dev" \
            "vaultwarden-oidc:vaultwarden-oidc-vault-luchtech-dev" \
            "vaultwarden-smtp:vaultwarden-smtp-vault-luchtech-dev"; do
  old="${pair%%:*}"; new="${pair##*:}"
  lvault get secret "$old" -o json \
    | jq --arg n "$new" '.metadata = {name:$n, namespace:"larakube-vault"}' \
    | lvault apply -f -
done

lvault get secret vaultwarden-secrets-vault-luchtech-dev -o jsonpath='{.data}' | jq -r 'keys|join(",")'
```

It must list `VAULTWARDEN_DATABASE_URL, admin-token, plain-token`.

## 2. Stop Vaultwarden

```zsh
lvault scale deploy/vaultwarden --replicas=0
until [ -z "$(lvault get pods --no-headers | grep -E '^vaultwarden-')" ]; do sleep 3; done
```

Vaultwarden is down from here. The pod must be gone, not terminating: it holds
the SQLite-style file locks on `/data` and Postgres connections.

## 3. Rename the database and the role

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc \
  "select count(*) from pg_stat_activity where datname='vaultwarden';"
```

Must be `0`. Then:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c 'ALTER DATABASE vaultwarden RENAME TO vaultwarden_vault_luchtech_dev;' \
  -c 'ALTER ROLE vaultwarden RENAME TO vaultwarden_vault_luchtech_dev;'

lplex exec deploy/postgres -c postgres -- psql -U postgres -d vaultwarden_vault_luchtech_dev -tAc \
  "select 'users='||count(*) from users; select 'ciphers='||count(*) from ciphers; select 'attachments='||count(*) from attachments; select 'orgs='||count(*) from organizations;"
```

The counts must match step 0. From this moment OpenBao's old `vaultwarden`
rotation fails every 10 seconds; steps 7 and 10 end that.

## 4. Copy the data volume

Create the claim exactly as the template writes it (no `storageClassName`, with
the identity labels), sized from the old one:

```zsh
SIZE=$(lvault get pvc vaultwarden-storage -o jsonpath='{.spec.resources.requests.storage}')

cat <<YAML | lvault apply -f -
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: vaultwarden-storage-vault-luchtech-dev
  namespace: larakube-vault
  labels:
    larakube.io/managed-by: larakube
    larakube.io/tool: passwords
    larakube.io/component: vaultwarden
    larakube.io/instance: vault-luchtech-dev
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: ${SIZE}
YAML
```

The Job copies with `cp -a` (ownership, modes, timestamps) and fails unless the
file counts match:

```zsh
lvault delete job/vaultwarden-pvc-copy --ignore-not-found --wait=true

cat <<'YAML' | lvault apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: vaultwarden-pvc-copy
  namespace: larakube-vault
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
              ls -la /to
          volumeMounts:
            - { name: from, mountPath: /from, readOnly: true }
            - { name: to,   mountPath: /to }
      volumes:
        - name: from
          persistentVolumeClaim: { claimName: vaultwarden-storage }
        - name: to
          persistentVolumeClaim: { claimName: vaultwarden-storage-vault-luchtech-dev }
YAML

waitjob vaultwarden-pvc-copy 300
lvault logs job/vaultwarden-pvc-copy | tail -15
```

`from` and `to` must match, and the listing must show `attachments`, `icon_cache`,
`rsa_key.pem`, `sends` and `tmp`.

## 5. Deploy under the new names

Add `--vpn-only` if step 0 showed a Middleware.

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube vaultwarden:init production --context=$CTX --domain=vault.luchtech.dev
```

It must report `https://vault.luchtech.dev` and an admin token **equal to the one
you already use** (it read it from the copied Secret). A different token means
step 1 did not take: stop.

```zsh
lvault get deploy,svc,ingress,pvc | grep -E 'vaultwarden'
```

The old Ingress and the new one both claim the host until step 9; remove the old
one as soon as the new pod is Ready:

```zsh
lvault rollout status deploy/vaultwarden-vault-luchtech-dev --timeout=180s
lvault delete ingress vaultwarden
curl -s -o /dev/null -w 'vault %{http_code}\n' https://vault.luchtech.dev/alive
```

## 6. Re-wire SSO and mail

`vaultwarden:init` re-applies the Deployment from its template, which drops the
env those wrote.

```zsh
./larakube sso:wire production --tool=passwords --domain=vault.luchtech.dev --context=$CTX
./larakube mail:wire production --tool=passwords --domain=vault.luchtech.dev --context=$CTX
```

Then check the project was **renamed, not replaced**:

```zsh
lsso get secret vaultwarden-sso-vault-luchtech-dev -o jsonpath='{.data.project-id}' | base64 -d; echo
```

Same id as step 0. If it changed, stop: the grants are on the old project and the
new one is empty. And compare the env with step 0:

```zsh
lvault get deploy vaultwarden-vault-luchtech-dev -o jsonpath='{range .spec.template.spec.containers[0].env[*]}{.name}{"\n"}{end}' | grep -E '^(SSO|SMTP)' | sort
```

## 7. Hand the database password back to OpenBao

This is the step the migration recipe ends on, and the one whose absence has
broken a tool before. `vaultwarden:init` deliberately does not start rotation;
`secrets:wire` does, and also creates the ExternalSecret that delivers the new
password into the Secret.

```zsh
./larakube secrets:wire production --tool=passwords --domain=vault.luchtech.dev --context=$CTX

lvault get externalsecret --no-headers
lvault rollout status deploy/vaultwarden-vault-luchtech-dev --timeout=180s
```

`vaultwarden-secrets-vault-luchtech-dev-db` must exist and be `SecretSynced`, and
the rollout must finish. Prove the password works over the network path (not
`-h localhost`):

```zsh
URL=$(lvault get secret vaultwarden-secrets-vault-luchtech-dev -o jsonpath='{.data.VAULTWARDEN_DATABASE_URL}' | base64 -d)
kubectl --context=$CTX -n larakube-plex exec deploy/postgres -c postgres -- psql "$URL" -tAc "select 'auth ok'"
```

The URL must use `vaultwarden_vault_luchtech_dev` as both user and database.

## 8. Verify before deleting anything

```zsh
curl -s -o /dev/null -w 'vault %{http_code}\n' https://vault.luchtech.dev/alive
lvault get deploy vaultwarden-vault-luchtech-dev
lplex exec deploy/postgres -c postgres -- psql -U postgres -d vaultwarden_vault_luchtech_dev -tAc \
  "select 'users='||count(*) from users; select 'ciphers='||count(*) from ciphers; select 'attachments='||count(*) from attachments;"
```

Then, in a browser, which are the only checks that prove the data and the volume
agree: log in **through SSO**, open several items and **open one organization
vault**, and **download the attachment**. A Send, if anyone has one, should open.
If any check fails, stop: the old Secret, claim and database copy are untouched
(only the database was renamed, and step "If it goes wrong" undoes that).

## 9. Remove the old objects

Only after step 8 passed **and** someone logged in through SSO.

```zsh
lvault delete job/vaultwarden-pvc-copy --ignore-not-found --wait=true

lvault delete deploy/vaultwarden service/vaultwarden --ignore-not-found
lvault delete externalsecret vault-secrets-db --ignore-not-found
lvault delete vaultdynamicsecret.generators.external-secrets.io vault-secrets-db --ignore-not-found
lvault delete secret vault-secrets vaultwarden-oidc vaultwarden-smtp vaultwarden-admin --ignore-not-found
lsso delete secret sso-app-passwords --ignore-not-found

# last, and only once you are sure
lvault delete pvc/vaultwarden-storage
```

`vaultwarden-admin` is an old orphan (`admin-token`, `database-url`,
`db-password`); a check before this runbook found nothing that references it.
Deleting the claim is permanent (`local-path` reclaims on delete).

## 10. Clear the old rotation role and registry row, and make the vault backed up

OpenBao is still trying to rotate the old `vaultwarden` role. Look first, then
remove it:

```zsh
./larakube secrets:prune production --dry-run --context=$CTX
```

It must list `vaultwarden` and nothing that belongs to an installed tool (the
current role is `vaultwarden_vault_luchtech_dev`). Then:

```zsh
./larakube secrets:prune production --context=$CTX
```

Clear the stale Commons registry row (its database no longer exists, and it holds
no Redis index, so evicting it only clears the row). Check, then evict by exact
name, never the picker:

```zsh
lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -c '.tenants | with_entries(select(.key|test("vault")))'
./larakube plex:evict production --tenant=vaultwarden --context=$CTX --force
```

Expect `vaultwarden` to hold only `db` and `db_service`, and
`vaultwarden_vault_luchtech_dev` to be present afterwards.

The nightly backup freezes its volume list, so re-schedule it, run one, and
confirm the vault's data volume is **in it for the first time**:

```zsh
./larakube backup:schedule production --cron="17 3 * * *" --timezone=Asia/Manila --context=$CTX
./larakube backup:run production --context=$CTX
./larakube backup:restore production --dry-run --context=$CTX
```

The dry run reports the number of volumes; it should now be **8** (it was 7).
Also check the tool registry row got its instance filled in rather than a second
row added:

```zsh
kubectl --context=$CTX -n larakube-shared get secret larakube-tools-registry -o json \
  | jq -r '.data|to_entries[]|.value|@base64d' | jq -c '[.[]? | select(.tool=="vaultwarden" or .tool=="passwords")]'
```

One row, with `"instance":"vault-luchtech-dev"`.

## If it goes wrong

Nothing before step 5 destroys anything the old install needs except the
database rename in step 3, which is reversible:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c 'ALTER DATABASE vaultwarden_vault_luchtech_dev RENAME TO vaultwarden;' \
  -c 'ALTER ROLE vaultwarden_vault_luchtech_dev RENAME TO vaultwarden;'
lvault scale deploy/vaultwarden --replicas=1
```

Scale the new Deployment to 0 first if `vaultwarden:init` already ran, and if the
old Ingress was already deleted, re-create it from the previous commit. After the
new Deployment has served traffic it writes to the new database and volume, so a
rollback loses what was written since. The two files from step 0 are the last
resort.

Safe to retry from step 1 as long as step 9 has not run. Before a second attempt,
delete the copy Job and the new claim:

```zsh
lvault delete job/vaultwarden-pvc-copy --ignore-not-found --wait=true
lvault delete deploy -l larakube.io/tool=passwords --ignore-not-found
lvault delete pvc/vaultwarden-storage-vault-luchtech-dev --ignore-not-found
```
