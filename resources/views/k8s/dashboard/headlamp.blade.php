@php($suffix = ($instance ?? '') !== '' ? "-{$instance}" : '')
apiVersion: v1
kind: Secret
metadata:
  name: dashboard-headlamp-oidc{{ $suffix }}
  namespace: larakube-shared
type: Opaque
data:
@if($oidc ?? null)
  HEADLAMP_CONFIG_OIDC_IDP_ISSUER_URL: {{ base64_encode($oidc['issuer'] ?? '') }}
  HEADLAMP_CONFIG_OIDC_CLIENT_ID: {{ base64_encode($oidc['client_id'] ?? '') }}
  HEADLAMP_CONFIG_OIDC_CLIENT_SECRET: {{ base64_encode($oidc['client_secret'] ?? '') }}
  HEADLAMP_CONFIG_OIDC_SCOPES: {{ base64_encode('openid profile email groups') }}
@endif
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: dashboard-headlamp{{ $suffix }}
  namespace: larakube-shared
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: dashboard-headlamp-admin{{ $suffix }}
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: cluster-admin
subjects:
  - kind: ServiceAccount
    name: dashboard-headlamp{{ $suffix }}
    namespace: larakube-shared
---
# Grants the OIDC-authenticated identity, not the ServiceAccount above, actual
# cluster-admin. Headlamp forwards the browser's bearer token straight through
# to the API server unmodified (confirmed live — no impersonation involved);
# the API server's own native OIDC authenticator (trusted via `dashboard:trust`,
# --oidc-issuer-url/--oidc-client-id/--oidc-groups-claim=groups) is what turns
# that token into an identity. The ServiceAccount's cluster-admin binding above
# only lets Headlamp's own pod reach the API at all — it grants nothing to the
# logged-in user.
#
# The "-" prefix on the group name is NOT a typo. "dashboard-admin" is the bare
# Zitadel project role key (ClusterTool::DASHBOARD->rbacRoles()) that
# zitadelEnsureRbacAction() flattens into the token's `groups` claim — a fixed,
# cluster-wide claim value, never instance-suffixed (there is exactly one K8s
# API server this binds against, regardless of naming convention elsewhere) —
# but `dashboard:trust` sets `--oidc-groups-prefix=-`, which per Kubernetes'
# own docs should DISABLE prefixing (same sentinel that correctly leaves
# --oidc-username-claim=email unprefixed, confirmed live). It doesn't: this
# k3s/kube-apiserver version prepends a literal "-" to every group from the
# claim instead of treating it as the disable sentinel. Confirmed
# unambiguously via `SelfSubjectReview` and `SubjectAccessReview` 2026-08-06 —
# a real token's resolved identity carries groups ["-openbao-admin",
# "-grafana-admin", "-dashboard-admin"], and only "-dashboard-admin" (not the
# bare key) matches an RBAC binding. Match reality here, not the docs, until
# k3s's own prefix handling is fixed upstream.
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: dashboard-oidc-admins{{ $suffix }}
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: cluster-admin
subjects:
  - kind: Group
    name: -dashboard-admin
    apiGroup: rbac.authorization.k8s.io
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: dashboard-headlamp{{ $suffix }}
  namespace: larakube-shared
  labels:
    app: dashboard-headlamp{{ $suffix }}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: dashboard-headlamp{{ $suffix }}
  template:
    metadata:
      labels:
        app: dashboard-headlamp{{ $suffix }}
    spec:
      serviceAccountName: dashboard-headlamp{{ $suffix }}
      containers:
        - name: headlamp
          image: ghcr.io/headlamp-k8s/headlamp:v0.29.0
          args:
            - "-plugins-dir=/headlamp/plugins"
            - "-in-cluster"
          ports:
            - containerPort: 4466
              name: http
@if($oidc ?? null)
          envFrom:
            - secretRef:
                name: dashboard-headlamp-oidc{{ $suffix }}
@endif
          readinessProbe:
            httpGet:
              path: /
              port: 4466
            initialDelaySeconds: 5
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /
              port: 4466
            initialDelaySeconds: 10
            periodSeconds: 10
---
apiVersion: v1
kind: Service
metadata:
  name: dashboard-headlamp{{ $suffix }}
  namespace: larakube-shared
spec:
  selector:
    app: dashboard-headlamp{{ $suffix }}
  ports:
    - protocol: TCP
      port: 4466
      targetPort: 4466
---
@include('k8s.dashboard.ingress')
