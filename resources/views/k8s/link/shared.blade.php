apiVersion: apps/v1
kind: Deployment
metadata:
  name: link-kutt
  namespace: larakube-shared
  labels:
    app: link-kutt
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: link-kutt
  template:
    metadata:
      labels:
        app: link-kutt
    spec:
      containers:
        - name: kutt
          image: kutt/kutt:v3.2.6
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: DEFAULT_DOMAIN
              value: "{{ $host }}"
            - name: SITE_NAME
              value: "{{ $appName ?? 'LaraKube Kutt' }}"
            - name: JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: link-secrets
                  key: jwt-secret
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: link-secrets
                  key: db-password
            - name: DB_HOST
              value: "postgres.{{ $plexNamespace }}.svc.cluster.local"
            - name: DB_PORT
              value: "5432"
            - name: DB_NAME
              value: "link_kutt"
            - name: DB_USER
              value: "link_kutt"
            - name: DB_CLIENT
              value: "pg"
            - name: REDIS_ENABLED
              value: "true"
            - name: REDIS_HOST
              value: "redis.{{ $plexNamespace }}.svc.cluster.local"
            - name: REDIS_PORT
              value: "6379"
            - name: REDIS_DB
              value: "{{ $redisIndex }}"
            # SMTP Setup
            - name: MAIL_HOST
              valueFrom:
                secretKeyRef:
                  name: link-smtp
                  key: MAIL_HOST
                  optional: true
            - name: MAIL_PORT
              valueFrom:
                secretKeyRef:
                  name: link-smtp
                  key: MAIL_PORT
                  optional: true
            # mail:wire sets this as a plain literal (kubectl set env
            # NAME=value), never through the link-smtp Secret — must
            # stay a literal here too, or a future kubectl apply conflicts
            # with mail:wire's live value (see ClusterTool::LINK's smtpEnv()).
            - name: MAIL_SECURE
              value: "true"
            - name: MAIL_USER
              valueFrom:
                secretKeyRef:
                  name: link-smtp
                  key: MAIL_USER
                  optional: true
            - name: MAIL_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: link-smtp
                  key: MAIL_PASSWORD
                  optional: true
            - name: MAIL_FROM
              valueFrom:
                secretKeyRef:
                  name: link-smtp
                  key: MAIL_FROM
                  optional: true
            # OIDC
            - name: OIDC_ISSUER
              valueFrom:
                secretKeyRef:
                  name: link-oidc
                  key: OIDC_ISSUER
                  optional: true
            - name: OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: link-oidc
                  key: OIDC_CLIENT_ID
                  optional: true
            - name: OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: link-oidc
                  key: OIDC_CLIENT_SECRET
                  optional: true
          startupProbe:
            httpGet:
              path: /
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 30
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
  name: link
  namespace: larakube-shared
spec:
  selector:
    app: link-kutt
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.link.ingress')
