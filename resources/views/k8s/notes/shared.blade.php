apiVersion: apps/v1
kind: Deployment
metadata:
  name: notes-outline
  namespace: larakube-shared
  labels:
    app: notes-outline
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: notes-outline
  template:
    metadata:
      labels:
        app: notes-outline
    spec:
      containers:
        - name: outline
          image: outlinewiki/outline:latest
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: NODE_ENV
              value: production
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: notes-secrets
                  key: secret-key
            - name: UTILS_SECRET
              valueFrom:
                secretKeyRef:
                  name: notes-secrets
                  key: utils-secret
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: notes-secrets
                  key: db-password
            - name: DATABASE_URL
              value: "postgres://outline:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/outline"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: URL
              value: "https://{{ $host }}"
            - name: PORT
              value: "3000"
            - name: FORCE_HTTPS
              value: "true"
            - name: ENABLE_UPDATES
              value: "false"
            - name: DEFAULT_LANGUAGE
              value: en_US
            - name: FILE_STORAGE
              value: s3
            - name: AWS_ACCESS_KEY_ID
              value: "{{ $s3AccessKey }}"
            - name: AWS_SECRET_ACCESS_KEY
              value: "{{ $s3SecretKey }}"
            - name: AWS_REGION
              value: us-east-1
            - name: AWS_S3_UPLOAD_BUCKET_NAME
              value: "{{ $s3Bucket }}"
            - name: AWS_S3_UPLOAD_BUCKET_URL
              value: "{{ $s3Endpoint }}"
            - name: AWS_S3_ACL
              value: private
            - name: AWS_S3_FORCE_PATH_STYLE
              value: "true"
            - name: FILE_STORAGE_UPLOAD_MAX_SIZE
              value: "26214400"
            - name: PGSSLMODE
              value: disable
            - name: OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: notes-outline-oidc
                  key: client-id
                  optional: true
            - name: OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: notes-outline-oidc
                  key: client-secret
                  optional: true
            - name: OIDC_AUTH_URI
              valueFrom:
                secretKeyRef:
                  name: notes-outline-oidc
                  key: auth-url
                  optional: true
            - name: OIDC_TOKEN_URI
              valueFrom:
                secretKeyRef:
                  name: notes-outline-oidc
                  key: token-url
                  optional: true
            - name: OIDC_USERINFO_URI
              valueFrom:
                secretKeyRef:
                  name: notes-outline-oidc
                  key: userinfo-url
                  optional: true
            - name: OIDC_DISPLAY_NAME
              value: "SSO"
            - name: OIDC_SCOPES
              value: "openid profile email"
          readinessProbe:
            httpGet:
              path: /_health
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /_health
              port: 3000
            initialDelaySeconds: 60
            periodSeconds: 15
            timeoutSeconds: 5
          resources:
            requests:
              memory: 256Mi
              cpu: 100m
            limits:
              memory: 512Mi
              cpu: 250m
---
apiVersion: v1
kind: Service
metadata:
  name: notes
  namespace: larakube-shared
spec:
  selector:
    app: notes-outline
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.notes.ingress')
