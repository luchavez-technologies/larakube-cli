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
  # hostPort binds ports 80/443 directly on the host — only one pod can hold
  # them at a time. RollingUpdate (default) would try to start the new pod
  # before killing the old one, causing a permanent Pending deadlock on a
  # single-node VPS. Recreate kills the old pod first (~15s downtime).
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: traefik
  template:
    metadata:
      labels:
        app: traefik
    spec:
      serviceAccountName: traefik-ingress-controller
      hostNetwork: false
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
            - --providers.kubernetesingress
@if(isset($ip))
            - --providers.kubernetesingress.ingressendpoint.ip={{ $ip }}
@endif
            - --providers.file.directory=/config
            - --providers.file.watch=true
            - --serverstransport.insecureSkipVerify=true
@if(isset($email))
            - --certificatesresolvers.letsencrypt.acme.email={{ $email }}
            - --certificatesresolvers.letsencrypt.acme.storage=/acme/acme.json
            - --certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web
@endif
          ports:
            - name: web
              containerPort: 80
              hostPort: 80
            - name: websecure
              containerPort: 443
              hostPort: 443
            - name: admin
              containerPort: 8080
          volumeMounts:
            - name: config
              mountPath: /config
            - name: certificates
              mountPath: /certs
@if(isset($email))
            - name: acme
              mountPath: /acme
@endif
      volumes:
        - name: config
          configMap:
            name: traefik-config
        - name: certificates
          secret:
            secretName: traefik-certificates
@if(isset($email))
        - name: acme
          hostPath:
            path: /var/lib/larakube/traefik/acme
            type: DirectoryOrCreate
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: traefik
  namespace: traefik
spec:
  type: ClusterIP
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
