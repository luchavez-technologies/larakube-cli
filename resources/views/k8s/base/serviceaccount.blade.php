@if($config->isSystem())
apiVersion: v1
kind: ServiceAccount
metadata:
  name: larakube-dashboard
  namespace: {{ $namespace }}
  labels:
    {{ \App\Support\Labels::MANAGED_BY }}: larakube
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: larakube-dashboard-role
rules:
  - apiGroups: [""]
    resources: ["namespaces", "pods", "pods/log", "pods/exec", "services", "nodes", "events"]
    verbs: ["get", "list", "watch", "create", "delete"]
  - apiGroups: ["apps"]
    resources: ["deployments", "statefulsets", "replicasets"]
    verbs: ["get", "list", "watch", "patch"]
  - apiGroups: ["networking.k8s.io"]
    resources: ["ingresses"]
    verbs: ["get", "list", "watch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: larakube-dashboard-binding-{{ $namespace }}
  labels:
    {{ \App\Support\Labels::MANAGED_BY }}: larakube
subjects:
  - kind: ServiceAccount
    name: larakube-dashboard
    namespace: {{ $namespace }}
roleRef:
  kind: ClusterRole
  name: larakube-dashboard-role
  apiGroup: rbac.authorization.k8s.io
@endif
