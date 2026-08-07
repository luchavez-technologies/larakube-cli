# Plan: RBAC-scoped teammate access (cluster-native)

**Status:** ✅ BUILT AND IN USE — verified 2026-08-08: `cluster:grant`, `cluster:revoke`, `cluster:users` all ship, and a real teammate ServiceAccount (`larakube-access/eman`) exists on `larakube-159.89.205.239`.

## 🎯 Objective

Give each teammate their own **scoped** kube access — read-only, operate-one-app,
or namespace-admin — via a per-person **kubeconfig** + Kubernetes **RBAC**, with
**no SSH and no OS user on the box**. Works identically on single-node, multi-node,
and managed clusters. Replaces the SSH/OS-user `cloud:configure:users` for cluster
access entirely.

## 🔍 Why the current SSH `teammates` must go

`cloud:configure:users` SSHes into `cloud.ip` and runs `useradd` +
`usermod -aG sudo` + `NOPASSWD:ALL`. That's **passwordless full root on the box**
— and since the admin kubeconfig lives there, effectively cluster-admin — just to
let someone help with one app. No read-only, no per-app scoping, single SSH-able
node only. Cluster access is an RBAC problem, not an OS-user one.

## 🧱 Design — the model

**One identity per person, many namespaces, one kubeconfig.**

Each teammate is a single **ServiceAccount** in a central `larakube-access`
namespace, with one **bound-token Secret → one kubeconfig**. Access to an app is a
**RoleBinding in that app's namespace** pointing at their SA and a built-in
ClusterRole:

```
larakube-access/lloyd  (ServiceAccount + token)        ← his ONE identity / kubeconfig
   ├─ RoleBinding in blue-production    → edit  (built-in)
   └─ RoleBinding in orange-production  → edit  (added later, same identity)
```

**Isolation is automatic.** Access is per-namespace and server-side, so before a
binding exists `kubectl -n orange-production …` → `403`. We never grant
`list namespaces` (cluster-scoped), so a teammate can't even enumerate other apps
— they only know the namespaces you told them about.

**Adding an app never touches their kubeconfig.** Granting `orange` just adds a
RoleBinding; lloyd's token/context is unchanged — he immediately works with
`-n orange-production`. No new file, no re-onboarding, no second context.

### Presets → built-in ClusterRoles (no custom roles to maintain)

Bound **namespaced** via RoleBinding:

- `--read` → **`view`**: get/list/watch; **no** secrets, **no** exec. (Interns.)
- `--edit` → **`edit`** *(DEFAULT)*: full app operation — `pods/exec` (artisan),
  `pods/log`, delete pods, deployments, configmaps; **cannot** manage RBAC or add
  other users. (The typical trusted teammate.)
- `--admin` → **`admin`**: `edit` + manage RBAC *within that namespace* (can grant
  others access to that app). (A co-owner of the app.)

### Upgrade / downgrade = re-grant (upsert)

A RoleBinding's `roleRef` is **immutable**, so changing a role means delete +
recreate the binding. `cluster:grant` therefore behaves as an **upsert**:

- New person → create SA + token + kubeconfig, then the RoleBinding.
- Existing person, new namespace → just add the RoleBinding.
- Existing person, **same** namespace, different role flag → **replace** the
  binding (delete old, create new) → that *is* the upgrade/downgrade.

The token/kubeconfig never changes on a role change — access level flips instantly,
no re-onboarding. Re-running `grant` also re-writes the kubeconfig file (covers a
lost file).

## 🛠 Commands

### Owner (admin context)
```bash
larakube cluster:grant  --name lloyd blue-production            # default: edit
larakube cluster:grant  --name lloyd orange-production          # + orange, same identity
larakube cluster:grant  --name lloyd blue-production --admin    # upgrade lloyd on blue
larakube cluster:grant  --name alex  blue-production --read     # intern, read-only
larakube cluster:users                                          # who has what, where (+ role)
larakube cluster:revoke --name lloyd orange-production          # drop just orange (delete binding)
larakube cluster:revoke --name lloyd                            # off-board entirely (SA+token+all bindings)
```
- `cluster:grant {namespace} {--name=} {--read|--edit|--admin} {--context=}` — upsert
  identity + binding; writes `./<name>.kubeconfig` for hand-off.
- Extend **`cluster:users`** to list human identities (per person: namespaces +
  role), not just the `deployer` deploy SA.
- Extend **`cluster:revoke`** with `--name` (a person) and an optional namespace
  (one app) vs none (full off-board). Label our RoleBindings
  (`larakube.dev/access-user: <name>`) so off-board can find them cluster-wide.

### Teammate (their laptop)
```bash
larakube context:import ./lloyd.kubeconfig    # merge into ~/.kube/config + switch to it
larakube context                               # switch between clusters
larakube context:remove                        # clean up when off-boarded
```
`context:import` belongs in the **context:*** family (managing *your* local
contexts), NOT cluster:* (operating *on* a cluster) — and reuses the safe
KUBECONFIG-merge logic already in `cloud:provision`'s `syncKubeconfig`.

## ♻️ Reuse (most of this is already built)

- `InteractsWithScopedRbac` — SA + bound-token Secret + `mintScopedKubeconfig` +
  `assembleScopedKubeconfig`. The per-person SA is the same machinery as `deployer`,
  just named per-person and in `larakube-access`.
- Presets are **built-in** ClusterRoles — we only generate RoleBindings.
- `cluster:users` / `cluster:revoke` already exist; they grow a `--name` notion.
- Kubeconfig merge for `context:import` already exists in `syncKubeconfig`.

## 🔗 Relationship to SSH `teammates`

SSH `cloud:configure:users` is **deprecated** for cluster access. SSH remains only
for **you** administering the box (`cloud:provision` / `cloud:harden`). Teammate
deploys are registry-based (no SSH sideload), so a *deploy-capable* teammate needs
a registry-configured env. The CI `deployer` identity is separate machine access —
unaffected; don't conflate human and CI access.

## 🚦 Phases

1. [x] **Grant + presets** — `larakube-access` namespace, per-person SA + token,
   RoleBinding generation for `view`/`edit`/`admin`. `cluster:grant` upsert
   (`InteractsWithTeammateRbac`).
2. [x] **Kubeconfig + onboarding** — emits `<name>.kubeconfig`; `context:import` merges.
3. [x] **List + revoke** — `cluster:users` shows a Teammates table; `cluster:revoke
   --name` does per-namespace removal or full off-board.
4. [ ] **Docs + deprecation** — Security docs page for teammate access; deprecate SSH
   `cloud:configure:users` for cluster access; update Blueprint Anatomy.

## ✅ Verification (the blue/orange/lloyd/alex story)

- `cluster:grant --name lloyd blue-production` → lloyd can exec/log/delete in
  `blue-production`, but `kubectl -n orange-production get pods` → `403`, and
  `kubectl get namespaces` → `403` (can't even see orange exists).
- `cluster:grant --name lloyd orange-production` → same kubeconfig now works in
  orange; **no new file**.
- `cluster:grant --name lloyd blue-production --admin` then `--read` → role flips
  on blue with no re-onboarding.
- `cluster:grant --name alex blue-production --read` → alex sees logs/pods but
  `kubectl delete pod` and reading secrets are denied.
- `cluster:revoke --name lloyd orange-production` → blue still works, orange `403`.
- `cluster:revoke --name lloyd` → all access gone; his kubeconfig is inert.

## ⚠️ Risks / open questions

- **Token lifecycle** — bound-token Secrets are long-lived + revocable (delete SA).
  Document rotation (`cluster:revoke --name X` + re-`grant`); client-cert noted as
  an alternative; OIDC as the graduation path for real SSO.
- **`edit` can read secrets** — built-in `edit` allows secret read/write in the
  namespace (fine for "operate the app"); `--read` (`view`) excludes secrets. Call
  this out so people don't give an intern `--edit`.
- **No blueprint schema** — the cluster is the source of truth; `cluster:users`
  reads it live. (Deliberately NOT storing teammates in `.larakube.json` — avoids
  drift. Supersedes the old "schema placement" question.)
- **kubeconfig hand-off is a secret** — guide secure delivery (not committed, not
  pasted in chat).
