@php
    $namespace = $namespace ?? 'larakube-secrets';
@endphp
apiVersion: v1
kind: ServiceAccount
metadata:
  name: external-secrets
  namespace: {{ $namespace }}
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: external-secrets-controller
rules:
  - apiGroups: [""]
    resources: ["secrets", "namespaces", "configmaps"]
    verbs: ["get", "list", "watch", "create", "update", "patch", "delete"]
  - apiGroups: ["external-secrets.io"]
    resources: ["*"]
    verbs: ["*"]
  - apiGroups: ["generators.external-secrets.io"]
    resources: ["*"]
    verbs: ["*"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: external-secrets-controller
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: external-secrets-controller
subjects:
  - kind: ServiceAccount
    name: external-secrets
    namespace: {{ $namespace }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: external-secrets
  namespace: {{ $namespace }}
  labels:
    app: external-secrets
spec:
  replicas: 1
  selector:
    matchLabels:
      app: external-secrets
  template:
    metadata:
      labels:
        app: external-secrets
    spec:
      serviceAccountName: external-secrets
      containers:
        - name: external-secrets
          image: ghcr.io/external-secrets/external-secrets:v0.11.0
          args:
            - --concurrent=1
          resources:
            requests:
              cpu: 10m
              memory: 32Mi
            limits:
              cpu: 100m
              memory: 128Mi
