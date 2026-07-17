apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: sheet-storage
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 5Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: sheet-baserow
  namespace: larakube-shared
  labels:
    app: sheet-baserow
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: sheet-baserow
  template:
    metadata:
      labels:
        app: sheet-baserow
    spec:
      containers:
        - name: baserow
          image: baserow/baserow:1.34.5
          env:
            # Public URL so generated links use the real host behind Traefik, and
            # BASEROW_CADDY_ADDRESSES pins the embedded Caddy to plain HTTP :80
            # (Traefik terminates TLS — no second ACME client fighting for :443).
            - name: BASEROW_PUBLIC_URL
              value: https://{{ $host }}
            - name: BASEROW_CADDY_ADDRESSES
              value: ":80"
            # External Postgres from the Plex Commons (its own isolated tenant DB).
            - name: DATABASE_HOST
              value: postgres.{{ $plexNamespace }}.svc.cluster.local
            - name: DATABASE_PORT
              value: "5432"
            - name: DATABASE_NAME
              value: baserow
            - name: DATABASE_USER
              value: baserow
            - name: DATABASE_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: sheet-secrets
                  key: db-password
            # Shared Commons Valkey (Redis-compatible), on a dedicated logical DB
            # index the Plex registry allocated to this tenant. Baserow points its
            # Celery broker/result-backend, Channels realtime, and caches at this
            # one REDIS_URL — all namespaced by key prefix inside index {{ $redisIndex }}, so
            # nothing collides with other Commons tenants (which own other indexes).
            # We set REDIS_URL directly because Baserow only hardcodes "/0" in the
            # fallback it builds when REDIS_URL is unset.
            - name: REDIS_URL
              value: redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: sheet-secrets
                  key: secret-key
            - name: BASEROW_JWT_SIGNING_KEY
              valueFrom:
                secretKeyRef:
                  name: sheet-secrets
                  key: jwt-key
            # Trim memory: fewer internal processes + a single worker so Baserow
            # fits alongside the other shared tools on a small node.
            - name: BASEROW_RUN_MINIMAL
              value: "yes"
            - name: BASEROW_AMOUNT_OF_WORKERS
              value: "1"
            # Template syncing at startup is slow and the readiness probe times
            # out while it runs — disable it; operators can trigger manually.
            - name: BASEROW_TRIGGER_SYNC_TEMPLATES_AFTER_MIGRATION
              value: "false"
            - name: DATA_DIR
              value: /baserow/data
          ports:
            - containerPort: 80
              name: http
          readinessProbe:
            httpGet:
              path: /api/_health/
              port: 80
            initialDelaySeconds: 60
            periodSeconds: 15
            timeoutSeconds: 10
            failureThreshold: 6
          livenessProbe:
            tcpSocket:
              port: 80
            initialDelaySeconds: 90
            periodSeconds: 15
          volumeMounts:
            - name: storage
              mountPath: /baserow/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: sheet-storage
---
# Stable Service name `sheet` fronts whichever engine (Baserow/NocoDB) is
# deployed, so the ingress and up-reconcile stay engine-agnostic.
apiVersion: v1
kind: Service
metadata:
  name: sheet
  namespace: larakube-shared
spec:
  selector:
    app: sheet-baserow
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
---
@include('k8s.sheet.ingress')
