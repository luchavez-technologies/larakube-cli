apiVersion: v1
kind: Secret
metadata:
  name: dashboard-headlamp-oidc
  namespace: larakube-shared
type: Opaque
data:
@if($oidc ?? null)
  HEADLAMP_OIDC_IDP_ISSUER_URL: {{ base64_encode($oidc['issuer'] ?? '') }}
  HEADLAMP_OIDC_CLIENT_ID: {{ base64_encode($oidc['client_id'] ?? '') }}
  HEADLAMP_OIDC_CLIENT_SECRET: {{ base64_encode($oidc['client_secret'] ?? '') }}
  HEADLAMP_OIDC_SCOPES: {{ base64_encode('openid profile email groups') }}
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
            - containerPort: 4686
              name: http
@if($oidc ?? null)
          envFrom:
            - secretRef:
                name: dashboard-headlamp-oidc
@endif
          readinessProbe:
            httpGet:
              path: /
              port: 4686
            initialDelaySeconds: 5
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /
              port: 4686
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
      port: 4686
      targetPort: 4686
---
@include('k8s.dashboard.ingress')
