apiVersion: apps/v1
kind: Deployment
metadata:
  name: support-chatwoot
  namespace: larakube-shared
  labels:
    app: support-chatwoot
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: support-chatwoot
  template:
    metadata:
      labels:
        app: support-chatwoot
    spec:
      containers:
        - name: chatwoot
          image: chatwoot/chatwoot:latest
          command:
            - docker/entrypoints/render.sh
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: FRONTEND_URL
              value: "https://{{ $host }}"
            - name: SECRET_KEY_BASE
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-secrets
                  key: secret-key-base
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-secrets
                  key: db-password
            - name: POSTGRES_DATABASE
              value: "support_chatwoot"
            - name: POSTGRES_USERNAME
              value: "support_chatwoot"
            - name: POSTGRES_HOST
              value: "postgres.{{ $plexNamespace }}.svc.cluster.local"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: RAILS_ENV
              value: "production"
            - name: FORCE_SSL
              value: "true"
            - name: ENABLE_ACCOUNT_SIGNUP
              value: "false"
            # SMTP Setup
            - name: SMTP_ADDRESS
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_ADDRESS
                  optional: true
            - name: SMTP_PORT
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_PORT
                  optional: true
            - name: SMTP_USERNAME
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_USERNAME
                  optional: true
            - name: SMTP_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_PASSWORD
                  optional: true
            - name: MAILER_SENDER_EMAIL
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: MAILER_SENDER_EMAIL
                  optional: true
            - name: SMTP_DOMAIN
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_DOMAIN
                  optional: true
            - name: SMTP_ENABLE_STARTTLS_AUTO
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_ENABLE_STARTTLS_AUTO
                  optional: true
            # OIDC
            - name: OIDC_ISSUER
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-oidc
                  key: OIDC_ISSUER
                  optional: true
            - name: OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-oidc
                  key: OIDC_CLIENT_ID
                  optional: true
            - name: OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-oidc
                  key: OIDC_CLIENT_SECRET
                  optional: true
          readinessProbe:
            httpGet:
              path: /api
              port: 3000
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /api
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 15
            timeoutSeconds: 5
          resources:
            requests:
              memory: 256Mi
              cpu: 50m
            limits:
              memory: 512Mi
              cpu: 200m
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: support-chatwoot-worker
  namespace: larakube-shared
  labels:
    app: support-chatwoot-worker
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: support-chatwoot-worker
  template:
    metadata:
      labels:
        app: support-chatwoot-worker
    spec:
      containers:
        - name: sidekiq
          image: chatwoot/chatwoot:latest
          command:
            - bundle
            - exec
            - sidekiq
            - -C
            - config/sidekiq.yml
          env:
            - name: FRONTEND_URL
              value: "https://{{ $host }}"
            - name: SECRET_KEY_BASE
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-secrets
                  key: secret-key-base
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-secrets
                  key: db-password
            - name: POSTGRES_DATABASE
              value: "support_chatwoot"
            - name: POSTGRES_USERNAME
              value: "support_chatwoot"
            - name: POSTGRES_HOST
              value: "postgres.{{ $plexNamespace }}.svc.cluster.local"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: RAILS_ENV
              value: "production"
            # SMTP Setup
            - name: SMTP_ADDRESS
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_ADDRESS
                  optional: true
            - name: SMTP_PORT
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_PORT
                  optional: true
            - name: SMTP_USERNAME
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_USERNAME
                  optional: true
            - name: SMTP_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_PASSWORD
                  optional: true
            - name: MAILER_SENDER_EMAIL
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: MAILER_SENDER_EMAIL
                  optional: true
            - name: SMTP_DOMAIN
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_DOMAIN
                  optional: true
            - name: SMTP_ENABLE_STARTTLS_AUTO
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-smtp
                  key: SMTP_ENABLE_STARTTLS_AUTO
                  optional: true
            # OIDC
            - name: OIDC_ISSUER
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-oidc
                  key: OIDC_ISSUER
                  optional: true
            - name: OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-oidc
                  key: OIDC_CLIENT_ID
                  optional: true
            - name: OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: support-chatwoot-oidc
                  key: OIDC_CLIENT_SECRET
                  optional: true
          resources:
            requests:
              memory: 256Mi
              cpu: 50m
            limits:
              memory: 512Mi
              cpu: 200m
---
apiVersion: v1
kind: Service
metadata:
  name: support
  namespace: larakube-shared
spec:
  selector:
    app: support-chatwoot
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.support.ingress')
