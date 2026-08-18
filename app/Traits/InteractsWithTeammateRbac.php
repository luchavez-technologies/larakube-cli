<?php

namespace App\Traits;

/**
 * Pure builders for per-person, RBAC-scoped teammate access. Each teammate is one
 * ServiceAccount in a central `larakube-access` namespace (one token → one
 * kubeconfig); access to an app is a RoleBinding in that app's namespace pointing
 * at a built-in ClusterRole (view/edit/admin). Adding an app = one more binding,
 * the identity/kubeconfig never changes.
 *
 * See plans/active/rbac-teammate-access.md. Pure (no I/O) → unit-testable.
 */
trait InteractsWithTeammateRbac
{
    /** Central namespace that holds human ServiceAccount identities. */
    public function accessNamespace(): string
    {
        return 'larakube-access';
    }

    /** A k8s-safe ServiceAccount name derived from a person's name. */
    public function teammateSaName(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-');
    }

    /** The labeled RoleBinding name for a person in an app namespace. */
    public function teammateBindingName(string $sa): string
    {
        return 'larakube-user-'.$sa;
    }

    /**
     * Preset flags → a built-in ClusterRole. Default is `edit` (operate the app,
     * but can't manage RBAC). `--read` = `view` (no exec, no secrets), `--admin` =
     * `admin` (edit + manage access within the namespace) — EXCEPT when granting
     * cluster-wide ($clusterWide): the built-in `admin` ClusterRole deliberately
     * excludes cluster-scoped resources (Nodes, PersistentVolumes, StorageClasses,
     * CustomResourceDefinitions, ClusterRoles/Bindings) even when bound via a
     * ClusterRoleBinding, so binding it cluster-wide silently under-delivers on
     * what "admin across the whole cluster" implies. `cluster-admin` is the only
     * built-in role that actually reaches those — use it instead in that case.
     */
    public function presetClusterRole(bool $read, bool $edit, bool $admin, bool $clusterWide = false): string
    {
        return match (true) {
            $admin && $clusterWide => 'cluster-admin',
            $admin => 'admin',
            $read => 'view',
            default => 'edit',
        };
    }

    /**
     * The context name a teammate sees — meaningful (app+env), not the cluster's
     * API hostname. A namespace like `react-test-production` → `larakube-react-test-production`,
     * so it reads cleanly on managed clusters too (a DOKS host would be a 60-char
     * UUID). Deterministic, so everyone granted the same app/env sees the same name,
     * and re-import is idempotent. Pure.
     */
    public function teammateContextName(string $appNamespace): string
    {
        return 'larakube-'.($appNamespace !== '' ? $appNamespace : 'cluster');
    }

    /** Namespace + ServiceAccount + bound-token Secret for a person (central ns). Pure. */
    public function teammateIdentityManifest(string $accessNs, string $sa, string $person): string
    {
        return <<<YAML
apiVersion: v1
kind: Namespace
metadata:
  name: {$accessNs}
  labels:
    app.kubernetes.io/managed-by: larakube
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: {$sa}
  namespace: {$accessNs}
  labels:
    app.kubernetes.io/managed-by: larakube
    larakube.dev/access-user: {$sa}
  annotations:
    larakube.dev/person: "{$person}"
---
apiVersion: v1
kind: Secret
metadata:
  name: {$sa}-token
  namespace: {$accessNs}
  labels:
    app.kubernetes.io/managed-by: larakube
    larakube.dev/access-user: {$sa}
  annotations:
    kubernetes.io/service-account.name: {$sa}
type: kubernetes.io/service-account-token
YAML;
    }

    /**
     * RoleBinding in an APP namespace → a built-in ClusterRole, subject = the
     * person's central SA. Labeled so off-boarding can find every binding for a
     * user cluster-wide. Pure.
     */
    public function teammateBindingManifest(string $appNs, string $accessNs, string $sa, string $clusterRole): string
    {
        $binding = $this->teammateBindingName($sa);

        return <<<YAML
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: {$binding}
  namespace: {$appNs}
  labels:
    app.kubernetes.io/managed-by: larakube
    larakube.dev/access-user: {$sa}
subjects:
  - kind: ServiceAccount
    name: {$sa}
    namespace: {$accessNs}
roleRef:
  kind: ClusterRole
  name: {$clusterRole}
  apiGroup: rbac.authorization.k8s.io
YAML;
    }

    /** The labeled ClusterRoleBinding name for a person granted cluster-wide. */
    public function teammateClusterBindingName(string $sa): string
    {
        return 'larakube-cluster-user-'.$sa;
    }

    /**
     * ClusterRoleBinding → a built-in ClusterRole across EVERY namespace, subject
     * = the person's central SA. The cluster-wide counterpart of
     * teammateBindingManifest(); separate name so a person can hold both a
     * cluster grant and per-namespace ones without the two clobbering each other.
     *
     * Read the security note on ClusterGrantCommand's --cluster flag before
     * reaching for this: bound to `edit` or `admin` it reaches every Secret in
     * every namespace, which on this cluster includes the Commons Postgres
     * superuser, the secrets-backend bootstrap and Zitadel's machine PAT. Pure.
     */
    public function teammateClusterBindingManifest(string $accessNs, string $sa, string $clusterRole): string
    {
        $binding = $this->teammateClusterBindingName($sa);

        return <<<YAML
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: {$binding}
  labels:
    app.kubernetes.io/managed-by: larakube
    larakube.dev/access-user: {$sa}
    larakube.dev/access-scope: cluster
subjects:
  - kind: ServiceAccount
    name: {$sa}
    namespace: {$accessNs}
roleRef:
  kind: ClusterRole
  name: {$clusterRole}
  apiGroup: rbac.authorization.k8s.io
YAML;
    }

    /**
     * A teammate kubeconfig whose context is named for the APP+ENV (so it reads
     * cleanly and tells them what they're operating), defaulting to that namespace.
     * Pure.
     */
    public function assembleTeammateKubeconfig(string $contextName, string $server, string $caData, string $defaultNamespace, string $token, string $user): string
    {
        return <<<YAML
apiVersion: v1
kind: Config
clusters:
  - name: {$contextName}
    cluster:
      server: {$server}
      certificate-authority-data: {$caData}
contexts:
  - name: {$contextName}
    context:
      cluster: {$contextName}
      namespace: {$defaultNamespace}
      user: {$user}
current-context: {$contextName}
users:
  - name: {$user}
    user:
      token: {$token}
YAML;
    }
}
