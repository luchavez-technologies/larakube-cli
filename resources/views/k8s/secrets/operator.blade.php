@php
    $hostAPI = $hostAPI ?? 'http://infisical-backend.larakube-secrets.svc.cluster.local:8080/api';
    $infisicalAddress = $infisicalAddress ?? 'http://infisical-backend.larakube-secrets.svc.cluster.local:8080';
    $image = $operatorImage ?? 'infisical/kubernetes-operator:v0.11.4';
@endphp
apiVersion: v1
kind: ServiceAccount
metadata:
  name: infisical-operator-controller-manager
  namespace: larakube-secrets
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: infisical-operator-manager-role
rules:
- apiGroups: [""]
  resources: ["configmaps", "secrets"]
  verbs: ["create", "delete", "get", "list", "update", "watch"]
- apiGroups: [""]
  resources: ["pods"]
  verbs: ["get", "list"]
- apiGroups: [""]
  resources: ["serviceaccounts"]
  verbs: ["get", "list", "watch"]
- apiGroups: [""]
  resources: ["serviceaccounts/token"]
  verbs: ["create"]
- apiGroups: ["apps"]
  resources: ["daemonsets", "deployments", "statefulsets"]
  verbs: ["get", "list", "update", "watch"]
- apiGroups: ["authentication.k8s.io"]
  resources: ["tokenreviews"]
  verbs: ["create"]
- apiGroups: ["secrets.infisical.com"]
  resources: ["clustergenerators", "infisicalauths", "infisicalconnections", "infisicaldynamicsecrets", "infisicalpushsecrets", "infisicalsecrets", "infisicalstaticsecrets"]
  verbs: ["create", "delete", "get", "list", "patch", "update", "watch"]
- apiGroups: ["secrets.infisical.com"]
  resources: ["infisicalauths/status", "infisicalconnections/status", "infisicaldynamicsecrets/status", "infisicalpushsecrets/status", "infisicalsecrets/status", "infisicalstaticsecrets/status"]
  verbs: ["get", "patch", "update"]
- apiGroups: ["secrets.infisical.com"]
  resources: ["infisicaldynamicsecrets/finalizers", "infisicalpushsecrets/finalizers", "infisicalsecrets/finalizers", "infisicalstaticsecrets/finalizers"]
  verbs: ["update"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: infisical-operator-manager-rolebinding
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: infisical-operator-manager-role
subjects:
- kind: ServiceAccount
  name: infisical-operator-controller-manager
  namespace: larakube-secrets
---
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata:
  name: infisical-operator-leader-election-role
  namespace: larakube-secrets
rules:
- apiGroups: [""]
  resources: ["configmaps"]
  verbs: ["get", "list", "watch", "create", "update", "patch", "delete"]
- apiGroups: ["coordination.k8s.io"]
  resources: ["leases"]
  verbs: ["get", "list", "watch", "create", "update", "patch", "delete"]
- apiGroups: [""]
  resources: ["events"]
  verbs: ["create", "patch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: infisical-operator-leader-election-rolebinding
  namespace: larakube-secrets
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: Role
  name: infisical-operator-leader-election-role
subjects:
- kind: ServiceAccount
  name: infisical-operator-controller-manager
  namespace: larakube-secrets
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: infisical-operator-metrics-auth-role
rules:
- apiGroups: ["authentication.k8s.io"]
  resources: ["tokenreviews"]
  verbs: ["create"]
- apiGroups: ["authorization.k8s.io"]
  resources: ["subjectaccessreviews"]
  verbs: ["create"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: infisical-operator-metrics-auth-rolebinding
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: infisical-operator-metrics-auth-role
subjects:
- kind: ServiceAccount
  name: infisical-operator-controller-manager
  namespace: larakube-secrets
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: infisical-operator-metrics-reader
rules:
- nonResourceURLs: ["/metrics"]
  verbs: ["get"]
---
apiVersion: v1
kind: Service
metadata:
  name: infisical-operator-controller-manager-metrics-service
  namespace: larakube-secrets
  labels:
    control-plane: controller-manager
spec:
  type: ClusterIP
  selector:
    control-plane: controller-manager
  ports:
  - name: https
    port: 8443
    protocol: TCP
    targetPort: 8443
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: infisical-operator-controller-manager
  namespace: larakube-secrets
  labels:
    control-plane: controller-manager
spec:
  replicas: 1
  selector:
    matchLabels:
      control-plane: controller-manager
  template:
    metadata:
      labels:
        control-plane: controller-manager
      annotations:
        kubectl.kubernetes.io/default-container: manager
    spec:
      containers:
      - args:
        - --metrics-bind-address=:8443
        - --leader-elect
        - --health-probe-bind-address=:8081
        command:
        - /manager
        env:
        - name: KUBERNETES_CLUSTER_DOMAIN
          value: cluster.local
        - name: INFISICAL_HOST_API
          value: "{{ $hostAPI }}"
        - name: INFISICAL_LOG_WRITER
          value: stderr
        image: "{{ $image }}"
        livenessProbe:
          httpGet:
            path: /healthz
            port: 8081
          initialDelaySeconds: 15
          periodSeconds: 20
        name: manager
        readinessProbe:
          httpGet:
            path: /readyz
            port: 8081
          initialDelaySeconds: 5
          periodSeconds: 10
        resources:
          limits:
            cpu: 500m
            memory: 128Mi
          requests:
            cpu: 10m
            memory: 64Mi
        securityContext:
          allowPrivilegeEscalation: false
          capabilities:
            drop:
            - ALL
          readOnlyRootFilesystem: true
      securityContext:
        runAsNonRoot: true
        seccompProfile:
          type: RuntimeDefault
      serviceAccountName: infisical-operator-controller-manager
      terminationGracePeriodSeconds: 10
---
apiVersion: secrets.infisical.com/v1beta1
kind: InfisicalConnection
metadata:
  name: infisical-connection
  namespace: larakube-secrets
spec:
  address: {{ $infisicalAddress }}
