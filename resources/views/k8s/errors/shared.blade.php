apiVersion: v1
kind: Secret
metadata:
  name: glitchtip-admin
  namespace: larakube-shared
type: Opaque
data:
  password: {{ base64_encode($adminPassword) }}
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
          image: glitchtip/glitchtip:v4
          command: ["./manage.py", "migrate"]
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: glitchtip-admin
                  key: database-url
            - name: CELERY_BROKER_URL
              valueFrom:
                secretKeyRef:
                  name: glitchtip-admin
                  key: redis-url
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: glitchtip-admin
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
          image: glitchtip/glitchtip:v4
          ports:
            - containerPort: 8000
              name: http
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: glitchtip-admin
                  key: database-url
            - name: CELERY_BROKER_URL
              valueFrom:
                secretKeyRef:
                  name: glitchtip-admin
                  key: redis-url
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: glitchtip-admin
                  key: secret-key
            - name: GLITCHTIP_DOMAIN
              value: "https://{{ $host }}"
            - name: GLITCHTIP_ADMIN_EMAIL
              value: "admin@larakube.local"
            - name: GLITCHTIP_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: glitchtip-admin
                  key: password
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
          image: glitchtip/glitchtip:v4
          command: ["celery", "-A", "glitchtip", "worker", "-B"]
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: glitchtip-admin
                  key: database-url
            - name: CELERY_BROKER_URL
              valueFrom:
                secretKeyRef:
                  name: glitchtip-admin
                  key: redis-url
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: glitchtip-admin
                  key: secret-key
            - name: GLITCHTIP_DOMAIN
              value: "https://{{ $host }}"
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
