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
          image: documenso/documenso:latest
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
                  name: sign-documenso-secrets
                  key: nextauth-secret
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-secrets
                  key: db-password
            - name: DATABASE_URL
              value: "postgres://sign_documenso:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/sign_documenso"
            - name: NEXT_PRIVATE_SMTP_TRANSPORT
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-smtp
                  key: NEXT_PRIVATE_SMTP_TRANSPORT
                  optional: true
            - name: NEXT_PRIVATE_SMTP_SECURE
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-smtp
                  key: NEXT_PRIVATE_SMTP_SECURE
                  optional: true
            - name: NEXT_PRIVATE_SMTP_HOST
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-smtp
                  key: NEXT_PRIVATE_SMTP_HOST
                  optional: true
            - name: NEXT_PRIVATE_SMTP_PORT
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-smtp
                  key: NEXT_PRIVATE_SMTP_PORT
                  optional: true
            - name: NEXT_PRIVATE_SMTP_USERNAME
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-smtp
                  key: NEXT_PRIVATE_SMTP_USERNAME
                  optional: true
            - name: NEXT_PRIVATE_SMTP_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-smtp
                  key: NEXT_PRIVATE_SMTP_PASSWORD
                  optional: true
            - name: NEXT_PRIVATE_SMTP_FROM_ADDRESS
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-smtp
                  key: NEXT_PRIVATE_SMTP_FROM_ADDRESS
                  optional: true
            - name: NEXT_PUBLIC_DISABLE_OIDC_SIGNIN
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-oidc
                  key: NEXT_PUBLIC_DISABLE_OIDC_SIGNIN
                  optional: true
            - name: NEXT_PRIVATE_OIDC_ALLOW_SIGNUP
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-oidc
                  key: NEXT_PRIVATE_OIDC_ALLOW_SIGNUP
                  optional: true
            - name: NEXT_PRIVATE_OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-oidc
                  key: NEXT_PRIVATE_OIDC_CLIENT_ID
                  optional: true
            - name: NEXT_PRIVATE_OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-oidc
                  key: NEXT_PRIVATE_OIDC_CLIENT_SECRET
                  optional: true
            - name: NEXT_PRIVATE_OIDC_WELL_KNOWN
              valueFrom:
                secretKeyRef:
                  name: sign-documenso-oidc
                  key: NEXT_PRIVATE_OIDC_WELL_KNOWN
                  optional: true
          readinessProbe:
            httpGet:
              path: /
              port: 3000
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /
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
