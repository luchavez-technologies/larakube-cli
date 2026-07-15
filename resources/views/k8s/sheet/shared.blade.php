@if($noPlex)
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
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: sheet-nocodb
  namespace: larakube-shared
  labels:
    app: sheet-nocodb
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: sheet-nocodb
  template:
    metadata:
      labels:
        app: sheet-nocodb
    spec:
      containers:
        - name: nocodb
          image: nocodb/nocodb:2026.06.1
          env:
@if($noPlex)
            - name: NC_DB
              value: sqlite:////usr/app/data/noco.db
@else
            - name: NC_DB
              value: pg://postgres.{{ $plexNamespace }}.svc.cluster.local:5432?u=nocodb&d=nocodb&p=$(DB_PASSWORD)
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: sheet-secrets
                  key: db-password
@endif
          ports:
            - containerPort: 8080
              name: http
          readinessProbe:
            tcpSocket:
              port: 8080
            initialDelaySeconds: 10
            periodSeconds: 5
          livenessProbe:
            tcpSocket:
              port: 8080
            initialDelaySeconds: 15
            periodSeconds: 10
          volumeMounts:
            - name: storage
              mountPath: /usr/app/data
      volumes:
        - name: storage
@if($noPlex)
          persistentVolumeClaim:
            claimName: sheet-storage
@else
          emptyDir: {}
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: sheet-nocodb
  namespace: larakube-shared
spec:
  selector:
    app: sheet-nocodb
  ports:
    - protocol: TCP
      port: 8080
      targetPort: 8080
  type: ClusterIP
---
@include('k8s.sheet.ingress')
