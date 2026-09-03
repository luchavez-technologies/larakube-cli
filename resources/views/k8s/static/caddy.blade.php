{{-- The built site IS the image: Dockerfile.static compiles the bundle in Node
     and copies it into Caddy, so there is nothing to fetch at runtime — no S3,
     no credentials, no init container, and `kubectl rollout undo` is a real
     rollback.

     Blade @if directives stay at column 0: an indented one leaks its own
     leading whitespace onto the next line when it closes and corrupts the
     following key. --}}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: web
spec:
  replicas: {{ $config->getReplicas($environment, 'web') }}
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: web
  template:
    metadata:
      labels:
        app: web
    spec:
      containers:
        - name: caddy
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          ports:
            - containerPort: 8080
          readinessProbe:
            httpGet:
              path: {{ $config->framework->healthProbePath() }}
              port: 8080
            initialDelaySeconds: 2
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: {{ $config->framework->healthProbePath() }}
              port: 8080
            initialDelaySeconds: 10
            periodSeconds: 30
          resources:
            requests:
              cpu: 10m
              memory: 16Mi
            limits:
              cpu: 200m
              memory: 128Mi
---
apiVersion: v1
kind: Service
metadata:
  name: web
spec:
  selector:
    app: web
  ports:
    - protocol: TCP
      port: 80
      targetPort: 8080
  type: ClusterIP
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: web
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
{{-- Orange-cloud by default: edge caching is the point of serving a static
     bundle. Lock this file to opt out. --}}
    external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"
spec:
  rules:
@foreach($hosts as $webHost)
    - host: {{ $webHost }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: web
                port:
                  number: 80
@endforeach
  tls:
    - hosts:
@foreach($hosts as $webHost)
        - {{ $webHost }}
@endforeach
