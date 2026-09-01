{{-- Production origin for a static site.

     The image is upstream Caddy, never built per project — the content lives in
     the Commons bucket, so publishing a change needs no image build and no
     registry (which is what makes this work for a Forgejo repo without one).

     Caddy rather than a Traefik middleware because Traefik is pinned at v3.1 and
     `statusRewrites` on the errors middleware — the only way to return HTTP 200
     for an SPA deep link rather than the origin's 404 — landed in v3.4.

     Blade @if directives stay at column 0: an indented one leaks its own leading
     whitespace onto the next line when it closes and corrupts the following key. --}}
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $config->getName() }}-caddy
data:
  Caddyfile: |
    :8080 {
        root * /srv
        encode zstd gzip

        # Hashed filenames are content-addressed, so they can never go stale.
        @@immutable path_regexp \.[0-9a-fA-F]{8,}\.(js|mjs|css|woff2?|png|jpe?g|svg|webp|avif|gif|ico)$
        header @@immutable Cache-Control "public, max-age=31536000, immutable"

        # index.html must NOT be cached, or a returning visitor keeps loading
        # the old bundle's asset URLs after a deploy and sees a stale site.
        header /index.html Cache-Control "no-cache"

        # SPA deep links: /some/route has no file on disk and must serve the
        # app shell at 200, not 404. This is the failure a dev server hides.
        try_files {path} /index.html

        file_server {
            precompressed br gzip
        }
    }
---
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
{{-- The init container pulls whatever release this project's -release ConfigMap
     points at. The sha lives in that ConfigMap rather than in this manifest, so
     a re-apply never clobbers the deployed release and a rollback is just
     rewriting it and restarting. --}}
      initContainers:
        - name: fetch-release
          image: amazon/aws-cli:2.27.22
          envFrom:
            - configMapRef:
                name: {{ $config->getName() }}-release
                optional: true
            - secretRef:
                name: {{ $config->getName() }}-s3
          command:
            - sh
            - -c
            - |
              set -eu
              if [ -z "${SITE_PREFIX:-}" ]; then
                echo "No release published for '{{ $environment }}' yet."
                echo "Run: larakube cloud:deploy {{ $environment }}"
                exit 1
              fi
              aws --endpoint-url "$AWS_ENDPOINT" --no-progress \
                s3 sync "s3://{{ $bucket }}/$SITE_PREFIX/" /srv --delete
          volumeMounts:
            - name: site
              mountPath: /srv
      containers:
        - name: caddy
          image: caddy:2.11.2-alpine
          ports:
            - containerPort: 8080
          readinessProbe:
            httpGet:
              path: {{ $config->framework->healthProbePath() }}
              port: 8080
            initialDelaySeconds: 3
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: {{ $config->framework->healthProbePath() }}
              port: 8080
            initialDelaySeconds: 10
            periodSeconds: 30
          volumeMounts:
            - name: site
              mountPath: /srv
              readOnly: true
            - name: caddyfile
              mountPath: /etc/caddy
      volumes:
        - name: site
          emptyDir: {}
        - name: caddyfile
          configMap:
            name: {{ $config->getName() }}-caddy
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
{{-- Orange-cloud by default: edge caching is the entire point of serving a
     static bundle this way. Lock this file to opt out. --}}
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
