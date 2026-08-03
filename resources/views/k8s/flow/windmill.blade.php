@if($noPlex)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: flow-windmill-db-storage
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
  name: flow-windmill-db
  namespace: larakube-shared
spec:
  replicas: 1
  selector:
    matchLabels:
      app: flow-windmill-db
  template:
    metadata:
      labels:
        app: flow-windmill-db
    spec:
      containers:
        - name: postgres
          image: postgres:15-alpine
          env:
            - name: POSTGRES_USER
              value: windmill
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: flow-secrets
                  key: db-password
            - name: POSTGRES_DB
              value: windmill
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
          volumeMounts:
            - name: storage
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: flow-windmill-db-storage
---
apiVersion: v1
kind: Service
metadata:
  name: flow-windmill-db
  namespace: larakube-shared
spec:
  selector:
    app: flow-windmill-db
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
---
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: flow-windmill
  namespace: larakube-shared
  labels:
    app: flow-windmill
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: flow-windmill
  template:
    metadata:
      labels:
        app: flow-windmill
    spec:
      containers:
        - name: windmill-server
          image: ghcr.io/windmill-labs/windmill:1.770.0
          env:
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: flow-secrets
                  key: db-password
@if($noPlex)
            - name: DATABASE_URL
              value: postgres://windmill:$(DB_PASSWORD)@flow-windmill-db:5432/windmill
@else
            - name: DATABASE_URL
              value: postgres://windmill:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/windmill
@endif
            - name: MODE
              value: server
            - name: BASE_URL
              value: https://{{ $host }}
          ports:
            - containerPort: 8000
              name: http
          readinessProbe:
            httpGet:
              path: /api/health/status
              port: 8000
            initialDelaySeconds: 15
            periodSeconds: 10
        - name: windmill-worker
          image: ghcr.io/windmill-labs/windmill:1.770.0
          env:
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: flow-secrets
                  key: db-password
@if($noPlex)
            - name: DATABASE_URL
              value: postgres://windmill:$(DB_PASSWORD)@flow-windmill-db:5432/windmill
@else
            - name: DATABASE_URL
              value: postgres://windmill:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/windmill
@endif
            - name: MODE
              value: worker
            - name: WORKER_GROUP
              value: default
        - name: windmill-lsp
          image: ghcr.io/windmill-labs/windmill-lsp:1.134.1
          ports:
            - containerPort: 3001
---
apiVersion: v1
kind: Service
metadata:
  name: flow-windmill
  namespace: larakube-shared
spec:
  selector:
    app: flow-windmill
  ports:
    - protocol: TCP
      port: 8000
      targetPort: 8000
      name: http
    - protocol: TCP
      port: 3001
      targetPort: 3001
      name: lsp
  type: ClusterIP
---
@include('k8s.flow.ingress', ['serviceName' => 'flow-windmill', 'servicePort' => 8000])
