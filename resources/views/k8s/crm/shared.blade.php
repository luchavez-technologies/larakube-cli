apiVersion: apps/v1
kind: Deployment
metadata:
  name: crm-twenty
  namespace: larakube-shared
  labels:
    app: crm-twenty
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: crm-twenty
  template:
    metadata:
      labels:
        app: crm-twenty
    spec:
      containers:
        - name: twenty
          image: twentycrm/twenty:latest
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: FRONT_BASE_URL
              value: "https://{{ $host }}"
            - name: PG_DATABASE_URL
              value: "postgres://crm_twenty:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/crm_twenty"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: crm-twenty-secrets
                  key: db-password
            - name: ACCESS_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: crm-twenty-secrets
                  key: access-token-secret
            - name: LOGIN_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: crm-twenty-secrets
                  key: login-token-secret
            - name: REFRESH_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: crm-twenty-secrets
                  key: refresh-token-secret
            - name: FILE_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: crm-twenty-secrets
                  key: file-token-secret
            # OIDC
            - name: SSO_OIDC_ISSUER
              valueFrom:
                secretKeyRef:
                  name: crm-twenty-oidc
                  key: SSO_OIDC_ISSUER
                  optional: true
            - name: SSO_OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: crm-twenty-oidc
                  key: SSO_OIDC_CLIENT_ID
                  optional: true
            - name: SSO_OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: crm-twenty-oidc
                  key: SSO_OIDC_CLIENT_SECRET
                  optional: true
          readinessProbe:
            httpGet:
              path: /health
              port: 3000
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /health
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
  name: crm
  namespace: larakube-shared
spec:
  selector:
    app: crm-twenty
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.crm.ingress')
