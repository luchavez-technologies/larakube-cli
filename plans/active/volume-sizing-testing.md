# Test Plan: Volume sizing (ADR 0023) — `storage:resize` and the live-size floor

**Status:** ⛔ NOT STARTED

Covers:

1. **`storage:resize <environment> --pvc= --size=`** — grows one claim in place; refuses on a
   non-expandable StorageClass, a shrink, a bad size, or an unknown claim; never scales or
   restarts a workload.
2. **`$volumeSize()` in every cluster-tool PVC template** — renders the larger of the live
   request and the template default, so a grown volume survives the next `{tool}:init`.
3. **The growth-volume warning** when a tool claims a growth volume on a StorageClass that
   cannot expand.

Ordered by blast radius. Everything runs on **OrbStack only**.

## Ground rules

- Run from `cli/` with `./larakube`, which runs the current source. The installed binary does
  not have `storage:resize` until `./build`.
- Pass **`--context=orbstack` on every command**. The ambient kube-context is not a safe default
  on this machine — it can point at production.
- **Never run `storage:resize` without `--pvc`.** Its picker lists every claim on the cluster,
  real ones included.
- Everything this plan creates is named `resize-fixture*`, and cleanup deletes only those names.
  OrbStack's `larakube-shared` holds real PocketBase instances — no step names them.

---

## Phase 0 — Pre-flight (read-only)

- [ ] Fixture names are unused. Each of these must return `NotFound` / no output:
  ```bash
  kubectl --context orbstack get namespace resize-fixture
  kubectl --context orbstack get storageclass larakube-resize-fixture
  kubectl --context orbstack get pvc,deploy -n larakube-shared -o name | grep resize
  ```
  If any of them exists, **stop** — don't reuse it.
- [ ] Record the real PocketBase claims, to compare after cleanup:
  ```bash
  kubectl --context orbstack get pvc -n larakube-shared -o name | grep pocketbase
  ```

## Phase A — Automated (no cluster)

- [ ] `./vendor/bin/pest tests/Unit/VolumeSizingTest.php tests/Feature/StorageResizeCommandTest.php tests/Unit/PocketBaseManifestYamlTest.php`
- [ ] `./vendor/bin/pest --parallel` — full suite green.

## Phase B — Create the fixture

One namespace, one expandable StorageClass, two 1Gi claims — `resize-fixture-fixed` on OrbStack's
default `local-path` (cannot expand) and `resize-fixture-expandable` on the fixture class — and a
Deployment that mounts both. The Deployment matters: `local-path` binds on first consumer, and
Kubernetes only accepts a resize on a **Bound** claim.

- [ ] Apply:
  ```bash
  kubectl --context orbstack apply -f - <<'EOF'
  apiVersion: v1
  kind: Namespace
  metadata:
    name: resize-fixture
  ---
  apiVersion: storage.k8s.io/v1
  kind: StorageClass
  metadata:
    name: larakube-resize-fixture
  provisioner: rancher.io/local-path
  allowVolumeExpansion: true
  reclaimPolicy: Delete
  volumeBindingMode: WaitForFirstConsumer
  ---
  apiVersion: v1
  kind: PersistentVolumeClaim
  metadata:
    name: resize-fixture-fixed
    namespace: resize-fixture
  spec:
    accessModes: [ReadWriteOnce]
    resources:
      requests:
        storage: 1Gi
  ---
  apiVersion: v1
  kind: PersistentVolumeClaim
  metadata:
    name: resize-fixture-expandable
    namespace: resize-fixture
  spec:
    storageClassName: larakube-resize-fixture
    accessModes: [ReadWriteOnce]
    resources:
      requests:
        storage: 1Gi
  ---
  apiVersion: apps/v1
  kind: Deployment
  metadata:
    name: resize-fixture
    namespace: resize-fixture
  spec:
    replicas: 1
    selector:
      matchLabels: {app: resize-fixture}
    template:
      metadata:
        labels: {app: resize-fixture}
      spec:
        containers:
          - name: hold
            image: busybox:stable
            command: ["sh", "-c", "while true; do sleep 3600; done"]
            volumeMounts:
              - {name: fixed, mountPath: /fixed}
              - {name: expandable, mountPath: /expandable}
        volumes:
          - name: fixed
            persistentVolumeClaim: {claimName: resize-fixture-fixed}
          - name: expandable
            persistentVolumeClaim: {claimName: resize-fixture-expandable}
  EOF
  ```
- [ ] `kubectl --context orbstack rollout status deploy/resize-fixture -n resize-fixture` succeeds.
- [ ] `kubectl --context orbstack get pvc -n resize-fixture` shows both claims **Bound**.

## Phase C — Refusal paths (nothing is patched)

Each must exit `1` (`echo $?`).

- [ ] **Non-expandable class:**
  `./larakube storage:resize local --context=orbstack --namespace=resize-fixture --pvc=resize-fixture-fixed --size=2Gi --force`
  → "StorageClass 'local-path' does not support volume expansion", followed by the hostPath
  explanation and the pointer to an expandable class.
- [ ] …and the claim is untouched:
  `kubectl --context orbstack get pvc resize-fixture-fixed -n resize-fixture -o jsonpath='{.spec.resources.requests.storage}'` → `1Gi`.
- [ ] **Shrink:** same command against `--pvc=resize-fixture-expandable --size=500Mi` → "A volume can only grow".
- [ ] **Bad size:** `--pvc=resize-fixture-expandable --size=banana` → "Pass a valid --size".
- [ ] **Unknown claim**, without `--namespace`: `--pvc=resize-fixture-does-not-exist --size=2Gi` → "No PersistentVolumeClaim named 'resize-fixture-does-not-exist' found".

## Phase D — Grow in place

- [ ] Record the pod name: `kubectl --context orbstack get pods -n resize-fixture -o name`
- [ ] `./larakube storage:resize local --context=orbstack --namespace=resize-fixture --pvc=resize-fixture-expandable --size=2Gi --force`
  → exit `0`. Output shows `larakube-resize-fixture (expandable)`, `Mounted by: resize-fixture`,
  and "✅ 'resize-fixture-expandable' now requests 2Gi."
- [ ] `kubectl --context orbstack get pvc resize-fixture-expandable -n resize-fixture -o jsonpath='{.spec.resources.requests.storage}'` → `2Gi`.
- [ ] **No workload was touched:** the pod name is identical to the one recorded, and the
  Deployment still reads `1/1`.
- [ ] **Namespace discovery:** repeat with `--size=3Gi` and **no** `--namespace` → succeeds, request reads `3Gi`.
- [ ] **Expected, not a failure:** `status.capacity` stays `1Gi`. `local-path` has no resizer — the
  StorageClass flag is what lets the API accept the larger request. This phase proves the CLI's
  patch path and the API contract. It does **not** prove bytes grew; that needs a real CSI class
  such as `do-block-storage`.

## Phase E — A grown volume survives `{tool}:init`

The ADR 0023 invariant, through a real tool, on a disposable Data instance at `resize-fixture.test`.

A claim can be **created** at any size on any class — only *growing* one needs expansion — so
pre-create the fixture instance's claim larger than PocketBase's `2Gi` template default. That is
exactly the state a `storage:resize` leaves behind.

- [ ] Pre-create the claim at `5Gi`:
  ```bash
  kubectl --context orbstack apply -f - <<'EOF'
  apiVersion: v1
  kind: PersistentVolumeClaim
  metadata:
    name: data-pocketbase-pvc-resize-fixture-test
    namespace: larakube-shared
  spec:
    accessModes: [ReadWriteOnce]
    resources:
      requests:
        storage: 5Gi
  EOF
  ```
- [ ] Deploy the instance:
  `./larakube data:init local --context=orbstack --engine=pocketbase --domain=resize-fixture.test --admin-email=admin@example.com --force`
  - Prints the growth-volume warning (OrbStack's default class cannot expand).
  - Ends with "✅ PocketBase Data / Headless CMS stack is live." and the summary line
    `PVC data-pocketbase-pvc-resize-fixture-test`. **If the PVC named there is different, stop** —
    the fixture didn't attach; use the printed name in Phase F.
- [ ] The request was kept, not reverted:
  `kubectl --context orbstack get pvc data-pocketbase-pvc-resize-fixture-test -n larakube-shared -o jsonpath='{.spec.resources.requests.storage}'` → `5Gi`, not `2Gi`.
- [ ] **Negative control** — what every `{tool}:init` would have sent without the floor. Server-side
  dry run, persists nothing:
  ```bash
  kubectl --context orbstack apply --dry-run=server -f - <<'EOF'
  apiVersion: v1
  kind: PersistentVolumeClaim
  metadata:
    name: data-pocketbase-pvc-resize-fixture-test
    namespace: larakube-shared
  spec:
    accessModes: [ReadWriteOnce]
    resources:
      requests:
        storage: 2Gi
  EOF
  ```
  → **rejected** with a `Forbidden` error on the storage request.
- [ ] **Idempotent:** re-run the same `data:init` command → succeeds, request still `5Gi`.

## Phase F — Cleanup (fixtures only)

- [ ] **Only if Phase E printed the success line** (that line prints after registration):
  `./larakube data:remove local --context=orbstack --domain=resize-fixture.test --force`
  An explicit `--domain` skips the instance picker. No `--purge` — PocketBase has no Commons data.
- [ ] **If Phase E did not print it**, skip `data:remove` and delete by exact name instead:
  ```bash
  kubectl --context orbstack delete deployment/data-pocketbase-resize-fixture-test service/data-pocketbase-resize-fixture-test ingress/data-pocketbase-resize-fixture-test-ingress configmap/data-pocketbase-resize-fixture-test-hooks secret/data-secrets-resize-fixture-test secret/data-smtp-resize-fixture-test secret/data-oidc-resize-fixture-test -n larakube-shared --ignore-not-found
  ```
- [ ] Delete the fixture claim either way — `data:remove` leaves PocketBase claims behind:
  `kubectl --context orbstack delete pvc data-pocketbase-pvc-resize-fixture-test -n larakube-shared --ignore-not-found`
- [ ] `kubectl --context orbstack delete namespace resize-fixture`
- [ ] `kubectl --context orbstack delete storageclass larakube-resize-fixture`
- [ ] **Verify:** `kubectl --context orbstack get pvc,deploy -n larakube-shared -o name | grep resize`
  returns nothing, and the PocketBase claim list matches Phase 0.
- Left behind, harmless: the local CA certificate now lists `resize-fixture.test`.

---

## Not covered

- **Bytes actually growing.** No StorageClass with a real resizer exists on OrbStack or the VPS.
  Verifying that needs `do-block-storage` on DOKS.
- **`data:remove` leaks PocketBase claims.** It deletes the Deployment, Service, Ingress,
  ConfigMap and Secrets but never the PVC, even with `--purge`. Phase F works around it; it is a
  separate fix.
- **Project-app volumes** (`base/volumes`, `mysql/`, `nextjs/`, …) don't use `$volumeSize` — their
  size belongs to the project blueprint (ADR 0014 tier).
