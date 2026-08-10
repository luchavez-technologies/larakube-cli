apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deployName }}
  namespace: larakube-shared
  labels:
    app: {{ $deployName }}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $deployName }}
  template:
    metadata:
      labels:
        app: {{ $deployName }}
    spec:
      containers:
        - name: directus
          image: directus/directus:12
          ports:
            - containerPort: 8055
              name: http
          env:
            - name: PUBLIC_URL
              value: "https://{{ $host }}"
            - name: SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: secret
            - name: KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: key
            - name: ADMIN_EMAIL
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: admin-email
            - name: ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: admin-password
            - name: DB_CLIENT
              value: "pg"
            - name: DB_HOST
              value: "postgres.{{ $plexNamespace }}.svc.cluster.local"
            - name: DB_PORT
              value: "5432"
            - name: DB_DATABASE
              value: "{{ $dbName }}"
            - name: DB_USER
              value: "{{ $dbName }}"
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: CACHE_ENABLED
              value: "true"
            - name: CACHE_STORE
              value: "redis"
            - name: REDIS
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: STORAGE_LOCATIONS
              value: "s3"
            - name: STORAGE_S3_DRIVER
              value: "s3"
            - name: STORAGE_S3_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-key
            - name: STORAGE_S3_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-secret
            - name: STORAGE_S3_BUCKET
              value: "{{ $bucket }}"
            - name: STORAGE_S3_ENDPOINT
              value: "http://seaweedfs.{{ $plexNamespace }}.svc.cluster.local:8333"
            - name: STORAGE_S3_FORCE_PATH_STYLE
              value: "true"
            - name: EMAIL_TRANSPORT
              value: "smtp"
            - name: EMAIL_SMTP_HOST
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: EMAIL_SMTP_HOST
                  optional: true
            - name: EMAIL_SMTP_PORT
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: EMAIL_SMTP_PORT
                  optional: true
            - name: EMAIL_SMTP_USER
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: EMAIL_SMTP_USER
                  optional: true
            - name: EMAIL_SMTP_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: EMAIL_SMTP_PASSWORD
                  optional: true
            - name: EMAIL_FROM
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: EMAIL_FROM
                  optional: true
            - name: AUTH_PROVIDERS
              value: "{{ $authProviders }}"
            - name: AUTH_ZITADEL_DRIVER
              value: "openid"
            - name: AUTH_ZITADEL_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: AUTH_ZITADEL_CLIENT_ID
                  optional: true
            - name: AUTH_ZITADEL_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: AUTH_ZITADEL_CLIENT_SECRET
                  optional: true
            - name: AUTH_ZITADEL_ISSUER
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: AUTH_ZITADEL_ISSUER
                  optional: true
            - name: AUTH_ZITADEL_AUTHORIZE_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: AUTH_ZITADEL_AUTHORIZE_URL
                  optional: true
            - name: AUTH_ZITADEL_ACCESS_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: AUTH_ZITADEL_ACCESS_URL
                  optional: true
            - name: AUTH_ZITADEL_PROFILE_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: AUTH_ZITADEL_PROFILE_URL
                  optional: true
            - name: AUTH_ZITADEL_SCOPE
              value: "openid email profile"
            - name: AUTH_ZITADEL_IDENTIFIER_KEY
              value: "email"
            - name: AUTH_ZITADEL_ALLOW_PUBLIC_REGISTRATION
              value: "true"
          startupProbe:
            httpGet:
              path: /server/ping
              port: 8055
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /server/ping
              port: 8055
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /server/ping
              port: 8055
            initialDelaySeconds: 30
            periodSeconds: 15
            timeoutSeconds: 5
          resources:
            requests:
              memory: 256Mi
              cpu: 100m
            limits:
              memory: 1Gi
              cpu: 500m
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $deployName }}
  namespace: larakube-shared
spec:
  selector:
    app: {{ $deployName }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 8055
  type: ClusterIP
---
@include('k8s.data.ingress', ['ingressName' => $deployName, 'serviceName' => $deployName])
