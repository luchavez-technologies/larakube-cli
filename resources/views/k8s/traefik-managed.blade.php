{{-- Traefik for a MANAGED, multi-node cluster (DOKS/EKS/GKE/…). Unlike the VPS
     k3s manifest (hostPort + hostPath), this exposes Traefik through a cloud
     LoadBalancer Service (the provider assigns an external IP) and persists the
     Let's Encrypt acme.json on a PVC (the cluster's default StorageClass, e.g.
     do-block-storage). Installed with `kubectl apply` — no Helm. --}}
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
      - middlewares
      - middlewaretcps
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
apiVersion: networking.k8s.io/v1
kind: IngressClass
metadata:
  name: traefik
  annotations:
    ingressclass.kubernetes.io/is-default-class: "true"
spec:
  controller: traefik.io/ingress-controller
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: traefik-acme
  namespace: traefik
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 128Mi
@isset($storageClass)
  storageClassName: {{ $storageClass }}
@endisset
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
      containers:
        - name: traefik
          image: traefik:v3.1
          args:
            - --accesslog
            - --entrypoints.web.Address=:80
            - --entrypoints.websecure.Address=:443
            - --entrypoints.websecure.http.tls=true
            - --providers.kubernetesingress
            - --providers.kubernetesingress.ingressendpoint.publishedservice=traefik/traefik
            - --certificatesresolvers.letsencrypt.acme.email={{ $email }}
            - --certificatesresolvers.letsencrypt.acme.storage=/data/acme.json
            - --certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web
          ports:
            - name: web
              containerPort: 80
            - name: websecure
              containerPort: 443
          volumeMounts:
            - name: acme
              mountPath: /data
      volumes:
        - name: acme
          persistentVolumeClaim:
            claimName: traefik-acme
---
apiVersion: v1
kind: Service
metadata:
  name: traefik
  namespace: traefik
@isset($loadBalancerName)
  annotations:
    # Naming the LB lets the provider reuse the SAME load balancer (and its IP)
    # across reinstalls, instead of minting a new one — so DNS stays valid.
    service.beta.kubernetes.io/do-loadbalancer-name: {{ $loadBalancerName }}
@endisset
spec:
  type: LoadBalancer
  selector:
    app: traefik
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
      name: web
    - protocol: TCP
      port: 443
      targetPort: 443
      name: websecure
