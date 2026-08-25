apiVersion: v1
kind: Secret
metadata:
  name: errors-secrets
  namespace: larakube-shared
type: Opaque
data:
  password: {{ base64_encode($adminPassword) }}
{{-- Confirmed live 2026-08-24 (same bug, chat/vpn Ingress): an indented @if
     directive leaks its own leading whitespace onto the next line once it
     closes — here it would push `secret-key:` deeper than `password:`,
     an invalid YAML mapping. Keep directive tags at column 0; only literal
     YAML content is indented. --}}
@if ($noPlex)
  database-url: {{ base64_encode("postgres://glitchtip:{$dbPassword}@glitchtip-db:5432/glitchtip") }}
  redis-url: {{ base64_encode("redis://glitchtip-cache:6379/0") }}
@else
  database-url: {{ base64_encode("postgres://glitchtip:{$dbPassword}@postgres.{$plexNamespace}.svc.cluster.local:5432/glitchtip") }}
  redis-url: {{ base64_encode("redis://redis.{$plexNamespace}.svc.cluster.local:6379/15") }}
@endif
  secret-key: {{ base64_encode(\Illuminate\Support\Str::random(50)) }}
---
apiVersion: batch/v1
kind: Job
metadata:
  name: glitchtip-db-migrations
  namespace: larakube-shared
spec:
  template:
    spec:
      restartPolicy: OnFailure
      containers:
        - name: migrate
          image: glitchtip/glitchtip:6.2.2
          command: ["./manage.py", "migrate"]
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: errors-secrets
                  key: database-url
            - name: CELERY_BROKER_URL
              valueFrom:
                secretKeyRef:
                  name: errors-secrets
                  key: redis-url
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: errors-secrets
                  key: secret-key
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: glitchtip-web
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: glitchtip-web
  template:
    metadata:
      labels:
        app: glitchtip-web
    spec:
      containers:
        - name: web
          image: glitchtip/glitchtip:6.2.2
          ports:
            - containerPort: 8000
              name: http
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: errors-secrets
                  key: database-url
            - name: CELERY_BROKER_URL
              valueFrom:
                secretKeyRef:
                  name: errors-secrets
                  key: redis-url
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: errors-secrets
                  key: secret-key
            - name: GLITCHTIP_DOMAIN
              value: "https://{{ $host }}"
@if($appName ?? null)
            - name: GLITCHTIP_INSTANCE_NAME
              value: "{{ $appName }}"
@endif
            - name: GLITCHTIP_ADMIN_EMAIL
              value: "admin@larakube.local"
            - name: GLITCHTIP_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: errors-secrets
                  key: password
            # SMTP (mail:wire): GlitchTip reads a single composed
            # django-environ URL plus the from-address — EMAIL_URL is built
            # at wire time (smtp+ssl:// with percent-encoded credentials,
            # see ErrorTool::smtpEnv()).
            - name: EMAIL_URL
              valueFrom:
                secretKeyRef:
                  name: glitchtip-smtp
                  key: EMAIL_URL
                  optional: true
            - name: DEFAULT_FROM_EMAIL
              valueFrom:
                secretKeyRef:
                  name: glitchtip-smtp
                  key: DEFAULT_FROM_EMAIL
                  optional: true
          readinessProbe:
            httpGet:
              path: /
              port: 8000
            initialDelaySeconds: 15
            periodSeconds: 10
---
apiVersion: v1
kind: Service
metadata:
  name: glitchtip-web
  namespace: larakube-shared
spec:
  selector:
    app: glitchtip-web
  ports:
    - protocol: TCP
      port: 8000
      targetPort: 8000
  type: ClusterIP
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: glitchtip-worker
  namespace: larakube-shared
spec:
  replicas: 1
  selector:
    matchLabels:
      app: glitchtip-worker
  template:
    metadata:
      labels:
        app: glitchtip-worker
    spec:
      containers:
        - name: worker
          image: glitchtip/glitchtip:6.2.2
          command: ["celery", "-A", "glitchtip", "worker", "-B"]
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: errors-secrets
                  key: database-url
            - name: CELERY_BROKER_URL
              valueFrom:
                secretKeyRef:
                  name: errors-secrets
                  key: redis-url
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: errors-secrets
                  key: secret-key
            - name: GLITCHTIP_DOMAIN
              value: "https://{{ $host }}"
            # SMTP (mail:wire): the celery worker sends the actual alert +
            # notification emails, so it mounts the same optional SMTP
            # secret keys as the web Deployment (see ErrorTool::smtpEnv()).
            - name: EMAIL_URL
              valueFrom:
                secretKeyRef:
                  name: glitchtip-smtp
                  key: EMAIL_URL
                  optional: true
            - name: DEFAULT_FROM_EMAIL
              valueFrom:
                secretKeyRef:
                  name: glitchtip-smtp
                  key: DEFAULT_FROM_EMAIL
                  optional: true
---
@if ($noPlex)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: glitchtip-db-storage
  namespace: larakube-shared
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 2Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: glitchtip-db
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: glitchtip-db
  template:
    metadata:
      labels:
        app: glitchtip-db
    spec:
      containers:
        - name: postgres
          image: postgres:15-alpine
          ports:
            - containerPort: 5432
          env:
            - name: POSTGRES_DB
              value: glitchtip
            - name: POSTGRES_USER
              value: glitchtip
            - name: POSTGRES_PASSWORD
              value: "{{ $dbPassword }}"
          volumeMounts:
            - name: storage
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: glitchtip-db-storage
---
apiVersion: v1
kind: Service
metadata:
  name: glitchtip-db
  namespace: larakube-shared
spec:
  selector:
    app: glitchtip-db
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
  type: ClusterIP
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: glitchtip-cache
  namespace: larakube-shared
spec:
  replicas: 1
  selector:
    matchLabels:
      app: glitchtip-cache
  template:
    metadata:
      labels:
        app: glitchtip-cache
    spec:
      containers:
        - name: valkey
          image: valkey/valkey:8.0-alpine
          ports:
            - containerPort: 6379
---
apiVersion: v1
kind: Service
metadata:
  name: glitchtip-cache
  namespace: larakube-shared
spec:
  selector:
    app: glitchtip-cache
  ports:
    - protocol: TCP
      port: 6379
      targetPort: 6379
  type: ClusterIP
---
@endif
@include('k8s.errors.ingress')
