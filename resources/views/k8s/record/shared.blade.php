apiVersion: apps/v1
kind: Deployment
metadata:
  name: record-sendrec
  namespace: larakube-shared
  labels:
    app: record-sendrec
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: record-sendrec
  template:
    metadata:
      labels:
        app: record-sendrec
    spec:
      containers:
        - name: sendrec
          image: ghcr.io/sendrec/sendrec:v1.89.1
          ports:
            - containerPort: 8080
              name: http
          env:
            - name: BASE_URL
              value: "https://{{ $host }}"
            - name: REGISTRATION_ENABLED
              value: "{{ ($allowRegistration ?? false) ? 'true' : 'false' }}"
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: record-sendrec-secrets
                  key: db-password
            - name: DATABASE_URL
              value: "postgres://record_sendrec:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/record_sendrec?sslmode=disable"
            - name: JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: record-sendrec-secrets
                  key: jwt-secret
            - name: S3_ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: S3_ACCESS_KEY
              value: "{{ $s3AccessKey }}"
            - name: S3_SECRET_KEY
              value: "{{ $s3SecretKey }}"
            - name: S3_BUCKET
              value: "{{ $s3Bucket }}"
            - name: S3_REGION
              value: "us-east-1"
            - name: S3_FORCE_PATH_STYLE
              value: "true"
            # 465 is implicit TLS; SendRec defaults to STARTTLS and would hang.
            - name: SMTP_TLS
              value: "implicit"
            - name: SMTP_HOST
              valueFrom:
                secretKeyRef:
                  name: record-sendrec-smtp
                  key: SMTP_HOST
                  optional: true
            - name: SMTP_PORT
              valueFrom:
                secretKeyRef:
                  name: record-sendrec-smtp
                  key: SMTP_PORT
                  optional: true
            - name: SMTP_USERNAME
              valueFrom:
                secretKeyRef:
                  name: record-sendrec-smtp
                  key: SMTP_USERNAME
                  optional: true
            - name: SMTP_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: record-sendrec-smtp
                  key: SMTP_PASSWORD
                  optional: true
            - name: EMAIL_FROM_ADDRESS
              valueFrom:
                secretKeyRef:
                  name: record-sendrec-smtp
                  key: EMAIL_FROM_ADDRESS
                  optional: true
          # NOTE: no OIDC_* env vars here on purpose. SendRec's SSO is
          # WORKSPACE-level and configured inside the app (its .env.example
          # declares no OIDC variables at all), so env-based wiring is inert.
          # A previous revision injected OIDC_ENABLED/CLIENT_ID/CLIENT_SECRET/
          # ISSUER from a `record-sendrec-oidc` Secret — those were invented and
          # did nothing. See plans/active/sendrec-native-sso.md.
---
apiVersion: v1
kind: Service
metadata:
  name: record
  namespace: larakube-shared
spec:
  selector:
    app: record-sendrec
  ports:
    - port: 80
      targetPort: 8080
      name: http
---
@include('k8s.record.ingress')
