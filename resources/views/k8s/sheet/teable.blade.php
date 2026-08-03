apiVersion: apps/v1
kind: Deployment
metadata:
  name: sheet-teable
  namespace: larakube-shared
  labels:
    app: sheet-teable
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: sheet-teable
  template:
    metadata:
      labels:
        app: sheet-teable
    spec:
      containers:
        - name: teable
          # The FULL image, used as if it were the community one: LaraKube CLI
          # wires only what is AGPL — instance-level OIDC and SMTP — and never
          # relies on a paid feature. Unlicensed paid features stay inert.
          #
          # `ghcr.io/teableio/teable-community` is the honest alternative and
          # drops the upgrade nags, but it trails this image by a week or more,
          # has ~1% of the downloads, and pulls cold and slow. Chosen tradeoff:
          # a newer, better-travelled build, at the cost of a licence page and
          # some "Pending configuration" items that will never be satisfiable.
          # If those ads ever become the bigger problem, community is a
          # one-line swap — but pin a release.* tag, NOT :latest, whose
          # community build is months stale.
          image: teableio/teable:release.2026-07-26T01-04-56Z.2377
          env:
            - name: PUBLIC_ORIGIN
              value: https://{{ $host }}
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: sheet-secrets
                  key: secret-key
            - name: PRISMA_DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: sheet-secrets
                  key: database-url
            # Defaults to sqlite. Without this the URI below is ignored for
            # caching, the allocated Valkey index goes unused, and the log fills
            # with "Redis is not available (cache provider is not redis)".
            - name: BACKEND_CACHE_PROVIDER
              value: redis
            - name: BACKEND_CACHE_REDIS_URI
              value: redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}
            - name: BACKEND_STORAGE_PROVIDER
              value: s3
            - name: BACKEND_STORAGE_S3_REGION
              value: us-east-1
            # Teable runs TWO S3 clients. The presigner signs the URLs handed to
            # the browser, so it must use the PUBLIC endpoint; uploads run
            # server-side over the internal one. Swapping these still uploads
            # fine and only breaks in the browser, so keep them straight.
            - name: BACKEND_STORAGE_S3_ENDPOINT
              value: {{ $s3PublicEndpoint }}
            - name: BACKEND_STORAGE_S3_INTERNAL_ENDPOINT
              value: {{ $s3InternalEndpoint }}
            # SeaweedFS has no subdomain-per-bucket serving. Both knobs are
            # required: the first covers browser-facing URLs, the second the
            # internal client — without it uploads resolve <bucket>.<endpoint>
            # and die with ENOTFOUND.
            - name: BACKEND_STORAGE_S3_FORCE_PATH_STYLE
              value: "true"
            - name: BACKEND_STORAGE_S3_INTERNAL_FORCE_PATH_STYLE
              value: "true"
            - name: BACKEND_STORAGE_S3_ACCESS_KEY
              valueFrom:
                secretKeyRef:
                  name: sheet-secrets
                  key: s3-access-key
            - name: BACKEND_STORAGE_S3_SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: sheet-secrets
                  key: s3-secret-key
            # Teable wants two buckets, not one. There is no
            # BACKEND_STORAGE_S3_BUCKET — setting it does nothing and leaves
            # these on their defaults of "public"/"private".
            - name: BACKEND_STORAGE_PUBLIC_BUCKET
              value: {{ $s3PublicBucket }}
            - name: BACKEND_STORAGE_PRIVATE_BUCKET
              value: {{ $s3PrivateBucket }}
          # Teable serves on 3000 ("Ready on http://localhost:3000"), NOT 80.
          # Nothing binds 80, so probes there get connection-refused forever and
          # the container is killed and restarted without ever going ready —
          # while its own logs read perfectly healthy.
          ports:
            - containerPort: 3000
              name: http
          # Teable is the heaviest tool in larakube-shared — a NestJS backend
          # and a Next.js frontend in one container, ~1.1Gi at rest. The request
          # reflects that resting figure so the scheduler is not lied to; the
          # limit leaves ~2x headroom for import/compute spikes.
          resources:
            requests:
              memory: 1Gi
              cpu: 100m
            limits:
              memory: 2Gi
              cpu: 1000m
          startupProbe:
            tcpSocket:
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 5
            # 30 × 10s = 300s, matching the rollout-status timeout in
            # sheets:init. A longer budget here just means the command reports a
            # timeout while Kubernetes is still patiently waiting.
            failureThreshold: 30
          readinessProbe:
            # /health, not /api/health — the latter is a 404.
            httpGet:
              path: /health
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 15
            timeoutSeconds: 10
            failureThreshold: 6
          livenessProbe:
            tcpSocket:
              port: 3000
            initialDelaySeconds: 60
            periodSeconds: 15
---
apiVersion: v1
kind: Service
metadata:
  name: sheet
  namespace: larakube-shared
spec:
  selector:
    app: sheet-teable
  ports:
    # Service port stays 80 so the ingress backend is unchanged; only the
    # targetPort follows Teable to 3000.
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.sheet.ingress')
