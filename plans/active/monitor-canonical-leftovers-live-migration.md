# Monitor — the in-use bare names, onto the canonical naming

Cluster side of the change that makes the rest of the monitoring stack carry the
instance. Context `larakube-159.89.205.239`, namespace `larakube-shared`, Grafana at
`monitor.luchtech.dev`, instance slug `monitor-luchtech-dev`. Part of
`plans/active/naming-closeout-leftovers.md`; run its steps 1 and 2 first or after,
they are independent.

Prometheus, Loki, Grafana, their claims, Services, Secrets and the Loki, Prometheus
and Promtail ConfigMaps were already canonical. What was still bare, and **in use**:

| now | canonical |
|---|---|
| `deployment` / `service` / `serviceaccount` `kube-state-metrics` | `kube-state-metrics-monitor-luchtech-dev` |
| `serviceaccount/prometheus` | `prometheus-monitor-luchtech-dev` |
| `serviceaccount/promtail` | `promtail-monitor-luchtech-dev` |
| `clusterrole` + `clusterrolebinding` `larakube-prometheus` | `prometheus-role-monitor-luchtech-dev` |
| `clusterrole` + `clusterrolebinding` `larakube-promtail` | `promtail-role-monitor-luchtech-dev` |
| `clusterrole` + `clusterrolebinding` `larakube-kube-state-metrics` | `kube-state-metrics-role-monitor-luchtech-dev` |
| `cm/grafana-datasources` | `grafana-datasources-monitor-luchtech-dev` |
| `cm/grafana-dashboard-provider` | `grafana-dashboard-provider-monitor-luchtech-dev` |
| `cm/grafana-dashboards` | `grafana-dashboards-monitor-luchtech-dev` |

Tempo (not deployed here: traces are off) and its ConfigMap, Service and claim follow
the same rule when it is. No data moves: Prometheus and Loki keep their claims, and
Grafana's database is in Postgres. The cost is **one restart each of Prometheus,
Grafana and Promtail, and a new kube-state-metrics pod** (a few seconds of gap in
the metrics).

One thing is left over on purpose: Grafana keeps a provisioned datasource that
disappears from its file (read-only, in its own database), so the dead **Tempo
datasource** survives the first re-apply. Step 2b removes it: `monitor:init` now lists
the components that are switched off under `deleteDatasources`.

## Helpers

```zsh
CTX=larakube-159.89.205.239
lsh() { kubectl --context=$CTX -n larakube-shared "$@"; }
```

## 0. Baseline (read-only)

```zsh
kubectl --context=$CTX -n larakube-shared port-forward svc/prometheus-monitor-luchtech-dev 31981:9090 >/dev/null 2>&1 &
P1=$!
GP=$(lsh get secret grafana-secrets-monitor-luchtech-dev -o jsonpath='{.data.password}' | base64 -d)
kubectl --context=$CTX -n larakube-shared port-forward svc/grafana-monitor-luchtech-dev 31982:3000 >/dev/null 2>&1 &
P2=$!
sleep 5
curl -s localhost:31981/api/v1/targets | jq -r '.data.activeTargets | group_by(.labels.job) | map({job:.[0].labels.job, up:(map(select(.health=="up"))|length), total:length}) | .[] | "\(.job) \(.up)/\(.total)"'
curl -s localhost:31982/api/health | jq -c .
curl -s -u admin:$GP localhost:31982/api/datasources | jq -r '.[] | "\(.name) \(.type)"'
curl -s -u admin:$GP "localhost:31982/api/search?type=dash-db" | jq length
curl -s 'localhost:31981/api/v1/query?query=count(kube_pod_info)' | jq -r '.data.result[0].value[1]'
kill $P1 $P2
```

Expect `kube-state-metrics 1/1`, `kubernetes-cadvisor 1/1`, `kubernetes-pods 4/5` (the
fifth is a known down pod target), Grafana `"database":"ok"`, 3 datasources (Loki,
Prometheus, Tempo), **5** dashboards and a `kube_pod_info` count of about 106. Write
the numbers down.

## 1. Re-apply

Run the new CLI from `cli/`. Logs stay on and traces stay off (`--with-logs
--no-traces`); branding is read back from the registry:

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube monitor:init production --context=$CTX --domain=monitor.luchtech.dev --with-logs --no-traces --force
```

It creates the new ServiceAccounts, RBAC, ConfigMaps and kube-state-metrics, and
rolls Prometheus, Grafana and Promtail onto them. Every step waits for its rollout.

```zsh
lsh get deploy,ds,svc,sa,cm --no-headers | grep -E 'prometheus|promtail|kube-state|grafana-(datasources|dashboard)'
lsh get pods --no-headers | grep -E "prometheus|promtail|loki|kube-state|grafana-monitor" | awk '{print $1,$2,$3}'
```

## 2. Verify before deleting anything

Re-run step 0. Expect `kube-state-metrics 1/1`, `kubernetes-cadvisor 1/1`,
`kubernetes-pods 5/6` (one more target: the new kube-state-metrics pod; the one down
target is the same as before), Grafana `"database":"ok"`, **3** datasources (Loki,
Prometheus, and the Tempo leftover), **5** dashboards (Cluster Overview, LaraKube App
Monitor, Loki Logs, Nodes, Pods; unchanged) and a `kube_pod_info` count of about
**double** the baseline (about 212): the old and the new kube-state-metrics both run
until step 3, and each pod is counted by both. Then open Grafana at
`https://monitor.luchtech.dev` through SSO and look at **Cluster Overview** and
**Loki Logs**: both must show recent data.

If a check fails, stop: the old objects are untouched, so nothing has been lost.

## 3. Remove the old objects

Check nothing uses them first; the list must be empty:

```zsh
lsh get pods -o json | jq -r '.items[] | select(.spec.serviceAccountName|IN("prometheus","promtail","kube-state-metrics")) | .metadata.name'
lsh get pods,deploy,ds -o json | jq -r '[.items[] | select([.. | objects | .configMap?.name? // empty] | map(select(IN("grafana-datasources","grafana-dashboards","grafana-dashboard-provider"))) | length > 0) | .metadata.name] | join(",")'
```

Then:

```zsh
lsh delete deploy/kube-state-metrics svc/kube-state-metrics --ignore-not-found
lsh delete sa kube-state-metrics prometheus promtail --ignore-not-found
lsh delete cm grafana-datasources grafana-dashboards grafana-dashboard-provider --ignore-not-found
kubectl --context=$CTX delete clusterrole,clusterrolebinding larakube-prometheus larakube-promtail larakube-kube-state-metrics --ignore-not-found
```

## 4. Drop the Tempo datasource

Needs the CLI built with the `deleteDatasources` change. Re-run the same command; the
datasources ConfigMap changes, and Reloader restarts Grafana:

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube monitor:init production --context=$CTX --domain=monitor.luchtech.dev --with-logs --no-traces --force
```

Re-run step 0 once Grafana is back: **2** datasources (Loki, Prometheus), 5
dashboards, and `kube_pod_info` back near the baseline (about 106, after a minute).

## 5. Sweep

Run the sweep in `plans/active/naming-closeout-leftovers.md` step 3. The only `CHECK`
rows left are the hand-made `grafana-matrix-forwarder` (Deployment and Service) and
`alertbot-credentials`.

## If it goes wrong

Nothing before step 3 deletes anything the old stack uses. To go back, deploy the
previous commit's `monitor:init`, which re-creates the bare objects; the old ones are
still there, so Prometheus, Grafana and Promtail simply roll back onto them.
