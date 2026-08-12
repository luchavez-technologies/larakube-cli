apiVersion: v1
kind: ServiceAccount
metadata:
  name: reloader-reloader
  namespace: {{ $namespace }}
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: reloader-reloader-role
rules:
  - apiGroups: [""]
    resources: ["secrets", "configmaps"]
    verbs: ["get", "list", "watch"]
  - apiGroups: ["apps"]
    resources: ["deployments", "daemonsets", "statefulsets"]
    verbs: ["get", "list", "watch", "update", "patch"]
  - apiGroups: ["batch"]
    resources: ["cronjobs", "jobs"]
    verbs: ["get", "list", "watch", "update", "patch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: reloader-reloader-role-binding
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: reloader-reloader-role
subjects:
  - kind: ServiceAccount
    name: reloader-reloader
    namespace: {{ $namespace }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: reloader-reloader
  namespace: {{ $namespace }}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: reloader-reloader
  template:
    metadata:
      labels:
        app: reloader-reloader
    spec:
      serviceAccountName: reloader-reloader
      containers:
        - name: reloader
          image: ghcr.io/stakater/reloader:v1.0.69
          imagePullPolicy: IfNotPresent
