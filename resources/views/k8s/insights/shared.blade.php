@if($noPlex)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: insights-storage
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 5Gi
---
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: insights-metabase
  namespace: larakube-shared
  labels:
    app: insights-metabase
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: insights-metabase
  template:
    metadata:
      labels:
        app: insights-metabase
    spec:
      containers:
        - name: metabase
          image: metabase/metabase:latest
          env:
            - name: MB_ENCRYPTION_SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: insights-secrets
                  key: encryption-key
@if($noPlex)
            - name: MB_DB_FILE
              value: /metabase-data/metabase.db
@else
            - name: MB_DB_TYPE
              value: postgres
            - name: MB_DB_DBNAME
              value: metabase
            - name: MB_DB_PORT
              value: "5432"
            - name: MB_DB_USER
              value: metabase
            - name: MB_DB_HOST
              value: postgres.{{ $plexNamespace }}.svc.cluster.local
            - name: MB_DB_PASS
              valueFrom:
                secretKeyRef:
                  name: insights-secrets
                  key: db-password
@endif
          ports:
            - containerPort: 3000
              name: http
          readinessProbe:
            httpGet:
              path: /api/health
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 10
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /api/health
              port: 3000
            initialDelaySeconds: 60
            periodSeconds: 15
            failureThreshold: 6
          volumeMounts:
            - name: storage
              mountPath: /metabase-data
      volumes:
        - name: storage
@if($noPlex)
          persistentVolumeClaim:
            claimName: insights-storage
@else
          emptyDir: {}
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: insights-metabase
  namespace: larakube-shared
spec:
  selector:
    app: insights-metabase
  ports:
    - protocol: TCP
      port: 3000
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.insights.ingress')
