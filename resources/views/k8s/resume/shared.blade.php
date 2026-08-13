apiVersion: apps/v1
kind: Deployment
metadata:
  name: resume-reactive
  namespace: larakube-shared
  labels:
    app: resume-reactive
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: resume-reactive
  template:
    metadata:
      labels:
        app: resume-reactive
    spec:
      containers:
        - name: reactive-resume
          image: amruthpillai/reactive-resume:v5.2.5
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: PORT
              value: "3000"
            - name: PUBLIC_URL
              value: "https://{{ $host }}"
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: resume-reactive-secrets
                  key: db-password
            - name: DATABASE_URL
              value: "postgresql://reactiveresume:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/reactiveresume"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/0"
            - name: S3_ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: S3_BUCKET
              value: "{{ $s3Bucket }}"
            - name: S3_ACCESS_KEY
              value: "{{ $s3AccessKey }}"
            - name: S3_SECRET_KEY
              value: "{{ $s3SecretKey }}"
            - name: S3_REGION
              value: "us-east-1"
            - name: S3_FORCE_PATH_STYLE
              value: "true"
            - name: OAUTH_PROVIDER_NAME
              value: "Zitadel"
            - name: OAUTH_SCOPES
              value: "openid profile email"
            - name: OAUTH_ALLOW_SIGNUPS
              value: "true"
            - name: OAUTH_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: resume-reactive-oidc
                  key: OAUTH_CLIENT_ID
                  optional: true
            - name: OAUTH_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: resume-reactive-oidc
                  key: OAUTH_CLIENT_SECRET
                  optional: true
            - name: OAUTH_DISCOVERY_URL
              valueFrom:
                secretKeyRef:
                  name: resume-reactive-oidc
                  key: OAUTH_DISCOVERY_URL
                  optional: true
            - name: MAIL_SERVER
              valueFrom:
                secretKeyRef:
                  name: resume-reactive-smtp
                  key: MAIL_SERVER
                  optional: true
            - name: MAIL_PORT
              valueFrom:
                secretKeyRef:
                  name: resume-reactive-smtp
                  key: MAIL_PORT
                  optional: true
            - name: MAIL_USERNAME
              valueFrom:
                secretKeyRef:
                  name: resume-reactive-smtp
                  key: MAIL_USERNAME
                  optional: true
            - name: MAIL_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: resume-reactive-smtp
                  key: MAIL_PASSWORD
                  optional: true
            - name: MAIL_FROM
              valueFrom:
                secretKeyRef:
                  name: resume-reactive-smtp
                  key: MAIL_FROM
                  optional: true
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
  name: resume
  namespace: larakube-shared
spec:
  selector:
    app: resume-reactive
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.resume.ingress')
