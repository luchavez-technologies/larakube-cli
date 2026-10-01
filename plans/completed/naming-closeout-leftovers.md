# Naming close-out — leftovers found by the full sweep of `larakube-159.89.205.239`

Run after OpenBao and Zitadel. A sweep of every `larakube-*` namespace (2026-10-02)
found the tool objects canonical, and these leftovers. Nothing here changes data.

| Found | What it is | Action |
|---|---|---|
| Zitadel project `dashboard-headlamp` | Headlamp's project, named from the old Deployment. `sso:grant --tool=dashboard` looks it up by its canonical name and would create a second, empty project, stranding the grants. | step 1: re-wire (renames in place) |
| `secret/webmail-oidc`, `secret/sso-proxy` (`larakube-shared`) | Nothing mounts or reads either (checked below). Left by older generations. | step 2: delete |
| `cm/loki-config`, `cm/prometheus-config`, `cm/grafana-alerting` (`larakube-shared`) | Nothing mounts any of them; Loki and Prometheus run on `…-monitor-luchtech-dev` copies. | step 2: delete |
| `grafana-matrix-forwarder` (Deployment, Service) and `secret/alertbot-credentials` | **Not created by the CLI**: no label, applied by hand on Aug 27, image `:latest`, no code in the repo names them. | none: yours to keep or retire |
| `cm/grafana-datasources`, `grafana-dashboards`, `grafana-dashboard-provider`, `sa/prometheus` | In use, and the Monitor manifests named them bare (as they did kube-state-metrics, Promtail's ServiceAccount and the RBAC). | fixed in code; live: `monitor-canonical-leftovers-live-migration.md` |
| `eman`, `eman-token` (`larakube-access`), `headless-shell` (`larakube-plex`), `cloudflare-token-luchtech-dev`, `external-dns-luchtech-dev`, ESO, Reloader, `postgres`, `redis`, `seaweedfs` | Cluster plumbing and Plex Commons, not Cluster Tools. | none |
| every `*-db` ExternalSecret and generator | `{secret}-db` is the ADR pattern. | none |

## Helpers

```zsh
CTX=larakube-159.89.205.239
lsso() { kubectl --context=$CTX -n larakube-sso "$@"; }
lshared() { kubectl --context=$CTX -n larakube-shared "$@"; }
```

## 1. Rename Headlamp's Zitadel project in place

Record the id first, so you can prove the project was renamed and not replaced:

```zsh
lsso get secret headlamp-sso-dashboard-luchtech-dev -o jsonpath='{.data.project-id}' | base64 -d; echo
```

It is `387109218164932708` at the time of writing. Then re-wire; `sso:wire` finds the
project through that recorded id and renames it:

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
./larakube sso:wire production --tool=dashboard --context=$CTX
lsso get secret headlamp-sso-dashboard-luchtech-dev -o jsonpath='{.data.project-id}' | base64 -d; echo
```

The id is **the same**, and the project is now `headlamp-dashboard-luchtech-dev`
(check in the Zitadel console under Projects, or re-run the project list from the
Zitadel runbook: 11 projects, no `dashboard-headlamp`). Headlamp restarts once. Log
in to Headlamp through SSO afterwards. If the id changed, stop: grants are on the old
project; tell me before deleting anything.

## 2. Delete what nothing uses

Check first (read-only). Each must print an empty list after "used by:":

```zsh
for n in webmail-oidc sso-proxy; do
  printf "secret %s used by: " $n
  lshared get pods,deploy,sts,ds,cronjob -o json | jq -r --arg n $n '[.items[] | select([.. | objects | .secretName? // .secretKeyRef.name? // .secretRef.name? // empty] | index($n)) | .metadata.name] | join(",")'
done
for n in grafana-alerting loki-config prometheus-config; do
  printf "cm %s used by: " $n
  lshared get pods,deploy,sts,ds,cronjob -o json | jq -r --arg n $n '[.items[] | select([.. | objects | .configMap?.name? // .configMapKeyRef.name? // .configMapRef.name? // empty] | index($n)) | .metadata.name] | join(",")'
done
```

Then:

```zsh
lshared delete secret webmail-oidc sso-proxy --ignore-not-found
lshared delete cm grafana-alerting loki-config prometheus-config --ignore-not-found
```

## 3. Sweep again

Every name should end in a registered instance, apart from the plumbing in the table
above. The `*-db` ExternalSecrets and generators, and the Monitor pieces named in
the note below, are the only expected `CHECK` rows.

```zsh
python3 - <<'PY'
import json,subprocess,re
C='larakube-159.89.205.239'
k=lambda *a: subprocess.run(['kubectl','--context='+C,*a],capture_output=True,text=True).stdout
reg=json.loads(subprocess.run("kubectl --context=%s -n larakube-shared get secret larakube-tools-registry -o jsonpath='{.data.registry\\.json}' | base64 -d"%C,shell=True,capture_output=True,text=True).stdout)
inst={r.get('instance') for r in reg if r.get('instance')}
infra=re.compile(r'^(external-secrets|reloader|traefik|external-dns|kube-state-metrics|plex-|postgres|redis|seaweedfs|larakube-|eso-openbao-token|eman|headless-shell|cloudflare-token|promtail|netbird-client-resolver|sh\.helm|default|kube-root-ca)')
kinds=['deployment','statefulset','daemonset','service','ingress','pvc','configmap','secret','serviceaccount','cronjob','externalsecret','middleware.traefik.io','vaultdynamicsecret.generators.external-secrets.io']
for ns in [n for n in k('get','ns','-o','jsonpath={.items[*].metadata.name}').split() if n.startswith('larakube-')]:
    for kind in kinds:
        try: items=json.loads(k('-n',ns,'get',kind,'-o','json'))['items']
        except Exception: continue
        for it in items:
            n=it['metadata']['name']
            if infra.match(n) or it['metadata'].get('ownerReferences') or n.endswith('-db'): continue
            if not any(n.endswith('-'+i) or n==i for i in inst): print('CHECK',ns,kind.split('.')[0],n)
PY
```

Expected `CHECK` rows after steps 1 and 2 (before the Monitor runbook): the three
`grafana-*` ConfigMaps and `prometheus` (the Monitor note), and the hand-made
`grafana-matrix-forwarder` and `alertbot-credentials`. After the Monitor runbook only
the last two remain.

## Monitor's bare in-use names

Fixed in `monitor:init`'s manifests (`feat(monitor)!`). Run
`monitor-canonical-leftovers-live-migration.md` to move the live objects: one restart
each of Prometheus, Grafana and Promtail, no data moved.
