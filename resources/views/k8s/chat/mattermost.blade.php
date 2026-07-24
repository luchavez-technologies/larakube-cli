apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: chat-storage
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
  name: chat-mattermost-db-storage
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
  name: chat-mattermost-db
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: chat-mattermost-db
  template:
    metadata:
      labels:
        app: chat-mattermost-db
    spec:
      containers:
        - name: postgres
          image: postgres:15-alpine
          env:
            - name: POSTGRES_USER
              value: mattermost
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: chat-secrets
                  key: db-password
            - name: POSTGRES_DB
              value: mattermost
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
          volumeMounts:
            - name: storage
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: chat-mattermost-db-storage
---
apiVersion: v1
kind: Service
metadata:
  name: chat-mattermost-db
  namespace: larakube-shared
spec:
  selector:
    app: chat-mattermost-db
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
@endif
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: chat-mattermost
  namespace: larakube-shared
  labels:
    app: chat-mattermost
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: chat-mattermost
  template:
    metadata:
      labels:
        app: chat-mattermost
    spec:
      containers:
        - name: mattermost
          image: mattermost/mattermost-team-edition:10.5
          env:
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: chat-secrets
                  key: db-password
            - name: MM_SQLSETTINGS_DRIVERNAME
              value: postgres
            - name: MM_SQLSETTINGS_DATASOURCE
@if($noPlex)
              value: "postgres://mattermost:$(DB_PASSWORD)@chat-mattermost-db:5432/mattermost?sslmode=disable&connect_timeout=10"
@else
              value: "postgres://mattermost:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/mattermost?sslmode=disable&connect_timeout=10"
@endif
            - name: MM_SERVICESETTINGS_SITEURL
              value: https://{{ $host }}
            - name: MM_SERVICESETTINGS_LISTENADDRESS
              value: ":8065"
@if($noPlex)
            - name: MM_FILESETTINGS_DRIVERNAME
              value: local
            - name: MM_FILESETTINGS_DIRECTORY
              value: /mattermost/data
@else
            - name: MM_FILESETTINGS_DRIVERNAME
              value: amazons3
            - name: MM_FILESETTINGS_AMAZONS3ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: MM_FILESETTINGS_AMAZONS3BUCKET
              value: "{{ $s3Bucket }}"
            - name: MM_FILESETTINGS_AMAZONS3ACCESSKEYID
              value: "{{ $s3AccessKey }}"
            - name: MM_FILESETTINGS_AMAZONS3SECRETACCESSKEY
              value: "{{ $s3SecretKey }}"
            - name: MM_FILESETTINGS_AMAZONS3SSL
              value: "false"
@endif
          ports:
            - containerPort: 8065
              name: http
          readinessProbe:
            httpGet:
              path: /api/v4/system/ping
              port: 8065
            initialDelaySeconds: 20
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: /api/v4/system/ping
              port: 8065
            initialDelaySeconds: 60
            periodSeconds: 15
          volumeMounts:
            - name: storage
              mountPath: /mattermost/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: chat-storage
---
apiVersion: v1
kind: Service
metadata:
  name: chat-mattermost
  namespace: larakube-shared
spec:
  selector:
    app: chat-mattermost
  ports:
    - protocol: TCP
      port: 8065
      targetPort: 8065
  type: ClusterIP
---
@include('k8s.chat.ingress')
