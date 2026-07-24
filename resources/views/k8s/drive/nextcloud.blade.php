apiVersion: apps/v1
kind: Deployment
metadata:
  name: drive-nextcloud
  namespace: larakube-shared
  labels:
    app: drive-nextcloud
spec:
  replicas: 1
  selector:
    matchLabels:
      app: drive-nextcloud
  template:
    metadata:
      labels:
        app: drive-nextcloud
    spec:
      containers:
        - name: nextcloud
          image: nextcloud:latest
          env:
            - name: NEXTCLOUD_ADMIN_USER
              value: "admin"
            - name: NEXTCLOUD_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: NEXTCLOUD_TRUSTED_DOMAINS
              value: "{{ $host }}"
@if (! $noPlex)
            - name: POSTGRES_DB
              value: "drive"
            - name: POSTGRES_USER
              value: "drive"
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: db-password
            - name: POSTGRES_HOST
              value: "postgres.{{ $plexNamespace }}.svc.cluster.local:5432"
            - name: REDIS_HOST
              value: "redis.{{ $plexNamespace }}.svc.cluster.local"
            - name: REDIS_HOST_PORT
              value: "6379"
            - name: REDIS_DB
              value: "{{ $redisIndex }}"
@else
            - name: SQLITE_DATABASE
              value: "nextcloud"
@endif
          ports:
            - containerPort: 80
          volumeMounts:
            - name: drive-nextcloud-data
              mountPath: /var/www/html
      volumes:
        - name: drive-nextcloud-data
          persistentVolumeClaim:
            claimName: drive-nextcloud-storage
---
apiVersion: v1
kind: Service
metadata:
  name: drive-nextcloud
  namespace: larakube-shared
spec:
  selector:
    app: drive-nextcloud
  ports:
    - port: 80
      targetPort: 80
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: drive-nextcloud-storage
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 20Gi
---
@include('k8s.drive.ingress', ['engine' => 'nextcloud'])
