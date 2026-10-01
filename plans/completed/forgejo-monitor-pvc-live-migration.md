# Forgejo + Monitor — live migration of the bare PVCs onto the canonical naming

Runbook for the three PVCs these tools kept after their Deployments crossed to
the canonical names. Code side: this change (the blades, `GitForgeTool`,
`MonitorInitCommand`/`MonitorRemoveCommand`, tests). Cluster side: this file.

Follows the shape of `plans/completed/drive-canonical-naming-live-migration.md`
and `plans/completed/vpn-canonical-naming-live-migration.md`; read the "what
went wrong" notes in the VPN one first.

## What moves

```
context     larakube-159.89.205.239     namespace   larakube-shared
```

| tool | now | canonical | size | stakes |
|---|---|---|---|---|
| MONITOR | `pvc/prometheus-storage` | `prometheus-storage-monitor-luchtech-dev` | 2Gi | metrics history, rebuildable |
| MONITOR | `pvc/loki-storage` | `loki-storage-monitor-luchtech-dev` | 10Gi | retained logs, rebuildable |
| GIT | `pvc/forgejo-data` | `forgejo-storage-git-luchtech-dev` | 5Gi | **every repo, LFS pointer, SSH host key** |

Not on this cluster, so nothing to move: `grafana-storage` (Grafana keeps its
database in Commons Postgres) and `tempo-storage` (traces are off). Their
templates are renamed too, so a future `--no-plex` or `--with-traces` install
comes up canonical.

Everything else of these two tools is already canonical, checked against the
live cluster and not just the code: Deployments, Services, Ingresses, Secrets,
the Commons databases (`forgejo_git_luchtech_dev`, `grafana_monitor_luchtech_dev`),
Forgejo's three S3 buckets (the Deployment is configured with the
`-git-luchtech-dev` names), the Zitadel app Secrets (`forgejo-sso-git-luchtech-dev`,
`grafana-sso-monitor-luchtech-dev`) and the runner cache PVC.
**No Secret, database or Zitadel project changes in this runbook**,
so unlike Drive and VPN there is no step that strands SSO grants. The one
thing that can still lose wiring is the re-apply in step 4 (ADR 0018).

Order: **Monitor first** as the pilot (nothing in it is irreplaceable), then
Forgejo. Do not start Forgejo until Monitor's step 5 has passed.

Both PVCs are ReadWriteOnce on `local-path`, so each copy needs its owning pod
stopped. Real downtime: Grafana dashboards lose their metrics source for a few
minutes; Forgejo (pushes, clones, Actions, the registry) is down for the copy.

Helpers:

```zsh
lkube() { kubectl --context=larakube-159.89.205.239 -n larakube-shared "$@"; }

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

`kubectl wait --for=condition=complete` is deliberately not used: a failed Job
never gains that condition, so it would block for the whole timeout.

The image is `alpine:3.24` (current at writing; re-check before running).

## 0. Preflight — both tools

```zsh
lkube get pvc | grep -E 'forgejo|loki|prometheus'
lkube get deploy | grep -E 'forgejo|loki|prometheus|grafana'
```

Record what `{tool}:init` will drop, because it re-applies each Deployment from
its template (ADR 0018). For each of Forgejo and Grafana, note whether SSO and
SMTP are currently wired:

```zsh
for d in forgejo-git-luchtech-dev grafana-monitor-luchtech-dev; do
  echo "== $d"
  lkube get deploy $d -o jsonpath='{range .spec.template.spec.containers[0].env[*]}{.name}{"\n"}{end}' \
    | grep -iE 'oidc|oauth|auth|smtp|mailer' | sort
done
```

Whatever prints here must print again after step 4; if a group is missing
afterwards, run the matching `:wire` (step 4).

Record whether either host is `--vpn-only`, because the init must be given the
flag again or it rewrites the Ingress without the Middleware:

```zsh
lkube get ingress forgejo-git-luchtech-dev grafana-monitor-luchtech-dev \
  -o custom-columns=NAME:.metadata.name,MW:.metadata.annotations.traefik\\.ingress\\.kubernetes\\.io/router\\.middlewares
```

An empty MW means public; anything else means pass `--vpn-only`.

Check the current backup schedule so step 7 can reuse it:

```zsh
kubectl --context=larakube-159.89.205.239 get cronjob -A
```

---

# Part A — Monitor (pilot)

## A1. Stop Prometheus and Loki

```zsh
lkube scale deploy/prometheus-monitor-luchtech-dev deploy/loki-monitor-luchtech-dev --replicas=0
until [ -z "$(lkube get pods --no-headers | grep -E '^(prometheus|loki)-monitor-luchtech-dev-')" ]; do sleep 3; done
```

Promtail is a DaemonSet with no PVC and keeps running; it retries until Loki is
back.

## A2. Create the new claims exactly as the template writes them

No `storageClassName` (the template omits it, and the field is immutable — a
hand-made claim that carries it makes the later apply fail on the PVC alone).
Size is read off the old claim so a past resize carries over:

```zsh
PROM_SIZE=$(lkube get pvc prometheus-storage -o jsonpath='{.spec.resources.requests.storage}')
LOKI_SIZE=$(lkube get pvc loki-storage -o jsonpath='{.spec.resources.requests.storage}')

cat <<YAML | lkube apply -f -
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: prometheus-storage-monitor-luchtech-dev
  namespace: larakube-shared
  labels:
    larakube.io/managed-by: larakube
    larakube.io/tool: monitor
    larakube.io/component: prometheus
    larakube.io/instance: monitor-luchtech-dev
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: ${PROM_SIZE}
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: loki-storage-monitor-luchtech-dev
  namespace: larakube-shared
  labels:
    larakube.io/managed-by: larakube
    larakube.io/tool: monitor
    larakube.io/component: loki
    larakube.io/instance: monitor-luchtech-dev
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: ${LOKI_SIZE}
YAML
```

They stay `Pending` until a pod mounts them (`WaitForFirstConsumer`); that is
the copy Job's job.

## A3. Copy

**Loki is optional.** Retained logs are disposable and were already declared
not worth keeping. To skip the copy, leave out the `loki` volumes and the
`loki` line in the command below; Loki then starts on an empty claim and the
old one is just deleted in step 6. Copying costs one extra minute, so the
default here is to copy.

The Job compares file counts after copying and fails if they differ:

```zsh
lkube delete job/monitor-pvc-copy --ignore-not-found --wait=true

cat <<'YAML' | lkube apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: monitor-pvc-copy
  namespace: larakube-shared
spec:
  backoffLimit: 0
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: copy
          image: alpine:3.24
          command: ["/bin/sh", "-c"]
          args:
            - |
              set -e
              for d in prometheus loki; do
                cp -a /from-$d/. /to-$d/
                a=$(find /from-$d | wc -l); b=$(find /to-$d | wc -l)
                echo "$d: from=$a to=$b ($(du -sk /to-$d | cut -f1) KiB)"
                [ "$a" = "$b" ]
              done
          volumeMounts:
            - { name: from-prometheus, mountPath: /from-prometheus, readOnly: true }
            - { name: to-prometheus,   mountPath: /to-prometheus }
            - { name: from-loki,       mountPath: /from-loki,       readOnly: true }
            - { name: to-loki,         mountPath: /to-loki }
      volumes:
        - name: from-prometheus
          persistentVolumeClaim: { claimName: prometheus-storage }
        - name: to-prometheus
          persistentVolumeClaim: { claimName: prometheus-storage-monitor-luchtech-dev }
        - name: from-loki
          persistentVolumeClaim: { claimName: loki-storage }
        - name: to-loki
          persistentVolumeClaim: { claimName: loki-storage-monitor-luchtech-dev }
YAML

waitjob monitor-pvc-copy 900
lkube logs job/monitor-pvc-copy
```

Both pairs of claims are `local-path` on the same node, so one pod can hold all
four. Expect `from=N to=N` on both lines.

## A4. Deploy under the new claim names

Use the flags that match step 0. Logs are on, traces are off, and the stack
uses Commons Postgres:

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube grafana:init production \
  --context=larakube-159.89.205.239 \
  --domain=monitor.luchtech.dev \
  --with-logs --no-traces
# add --vpn-only if step 0 showed a Middleware on the Grafana Ingress
```

If it asks to remove Loki or Tempo, answer **no** — that prompt means the flags
disagree with what is live.

Re-wire whatever step 0 said was wired on Grafana (the re-apply dropped it):

```zsh
./larakube sso:wire production --tool=monitor --context=larakube-159.89.205.239 --domain=monitor.luchtech.dev
./larakube mail:wire production --tool=monitor --context=larakube-159.89.205.239 --domain=monitor.luchtech.dev
```

## A5. Verify before deleting anything

```zsh
lkube get deploy,pvc | grep -E 'prometheus|loki'
lkube rollout status deploy/prometheus-monitor-luchtech-dev deploy/loki-monitor-luchtech-dev --timeout=180s
lkube get deploy prometheus-monitor-luchtech-dev loki-monitor-luchtech-dev \
  -o jsonpath='{range .items[*]}{.metadata.name}{" -> "}{.spec.template.spec.volumes[*].persistentVolumeClaim.claimName}{"\n"}{end}'
```

Both must name the new claims. Then, in Grafana (monitor.luchtech.dev): open a
dashboard and scroll the time range back **before** step A1 — history older
than the outage is the only proof the copy landed; a live graph proves
nothing. Do the same in Explore with the Loki datasource. If either shows
nothing before the outage, stop: the old claims are untouched, nothing is lost.

---

# Part B — Forgejo

Only after Part A passed.

## B1. Stop Forgejo and its runner

Forgejo is down from here until B4.

```zsh
lkube scale deploy/forgejo-git-luchtech-dev deploy/forgejo-runner-git-luchtech-dev --replicas=0
until [ -z "$(lkube get pods --no-headers | grep -E '^forgejo-')" ]; do sleep 3; done
```

(`forgejo-runner-cache-…` is the runner's PVC, not a pod, so the pattern only
matches pods.) The pod must be gone, not just terminating: Forgejo holds open
git object files and its queue database on this volume.

## B2. Create the new claim

```zsh
GIT_SIZE=$(lkube get pvc forgejo-data -o jsonpath='{.spec.resources.requests.storage}')

cat <<YAML | lkube apply -f -
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: forgejo-storage-git-luchtech-dev
  namespace: larakube-shared
  labels:
    larakube.io/managed-by: larakube
    larakube.io/tool: git
    larakube.io/component: forgejo
    larakube.io/instance: git-luchtech-dev
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: ${GIT_SIZE}
YAML
```

## B3. Copy

`cp -a` keeps ownership, modes, symlinks and timestamps, which git hooks and
the SSH host keys under `/data` depend on. The count check is the gate.

```zsh
lkube delete job/forgejo-pvc-copy --ignore-not-found --wait=true

cat <<'YAML' | lkube apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: forgejo-pvc-copy
  namespace: larakube-shared
spec:
  backoffLimit: 0
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: copy
          image: alpine:3.24
          command: ["/bin/sh", "-c"]
          args:
            - |
              set -e
              cp -a /from/. /to/
              a=$(find /from | wc -l); b=$(find /to | wc -l)
              echo "from=$a to=$b ($(du -sk /to | cut -f1) KiB)"
              [ "$a" = "$b" ]
              ls -la /to | head -20
          volumeMounts:
            - { name: from, mountPath: /from, readOnly: true }
            - { name: to,   mountPath: /to }
      volumes:
        - name: from
          persistentVolumeClaim: { claimName: forgejo-data }
        - name: to
          persistentVolumeClaim: { claimName: forgejo-storage-git-luchtech-dev }
YAML

waitjob forgejo-pvc-copy 1800
lkube logs job/forgejo-pvc-copy | tail -30
```

`from` and `to` must match. The listing should show the same top-level
directories the old volume has (`git`, `gitea` or `forgejo`, `ssh`).

## B4. Deploy under the new claim name

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube forgejo:init production \
  --context=larakube-159.89.205.239 \
  --domain=git.luchtech.dev
# add --vpn-only if step 0 showed a Middleware on the Forgejo Ingress
```

The init reads the existing Secret back by name rather than generating new
credentials; the database and buckets are already canonical and are reused.

Re-wire whatever step 0 said was wired (the re-apply dropped it):

```zsh
./larakube sso:wire production --tool=git --context=larakube-159.89.205.239 --domain=git.luchtech.dev
./larakube mail:wire production --tool=git --context=larakube-159.89.205.239 --domain=git.luchtech.dev
```

Then compare the env names with step 0.

## B5. Verify before deleting anything

```zsh
lkube rollout status deploy/forgejo-git-luchtech-dev --timeout=180s
lkube get deploy forgejo-git-luchtech-dev \
  -o jsonpath='{.spec.template.spec.volumes[*].persistentVolumeClaim.claimName}{"\n"}'
curl -s -o /dev/null -w '%{http_code}\n' https://git.luchtech.dev/
```

The claim name must be `forgejo-storage-git-luchtech-dev`. Then the checks a
200 cannot make:

1. Log in (SSO), open a repository with history, and view a commit that
   predates today. That proves the repository tree came across.
2. `git clone` a repo over SSH **and** HTTPS. SSH proves the host keys under
   `/data` were copied: a different fingerprint than before means they were
   not, and every developer gets a host-key warning.
3. Push a trivial commit.
4. Scale the runner back up and confirm it re-registers and picks up a job:
   `lkube scale deploy/forgejo-runner-git-luchtech-dev --replicas=1`.

If any of 1–3 fails, stop. The old claim is untouched.

---

# Both — finish

## 6. Re-point the nightly backup

The backup CronJob freezes its volume list at schedule time. Use the schedule
recorded in step 0; the values below are what the VPN migration used:

```zsh
./larakube backup:schedule production \
  --cron="17 3 * * *" --timezone=Asia/Manila \
  --context=larakube-159.89.205.239
./larakube backup:run production --context=larakube-159.89.205.239
```

Check the volume count it reports against before the migration. Fewer means
discovery missed the renamed claim and the schedule covers less than it says.
Re-run rather than accepting it. `backup:list` should show a fresh set that
includes the Forgejo volume.

## 7. Delete the old objects

Only after A5 and B5 passed **and** the backup in step 6 shows the Forgejo
volume. Until then the old claims are the rollback.

Delete the copy Jobs first. A Completed Job pod still holds
`kubernetes.io/pvc-protection` on every claim it mounted, so a claim deleted
while one exists sits in `Terminating` with nothing Running to explain it.

```zsh
lkube delete job/monitor-pvc-copy job/forgejo-pvc-copy --ignore-not-found --wait=true

lkube delete pvc/prometheus-storage pvc/loki-storage
# last, once the git clone/push/runner checks above have all passed
lkube delete pvc/forgejo-data
```

`local-path` reclaims on `Delete`. **There is no undo for the last command.**

## 8. Registry

Nothing for these two tools: their own rows, tenants, buckets and Secrets did
not change. The stale rows and old copies left by earlier migrations are
Part C below.

---

# Part C — Leftovers from earlier migrations

Old copies and registry rows that earlier runs deliberately kept as their
rollback. Safe to do now: the tools they belonged to have been serving from
their canonical names for days. Run it after step 7, in this order.

Every deletion below has a check that proves nothing reads the thing first. If
a check does not print what it says it must, **skip that item** and tell me;
none of them is blocking anything.

**Never use the `plex:evict` picker.** Always pass `--tenant=`, and never
`--no-backup`. Evict takes a SQL dump first (written to the current directory,
keep it until Part C is done), drops the database and its login, **flushes the
tenant's Redis index**, deletes its bucket and removes its registry row. Its
guard may refuse an item as "belongs to a tool installed on this cluster";
that is expected for leftovers of a migrated tool, and `--force` is for exactly
this case, but only after the check for that item passed.

```zsh
lplex() { kubectl --context=larakube-159.89.205.239 -n larakube-plex "$@"; }
```

## C0. Do not touch

| row | why |
|---|---|
| `crm_twenty_crm-luchtech-dev` | Looks like a typo of `crm_twenty_crm_luchtech_dev`, **but it holds Redis index 7, which live CRM is using right now** (`REDIS_URL=…/7`, 1,448 keys). Evicting it would flush CRM's cache and sessions. The mismatch is fixed when CRM is migrated. |
| `crm_twenty_crm_luchtech_dev`, `crm-twenty-storage-crm-luchtech-dev` | live CRM |
| Deployment `stalwart` (0 replicas) | still mounts `stalwart-data`, which the live mail server also mounts. Belongs to the Mail migration. |
| `chat_*`, `stalwart`, `vaultwarden`, `zitadel`, `chat-media` | live, unmigrated tools |

## C1. Old Notes copies: database `outline`, Redis index 0, bucket `notes-storage`

Outline now runs on `outline_notes_luchtech_dev`, Redis index 8 and bucket
`notes-storage-notes-luchtech-dev`. Prove it, and prove nothing is connected to
the old ones:

```zsh
# the live Outline must point at the new database, index and bucket
lkube get deploy outline-notes-luchtech-dev -o jsonpath='{range .spec.template.spec.containers[0].env[*]}{.name}={.value}{"\n"}{end}' \
  | grep -E '^(DATABASE_URL|REDIS_URL|AWS_S3_UPLOAD_BUCKET_NAME)=' | sed -E 's#(://[^:]+:)[^@]+@#\1***@#'

# nothing may be connected to the old database
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc \
  "select count(*) from pg_stat_activity where datname='outline';"
```

`DATABASE_URL` must end in `/outline_notes_luchtech_dev`, `REDIS_URL` in `/8`,
the bucket must be `notes-storage-notes-luchtech-dev`, and the count must be `0`.

Then:

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube plex:evict production --tenant=outline \
  --context=larakube-159.89.205.239 --force
./larakube plex:evict production --tenant=notes-storage \
  --context=larakube-159.89.205.239 --force
```

`outline` takes the 13MB database, its role and Redis index 0. Index 0 holds 12
keys, every one with an expiry (average remaining TTL about 13 hours), no
registered tenant owns it, and I could not identify the owner (some are named
after email addresses, which looks like an old Outline cache). The flush
therefore loses at most entries that were going to expire within a day, but if
that is not acceptable, skip the `outline` line and take the database and role
out by hand instead:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c 'DROP DATABASE outline;' -c 'DROP ROLE outline;'
```

(the registry row then stays until the jq edit in the VPN runbook's step 10).
`notes-storage` takes the old 10MB bucket. Then confirm
Outline still works: open `notes.luchtech.dev`, open a document, attach an image.

## C2. Old Sign bucket: `sign-storage-sign-luchtech-dev`

Documenso uses `documenso-storage-sign-luchtech-dev`; the other is the
category-first copy. Prove it:

```zsh
lkube get deploy documenso-sign-luchtech-dev \
  -o jsonpath='{.spec.template.spec.containers[0].env[?(@.name=="NEXT_PRIVATE_UPLOAD_BUCKET")].value}'; echo
```

It must print `documenso-storage-sign-luchtech-dev`. Then:

```zsh
./larakube plex:evict production --tenant=sign-storage-sign-luchtech-dev \
  --context=larakube-159.89.205.239 --force
```

## C3. Registry rows whose resource is already gone

These have no database and no live bucket to harm (evict ignores a missing
bucket, and none carries a Redis index), so this only clears the row. First
confirm each really is bucket-only and that no Deployment names it:

```zsh
for t in forgejo-storage forgejo-packages forgejo-lfs notes-storage-main data-storage data-directus-storage; do
  printf "%-24s " "$t"
  lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -c --arg t "$t" '.tenants[$t]'
  lkube get deploy -o json | jq -r --arg t "$t" '[.items[] | select(.spec.template.spec.containers[0].env // [] | map(.value // "") | any(. == $t))] | length' | sed 's/^/   deployments naming it: /'
done
```

Each row must show only `s3_bucket`/`s3_service` and **0** deployments naming
it. `data-storage` and `data-directus-storage` also have an (empty) bucket in
SeaweedFS; evict removes that too, which is the point. Then:

```zsh
for t in forgejo-storage forgejo-packages forgejo-lfs notes-storage-main data-storage data-directus-storage; do
  ./larakube plex:evict production --tenant=$t --context=larakube-159.89.205.239 --force
done
```

`forgejo-storage` here is the **bare** one. Do not confuse it with
`forgejo-storage-git-luchtech-dev`, which Forgejo is using right now; the
`for` loop above uses exact names, so it can only hit the bare ones. Run the
check in C0/C3 again afterwards; `forgejo-storage-git-luchtech-dev` must still be
listed.

## C4. Roles with no tool and no objects

`penpot_design-luchtech-dev`, `windmill_admin`, `windmill_user`: neither Design
nor Windmill is installed, there is no database for them, and each owns 0
relations. Confirm they own nothing at all, then drop them. `DROP ROLE` itself
refuses if anything still depends on the role, so a wrong guess fails safe:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres -tAc \
  "select r.rolname, (select count(*) from pg_shdepend d where d.refobjid=r.oid) from pg_roles r where r.rolname in ('penpot_design-luchtech-dev','windmill_admin','windmill_user');"
```

Each count must be `0`. Then:

```zsh
lplex exec deploy/postgres -c postgres -- psql -U postgres \
  -c 'DROP ROLE "penpot_design-luchtech-dev";' -c 'DROP ROLE windmill_admin;' -c 'DROP ROLE windmill_user;'
```

## C5. The PocketBase ghost PVC: `pocketbase-storage-data-luchtech-dev` (2Gi)

PocketBase is not installed and nothing mounts this claim. It may still hold
data, so look first with a read-only Job:

```zsh
lkube get pods -o json | jq -r '.items[] | select(.spec.volumes[]?.persistentVolumeClaim.claimName=="pocketbase-storage-data-luchtech-dev") | .metadata.name'
lkube delete job/pocketbase-peek --ignore-not-found --wait=true
cat <<'YAML' | lkube apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: pocketbase-peek
  namespace: larakube-shared
spec:
  backoffLimit: 0
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: peek
          image: alpine:3.24
          command: ["/bin/sh", "-c", "du -sk /v; find /v | head -30"]
          volumeMounts:
            - { name: v, mountPath: /v, readOnly: true }
      volumes:
        - name: v
          persistentVolumeClaim: { claimName: pocketbase-storage-data-luchtech-dev }
YAML
waitjob pocketbase-peek 120
lkube logs job/pocketbase-peek
```

The first command must print nothing (no pod mounts it). If the listing shows
only empty directories or a few KiB, delete the Job and then the claim. **If it
holds a `data.db` or anything over a few hundred KiB, stop and ask first**:
that is someone's data.

```zsh
lkube delete job/pocketbase-peek --wait=true
lkube delete pvc/pocketbase-storage-data-luchtech-dev
```

## C6. Confirm the registry

```zsh
lplex get cm plex-registry -o jsonpath='{.data.registry\.json}' | jq -r '.tenants | keys[]' | sort
```

It should no longer list `outline`, `notes-storage`, `notes-storage-main`,
`sign-storage-sign-luchtech-dev`, `forgejo-storage`, `forgejo-packages`,
`forgejo-lfs`, `data-storage`, `data-directus-storage`. It **must** still list
`crm_twenty_crm-luchtech-dev`, `crm_twenty_crm_luchtech_dev`,
`forgejo-storage-git-luchtech-dev`, `forgejo-packages-git-luchtech-dev`,
`forgejo-lfs-git-luchtech-dev`, `notes-storage-notes-luchtech-dev`,
`outline_notes_luchtech_dev` and `documenso-storage-sign-luchtech-dev`.

Keep the `*-commons.sql` dumps evict wrote until you have used each tool for a
few days.

## If something goes wrong

Before B4/A4 nothing is destructive: the old claims are untouched and the old
Deployments are only scaled to 0.

```zsh
lkube scale deploy/prometheus-monitor-luchtech-dev deploy/loki-monitor-luchtech-dev --replicas=1
lkube scale deploy/forgejo-git-luchtech-dev deploy/forgejo-runner-git-luchtech-dev --replicas=1
```

After `{tool}:init` ran, the Deployment points at the NEW claim, so scaling up
is no longer a rollback. Do not patch the Deployment by hand: re-run the init
from the previous commit (`git checkout <prior> -- resources app` in a scratch
worktree, then `./larakube …:init` as above), which redeploys onto the old
claim. Anything written since the cutover stays only on the new claim.

### Retrying

Once either tool is serving again it writes to the claim it mounts, so a copy
taken earlier is stale. A retry starts over:

```zsh
lkube delete job/monitor-pvc-copy job/forgejo-pvc-copy --ignore-not-found --wait=true
lkube delete pvc/prometheus-storage-monitor-luchtech-dev pvc/loki-storage-monitor-luchtech-dev \
                 pvc/forgejo-storage-git-luchtech-dev --ignore-not-found
```

then resume at A1 or B1.
