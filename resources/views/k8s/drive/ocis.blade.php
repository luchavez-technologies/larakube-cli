apiVersion: apps/v1
kind: Deployment
metadata:
  name: drive-ocis
  namespace: larakube-shared
  labels:
    app: drive-ocis
spec:
  replicas: 1
  selector:
    matchLabels:
      app: drive-ocis
  template:
    metadata:
      labels:
        app: drive-ocis
    spec:
      containers:
        - name: ocis
          image: owncloud/ocis:latest
          command: ["ocis", "init"]
          args: []
          # Normally oCIS runs init first, but we configure via env so we just run server.
          # Actually, 'ocis server' is the command to start everything.
          command: ["ocis"]
          args: ["server"]
          env:
            - name: OCIS_URL
              value: "https://{{ $host }}"
            - name: OCIS_INSECURE
              value: "false"
            - name: PROXY_HTTP_ADDR
              value: "0.0.0.0:80"
            - name: PROXY_TLS
              value: "false"
            - name: OCIS_LOG_LEVEL
              value: "info"
            - name: ADMIN_USERNAME
              value: "admin"
            - name: ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: OCIS_MACHINE_AUTH_API_KEY
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: machine-auth-api-key
@if (! $noPlex && $s3Creds)
            - name: OCIS_DEFAULT_STORAGE_SYSTEM
              value: "s3"
            - name: STORAGE_SYSTEM_S3_ENDPOINT
              value: "http://seaweedfs.{{ $plexNamespace }}.svc.cluster.local:8333"
            - name: STORAGE_SYSTEM_S3_REGION
              value: "us-east-1"
            - name: STORAGE_SYSTEM_S3_BUCKET
              value: "drive-ocis"
            - name: STORAGE_SYSTEM_S3_ACCESS_KEY
              value: "{{ $s3Creds['access'] }}"
            - name: STORAGE_SYSTEM_S3_SECRET_KEY
              value: "{{ $s3Creds['secret'] }}"
@else
            - name: OCIS_DEFAULT_STORAGE_SYSTEM
              value: "posix"
            - name: STORAGE_SYSTEM_POSIX_ROOT
              value: "/var/lib/ocis/data"
@endif
          ports:
            - containerPort: 80
@if ($noPlex || ! $s3Creds)
          volumeMounts:
            - name: drive-ocis-data
              mountPath: /var/lib/ocis
@endif
@if ($noPlex || ! $s3Creds)
      volumes:
        - name: drive-ocis-data
          persistentVolumeClaim:
            claimName: drive-ocis-storage
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: drive-ocis
  namespace: larakube-shared
spec:
  selector:
    app: drive-ocis
  ports:
    - port: 80
      targetPort: 80
@if ($noPlex || ! $s3Creds)
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: drive-ocis-storage
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 10Gi
@endif
---
@include('k8s.drive.ingress', ['engine' => 'ocis'])
