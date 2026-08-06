apiVersion: v1
kind: Secret
metadata:
  name: dashboard-headlamp-oidc
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
  name: dashboard-headlamp
  namespace: larakube-shared
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: dashboard-headlamp-admin
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: cluster-admin
subjects:
  - kind: ServiceAccount
    name: dashboard-headlamp
    namespace: larakube-shared
---
# Grants the OIDC-impersonated identity, not the ServiceAccount above, actual
# cluster-admin. Headlamp authenticates its own API calls as the ServiceAccount
# but sends them with Impersonate-User/-Group headers for the logged-in user,
# so the API server authorizes each request as THAT identity — the
# ServiceAccount's own cluster-admin binding only covers impersonation itself,
# not what an impersonated user can do. "dashboard-admin" is the Zitadel
# project role key (ClusterTool::DASHBOARD->rbacRoles()); sso:grant grants it
# in Zitadel, and zitadelEnsureRbacAction() flattens it into this token's
# `groups` claim, which Headlamp forwards as Impersonate-Group. Static and
# unconditional — safe to apply whether or not OIDC is currently wired, since
# the group name never appears in an impersonation header without it.
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: dashboard-oidc-admins
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: cluster-admin
subjects:
  - kind: Group
    name: dashboard-admin
    apiGroup: rbac.authorization.k8s.io
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: dashboard-headlamp
  namespace: larakube-shared
  labels:
    app: dashboard-headlamp
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: dashboard-headlamp
  template:
    metadata:
      labels:
        app: dashboard-headlamp
    spec:
      serviceAccountName: dashboard-headlamp
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
                name: dashboard-headlamp-oidc
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
  name: dashboard-headlamp
  namespace: larakube-shared
spec:
  selector:
    app: dashboard-headlamp
  ports:
    - protocol: TCP
      port: 4466
      targetPort: 4466
---
@include('k8s.dashboard.ingress')
