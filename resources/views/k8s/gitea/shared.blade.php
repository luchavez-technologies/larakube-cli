apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: gitea-data
  namespace: larakube-shared
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 5Gi
---
apiVersion: v1
kind: Secret
metadata:
  name: gitea-admin
  namespace: larakube-shared
type: Opaque
data:
  username: {{ base64_encode('larakube') }}
  password: {{ base64_encode($adminPassword) }}
  registry-token: {{ base64_encode($registryToken) }}
  runner-token: {{ base64_encode($runnerToken) }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: gitea
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: gitea
  template:
    metadata:
      labels:
        app: gitea
    spec:
      containers:
        - name: gitea
          image: gitea/gitea:1.22-alpine
          ports:
            - containerPort: 3000
              name: http
            - containerPort: 22
              name: ssh
          env:
            - name: GITEA__database__DB_TYPE
              value: sqlite3
            - name: GITEA__database__PATH
              value: /data/gitea/gitea.db
            - name: GITEA__security__INSTALL_LOCK
              value: "true"
            - name: GITEA__server__ROOT_URL
              value: "https://{{ $host }}/"
            - name: GITEA__server__DOMAIN
              value: "{{ $host }}"
            - name: GITEA__server__SSH_DOMAIN
              value: "{{ $host }}"
            - name: GITEA__server__SSH_PORT
              value: "2222"
            - name: GITEA__server__SSH_LISTEN_PORT
              value: "22"
            - name: GITEA__server__LFS_START_SERVER
              value: "true"
            - name: GITEA__actions__ENABLED
              value: "true"
            - name: GITEA__actions__DEFAULT_ACTIONS_URL
              value: github
            - name: GITEA__security__SECRET_KEY
              value: "{{ $secretKey }}"
            - name: GITEA__security__INTERNAL_TOKEN
              value: "{{ $internalToken }}"
            - name: GITEA__server__LFS_JWT_SECRET
              value: "{{ $jwtSecret }}"
            @if (! $noPlex)
            - name: GITEA__storage__STORAGE_TYPE
              value: minio
            - name: GITEA__storage__MINIO_ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: GITEA__storage__MINIO_ACCESS_KEY_ID
              value: "{{ $s3AccessKey }}"
            - name: GITEA__storage__MINIO_SECRET_ACCESS_KEY
              value: "{{ $s3SecretKey }}"
            - name: GITEA__storage__MINIO_BUCKET
              value: gitea-storage
            - name: GITEA__storage__MINIO_USE_SSL
              value: "false"
            - name: GITEA__storage__MINIO_INSECURE_SKIP_VERIFY
              value: "true"

            - name: GITEA__packages__STORAGE_TYPE
              value: minio
            - name: GITEA__packages__MINIO_ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: GITEA__packages__MINIO_ACCESS_KEY_ID
              value: "{{ $s3AccessKey }}"
            - name: GITEA__packages__MINIO_SECRET_ACCESS_KEY
              value: "{{ $s3SecretKey }}"
            - name: GITEA__packages__MINIO_BUCKET
              value: gitea-packages
            - name: GITEA__packages__MINIO_USE_SSL
              value: "false"
            - name: GITEA__packages__MINIO_INSECURE_SKIP_VERIFY
              value: "true"

            - name: GITEA__lfs__STORAGE_TYPE
              value: minio
            - name: GITEA__lfs__MINIO_ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: GITEA__lfs__MINIO_ACCESS_KEY_ID
              value: "{{ $s3AccessKey }}"
            - name: GITEA__lfs__MINIO_SECRET_ACCESS_KEY
              value: "{{ $s3SecretKey }}"
            - name: GITEA__lfs__MINIO_BUCKET
              value: gitea-lfs
            - name: GITEA__lfs__MINIO_USE_SSL
              value: "false"
            - name: GITEA__lfs__MINIO_INSECURE_SKIP_VERIFY
              value: "true"
            @endif
          volumeMounts:
            - name: gitea-data
              mountPath: /data
      volumes:
        - name: gitea-data
          persistentVolumeClaim:
            claimName: gitea-data
---
apiVersion: v1
kind: Service
metadata:
  name: gitea-http
  namespace: larakube-shared
spec:
  selector:
    app: gitea
  ports:
    - protocol: TCP
      port: 3000
      targetPort: 3000
  type: ClusterIP
---
apiVersion: v1
kind: Service
metadata:
  name: gitea-ssh
  namespace: larakube-shared
spec:
  selector:
    app: gitea
  ports:
    - protocol: TCP
      port: 2222
      targetPort: 22
  type: LoadBalancer
---
@if ($runnerToken !== 'pending')
apiVersion: apps/v1
kind: Deployment
metadata:
  name: gitea-runner
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: gitea-runner
  template:
    metadata:
      labels:
        app: gitea-runner
    spec:
      containers:
        - name: runner
          image: gitea/act_runner:latest
          env:
            - name: GITEA_INSTANCE_URL
              value: "http://gitea-http:3000"
            - name: GITEA_RUNNER_REGISTRATION_TOKEN
              valueFrom:
                secretKeyRef:
                  name: gitea-admin
                  key: runner-token
          volumeMounts:
            - name: docker-sock
              mountPath: /var/run/docker.sock
      volumes:
        - name: docker-sock
          hostPath:
            path: /var/run/docker.sock
---
@endif
@include('k8s.gitea.ingress')
