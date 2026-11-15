# Label prefix rollout: luchtech-vps

After the `luchtech.dev/` key change (ADR 0021 amendment), every workload must be re-applied
once. Until it is, label-based discovery (`tool:list`, presence checks, remove, backup) can't
see it. Nothing is recreated and no volume is touched: selectors match only `app:`.

The same re-run also applies the new resource requests and the n8n `2.47.7` image.

## Before
- [ ] Run `./build` and confirm `larakube --version` is the new build.
- [ ] Record what's installed (read-only):
  `kubectl --context=larakube-159.89.205.239 get deploy -A -l larakube.io/managed-by -o custom-columns=NS:.metadata.namespace,NAME:.metadata.name,TOOL:.metadata.labels.larakube\.io/tool`

## Re-run each installed tool
Use each instance's own host. The old `tool` label values on luchtech-vps were category
names; the re-run replaces them with vendor names.

| Live label (old) | Tool | Re-run |
|---|---|---|
| secrets | OpenBao | `openbao:init production --domain=…` |
| webmail | Bulwark | `bulwark:init production --domain=…` |
| chat | Matrix (Synapse, MAS, Element, Coturn, Admin) | `matrix:init production --domain=…` |
| sign | Documenso | `documenso:init production --domain=…` |
| git | Forgejo (+ runner) | `forgejo:init production --domain=…` |
| monitor | Grafana stack | `grafana:init production --domain=…` (keep the same logs/traces choice) |
| dashboard | Headlamp | `headlamp:init production --domain=…` |
| livekit | LiveKit (+ lk-jwt) | `livekit:init production --domain=…` |
| flow | n8n | `n8n:init production --domain=flow.luchtech.dev` |
| drive | oCIS | `ocis:init production --domain=…` |
| notes | Outline | `outline:init production --domain=…` |
| stalwart | Stalwart | `stalwart:init production --domain=…` |
| crm | Twenty | `twenty:init production --domain=…` |
| sso | Zitadel | `zitadel:init production --domain=…` |
| passwords | Vaultwarden | `vaultwarden:init production --domain=…` |
| vpn | NetBird (+ client) | `netbird:init production --domain=…` |
| — | ExternalDNS (each zone group) | re-run its init, so zone discovery finds `luchtech.dev/dns-*` |

- [ ] Re-run any `cluster:grant` access grants, so `cluster:users` lists them again.

## Redeploy each project
Their blueprint Secrets and volumes are found by `luchtech.dev/project`.
- [ ] docs, doctor, portfolio, portal: `cloud:deploy production` from each project.

## After (read-only checks)
- [ ] No workload still carries an old key. Each of these should print nothing:
  - `kubectl get deploy -A -l larakube.io/tool`
  - `kubectl get deploy -A -l larakube-tool`
  - `kubectl get secret -A -l larakube.io/project`
- [ ] Every tool is visible again: `larakube tool:list production` shows the same set as before.
- [ ] `kubectl get deploy -A -L luchtech.dev/tool` shows vendor values (`stalwart`, `forgejo`, …).
- [ ] `larakube backup:run production --dry-run` (if available) still finds every volume.
