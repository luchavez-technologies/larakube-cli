apiVersion: v1
kind: Secret
metadata:
  name: infisical-secrets
  namespace: larakube-secrets
type: Opaque
data:
  encryption-key: {{ base64_encode($encryptionKey) }}
  auth-secret: {{ base64_encode($authSecret) }}
@if ($noPlex)
  db-connection-uri: {{ base64_encode("postgres://infisical:{$dbPassword}@infisical-db:5432/infisical") }}
  redis-url: {{ base64_encode("redis://infisical-cache:6379/0") }}
@else
  db-connection-uri: {{ base64_encode("postgres://infisical:{$dbPassword}@postgres.{$plexNamespace}.svc.cluster.local:5432/infisical") }}
  redis-url: {{ base64_encode("redis://redis.{$plexNamespace}.svc.cluster.local:6379/14") }}
@endif
---
@if ($noPlex)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: infisical-db-storage
  namespace: larakube-secrets
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 2Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: infisical-db
  namespace: larakube-secrets
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: infisical-db
  template:
    metadata:
      labels:
        app: infisical-db
    spec:
      containers:
        - name: postgres
          image: postgres:15-alpine
          ports:
            - containerPort: 5432
          env:
            - name: POSTGRES_DB
              value: infisical
            - name: POSTGRES_USER
              value: infisical
            - name: POSTGRES_PASSWORD
              value: "{{ $dbPassword }}"
          volumeMounts:
            - name: storage
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: infisical-db-storage
---
apiVersion: v1
kind: Service
metadata:
  name: infisical-db
  namespace: larakube-secrets
spec:
  selector:
    app: infisical-db
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
  type: ClusterIP
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: infisical-cache
  namespace: larakube-secrets
spec:
  replicas: 1
  selector:
    matchLabels:
      app: infisical-cache
  template:
    metadata:
      labels:
        app: infisical-cache
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
  name: infisical-cache
  namespace: larakube-secrets
spec:
  selector:
    app: infisical-cache
  ports:
    - protocol: TCP
      port: 6379
      targetPort: 6379
  type: ClusterIP
---
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: infisical-backend
  namespace: larakube-secrets
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: infisical-backend
  template:
    metadata:
      labels:
        app: infisical-backend
    spec:
      containers:
        - name: infisical
          image: infisical/infisical:latest
          ports:
            - containerPort: 8080
              name: http
          env:
            - name: ENCRYPTION_KEY
              valueFrom:
                secretKeyRef:
                  name: infisical-secrets
                  key: encryption-key
            - name: AUTH_SECRET
              valueFrom:
                secretKeyRef:
                  name: infisical-secrets
                  key: auth-secret
            - name: DB_CONNECTION_URI
              valueFrom:
                secretKeyRef:
                  name: infisical-secrets
                  key: db-connection-uri
            - name: REDIS_URL
              valueFrom:
                secretKeyRef:
                  name: infisical-secrets
                  key: redis-url
            - name: SITE_URL
              value: "https://{{ $host }}"
          readinessProbe:
            tcpSocket:
              port: 8080
            initialDelaySeconds: 15
            periodSeconds: 10
---
apiVersion: v1
kind: Service
metadata:
  name: infisical-backend
  namespace: larakube-secrets
spec:
  selector:
    app: infisical-backend
  ports:
    - protocol: TCP
      port: 8080
      targetPort: 8080
  type: ClusterIP
---
@include('k8s.secrets.ingress')
---
@include('k8s.secrets.crds')
---
@include('k8s.secrets.operator')
