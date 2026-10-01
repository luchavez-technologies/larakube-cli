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

Everything else of these two tools is already canonical: Deployments, Services,
Ingresses, Secrets, the Commons databases, Forgejo's S3 buckets, the runner
cache PVC. **No Secret, database or Zitadel project changes in this runbook**,
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

Nothing to do. Neither tool's registry row, Plex tenant, bucket or Secret name
changed, so there is no stale row to drop.

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
