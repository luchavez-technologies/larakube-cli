apiVersion: v1
kind: Namespace
metadata:
  name: traefik
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: traefik-ingress-controller
  namespace: traefik
---
kind: ClusterRole
apiVersion: rbac.authorization.k8s.io/v1
metadata:
  name: traefik-ingress-controller
rules:
  - apiGroups:
      - ""
    resources:
      - services
      - endpoints
      - secrets
      - nodes
      - namespaces
    verbs:
      - get
      - list
      - watch
  - apiGroups:
      - discovery.k8s.io
    resources:
      - endpointslices
    verbs:
      - get
      - list
      - watch
  - apiGroups:
      - extensions
      - networking.k8s.io
    resources:
      - ingresses
      - ingressclasses
    verbs:
      - get
      - list
      - watch
  - apiGroups:
      - extensions
      - networking.k8s.io
    resources:
      - ingresses/status
    verbs:
      - update
  - apiGroups:
      - traefik.io
    resources:
      - ingressroutes
      - ingressroutetcps
      - ingressrouteudps
      - middlewaress
      - middlewares
      - tlsoptions
      - tlsstores
      - traefikservices
      - serverstransports
    verbs:
      - get
      - list
      - watch
---
kind: ClusterRoleBinding
apiVersion: rbac.authorization.k8s.io/v1
metadata:
  name: traefik-ingress-controller
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: traefik-ingress-controller
subjects:
  - kind: ServiceAccount
    name: traefik-ingress-controller
    namespace: traefik
---
kind: Deployment
apiVersion: apps/v1
metadata:
  name: traefik
  namespace: traefik
  labels:
    app: traefik
spec:
  replicas: 1
  selector:
    matchLabels:
      app: traefik
  template:
    metadata:
      labels:
        app: traefik
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "8082"
    spec:
      serviceAccountName: traefik-ingress-controller
      containers:
        - name: traefik
          image: traefik:v3.1
          args:
            - --api.insecure
            - --accesslog
            - --entrypoints.web.Address=:80
            - --entrypoints.web.http.redirections.entrypoint.to=websecure
            - --entrypoints.web.http.redirections.entrypoint.scheme=https
            - --entrypoints.websecure.Address=:443
            - --entrypoints.websecure.http.tls=true
            - --entrypoints.metrics.Address=:8082
            - --metrics.prometheus=true
            - --metrics.prometheus.entryPoint=metrics
            - --providers.kubernetesingress
            - --providers.file.directory=/config
            - --providers.file.watch=true
            - --serverstransport.insecureSkipVerify=true
          ports:
            - name: web
              containerPort: 80
            - name: websecure
              containerPort: 443
            - name: admin
              containerPort: 8080
            - name: metrics
              containerPort: 8082
          volumeMounts:
            - name: config
              mountPath: /config
            - name: certificates
              mountPath: /certs
      volumes:
        - name: config
          configMap:
            name: traefik-config
        - name: certificates
          secret:
            secretName: traefik-certificates
---
apiVersion: v1
kind: Service
metadata:
  name: traefik
  namespace: traefik
  labels:
    app.kubernetes.io/name: traefik
    app.kubernetes.io/component: ingress-controller
    app.kubernetes.io/managed-by: larakube
spec:
  type: LoadBalancer
  selector:
    app: traefik
  ports:
    - protocol: TCP
      port: 80
      name: web
    - protocol: TCP
      port: 443
      name: websecure
    - protocol: TCP
      port: 8080
      name: admin

{{-- The traefik-dashboard Ingress (traefik.{tld}) is intentionally NOT here.
     Its host carries the TLD, so it must be re-applied on every `up` (not
     written once at install) or a `config:tld` change leaves it stale. It
     lives in k8s/traefik-dashboard.blade.php (the SharedClusterService::TRAEFIK_DASHBOARD
     case), reconciled by InteractsWithTraefik::reconcileSharedCluster(). --}}
