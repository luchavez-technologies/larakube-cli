apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: desk-storage
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 5Gi
@if($noPlex)
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: desk-freescout-db-storage
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
  name: desk-freescout-db
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: desk-freescout-db
  template:
    metadata:
      labels:
        app: desk-freescout-db
    spec:
      containers:
        - name: postgres
          image: postgres:15-alpine
          env:
            - name: POSTGRES_USER
              value: freescout
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: desk-secrets
                  key: db-password
            - name: POSTGRES_DB
              value: freescout
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
          volumeMounts:
            - name: storage
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: desk-freescout-db-storage
---
apiVersion: v1
kind: Service
metadata:
  name: desk-freescout-db
  namespace: larakube-shared
spec:
  selector:
    app: desk-freescout-db
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
@endif
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: desk-freescout
  namespace: larakube-shared
  labels:
    app: desk-freescout
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: desk-freescout
  template:
    metadata:
      labels:
        app: desk-freescout
    spec:
      containers:
        - name: freescout
          image: tiredofit/freescout:php8.3-1.17.159
          env:
            - name: DB_TYPE
              value: pgsql
            - name: DB_HOST
@if($noPlex)
              value: desk-freescout-db
@else
              value: postgres.{{ $plexNamespace }}.svc.cluster.local
@endif
            - name: DB_PORT
              value: "5432"
            - name: DB_NAME
              value: freescout
            - name: DB_USER
              value: freescout
            - name: DB_PASS
              valueFrom:
                secretKeyRef:
                  name: desk-secrets
                  key: db-password
            - name: SITE_URL
              value: https://{{ $host }}
            - name: ADMIN_EMAIL
              value: {{ $adminEmail }}
            - name: ADMIN_PASS
              valueFrom:
                secretKeyRef:
                  name: desk-secrets
                  key: admin-password
            - name: ENABLE_SSL_PROXY
              value: "TRUE"
            - name: TIMEZONE
              value: "UTC"
          ports:
            - containerPort: 80
              name: http
          readinessProbe:
            tcpSocket:
              port: 80
            initialDelaySeconds: 20
            periodSeconds: 10
          livenessProbe:
            tcpSocket:
              port: 80
            initialDelaySeconds: 60
            periodSeconds: 15
          volumeMounts:
            - name: storage
              mountPath: /data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: desk-storage
---
apiVersion: v1
kind: Service
metadata:
  name: desk-freescout
  namespace: larakube-shared
spec:
  selector:
    app: desk-freescout
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
---
@include('k8s.desk.ingress')
