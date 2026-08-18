apiVersion: apps/v1
kind: Deployment
metadata:
  name: sign-documenso
  namespace: larakube-shared
  labels:
    app: sign-documenso
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: sign-documenso
  template:
    metadata:
      labels:
        app: sign-documenso
    spec:
      containers:
        - name: documenso
          image: documenso/documenso:v2.16.0
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: NEXT_PUBLIC_WEBAPP_URL
              value: "https://{{ $host }}"
            - name: NEXT_PUBLIC_MARKETING_URL
              value: "https://{{ $host }}"
            - name: NEXTAUTH_URL
              value: "https://{{ $host }}/api/auth"
            - name: NEXTAUTH_SECRET
              valueFrom:
                secretKeyRef:
                  name: sign-secrets
                  key: nextauth-secret
            - name: NEXT_PRIVATE_ENCRYPTION_KEY
              valueFrom:
                secretKeyRef:
                  name: sign-secrets
                  key: encryption-key
            - name: NEXT_PRIVATE_ENCRYPTION_SECONDARY_KEY
              valueFrom:
                secretKeyRef:
                  name: sign-secrets
                  key: encryption-secondary-key
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: sign-secrets
                  key: db-password
            - name: NEXT_PRIVATE_DATABASE_URL
              value: "postgres://sign_documenso:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/sign_documenso"
            # Prisma Migrate connects via directUrl to bypass a connection pooler.
            # We talk straight to Commons Postgres (no pooler), so it's identical.
            - name: NEXT_PRIVATE_DIRECT_DATABASE_URL
              value: "postgres://sign_documenso:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/sign_documenso"
            # Store signed PDFs on the Commons SeaweedFS (S3), not the default
            # `database` transport. FORCE_PATH_STYLE is required for non-AWS S3.
            - name: NEXT_PUBLIC_UPLOAD_TRANSPORT
              value: "s3"
            - name: NEXT_PRIVATE_UPLOAD_ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: NEXT_PRIVATE_UPLOAD_FORCE_PATH_STYLE
              value: "true"
            - name: NEXT_PRIVATE_UPLOAD_REGION
              value: "us-east-1"
            - name: NEXT_PRIVATE_UPLOAD_BUCKET
              value: "{{ $s3Bucket }}"
            - name: NEXT_PRIVATE_UPLOAD_ACCESS_KEY_ID
              value: "{{ $s3AccessKey }}"
            - name: NEXT_PRIVATE_UPLOAD_SECRET_ACCESS_KEY
              value: "{{ $s3SecretKey }}"
            # mail:wire sets these two as plain literals (kubectl set env
            # NAME=value), never through the sign-smtp Secret — must
            # stay literals here too, or a future kubectl apply conflicts with
            # mail:wire's live value (see ClusterTool::SIGN's smtpEnv()).
            - name: NEXT_PRIVATE_SMTP_TRANSPORT
              value: "smtp-auth"
            - name: NEXT_PRIVATE_SMTP_SECURE
              value: "true"
            - name: NEXT_PRIVATE_SMTP_HOST
              valueFrom:
                secretKeyRef:
                  name: sign-smtp
                  key: NEXT_PRIVATE_SMTP_HOST
                  optional: true
            - name: NEXT_PRIVATE_SMTP_PORT
              valueFrom:
                secretKeyRef:
                  name: sign-smtp
                  key: NEXT_PRIVATE_SMTP_PORT
                  optional: true
            - name: NEXT_PRIVATE_SMTP_USERNAME
              valueFrom:
                secretKeyRef:
                  name: sign-smtp
                  key: NEXT_PRIVATE_SMTP_USERNAME
                  optional: true
            - name: NEXT_PRIVATE_SMTP_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: sign-smtp
                  key: NEXT_PRIVATE_SMTP_PASSWORD
                  optional: true
            - name: NEXT_PRIVATE_SMTP_FROM_ADDRESS
              valueFrom:
                secretKeyRef:
                  name: sign-smtp
                  key: NEXT_PRIVATE_SMTP_FROM_ADDRESS
                  optional: true
            # sso:wire sets these two as plain literals too — same reasoning
            # as the SMTP pair above (see ClusterTool::SIGN's oidcEnv()).
            - name: NEXT_PUBLIC_DISABLE_OIDC_SIGNIN
              value: "false"
            - name: NEXT_PUBLIC_DISABLE_OIDC_SIGNUP
              value: "false"
            - name: NEXT_PRIVATE_OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: sign-oidc
                  key: NEXT_PRIVATE_OIDC_CLIENT_ID
                  optional: true
            - name: NEXT_PRIVATE_OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: sign-oidc
                  key: NEXT_PRIVATE_OIDC_CLIENT_SECRET
                  optional: true
            - name: NEXT_PRIVATE_OIDC_WELL_KNOWN
              valueFrom:
                secretKeyRef:
                  name: sign-oidc
                  key: NEXT_PRIVATE_OIDC_WELL_KNOWN
                  optional: true
          # Documenso verifies 163 migrations + runs service-account migrations +
          # a license check before it binds :3000 (~60-90s). A startupProbe holds
          # liveness/readiness off until it's actually up, so the slow boot can't
          # be SIGKILLed mid-flight (the CrashLoop cause). failureThreshold 30 x
          # 10s = up to 5 min of startup runway.
          startupProbe:
            httpGet:
              path: /api/health
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /api/health
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 3
          livenessProbe:
            httpGet:
              path: /api/health
              port: 3000
            periodSeconds: 15
            timeoutSeconds: 5
            failureThreshold: 3
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
  name: sign
  namespace: larakube-shared
spec:
  selector:
    app: sign-documenso
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.sign.ingress')
