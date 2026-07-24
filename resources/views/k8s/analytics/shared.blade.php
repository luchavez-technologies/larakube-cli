apiVersion: apps/v1
kind: Deployment
metadata:
  name: analytics-umami
  namespace: larakube-shared
  labels:
    app: analytics-umami
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: analytics-umami
  template:
    metadata:
      labels:
        app: analytics-umami
    spec:
      containers:
        - name: umami
          image: ghcr.io/umami-software/umami:postgresql-latest
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: APP_SECRET
              valueFrom:
                secretKeyRef:
                  name: analytics-secrets
                  key: app-secret
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: analytics-secrets
                  key: db-password
            - name: DATABASE_URL
              value: "postgres://umami:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/umami"
          readinessProbe:
            httpGet:
              path: /api/heartbeat
              port: 3000
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /api/heartbeat
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 15
            timeoutSeconds: 5
          resources:
            requests:
              memory: 128Mi
              cpu: 50m
            limits:
              memory: 256Mi
              cpu: 100m
---
apiVersion: v1
kind: Service
metadata:
  name: analytics
  namespace: larakube-shared
spec:
  selector:
    app: analytics-umami
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.analytics.ingress')
