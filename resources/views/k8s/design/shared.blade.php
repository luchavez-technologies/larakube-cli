apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $backendName }}
  namespace: larakube-shared
  labels:
    app: {{ $backendName }}
    instance: {{ $instance }}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $backendName }}
  template:
    metadata:
      labels:
        app: {{ $backendName }}
        instance: {{ $instance }}
    spec:
      containers:
        - name: backend
          image: penpotapp/backend:2.17
          ports:
            - containerPort: 6060
              name: http
          env:
            - name: PENPOT_PUBLIC_URI
              value: "https://{{ $host }}"
            - name: PENPOT_DATABASE_USERNAME
              value: "{{ $dbUser }}"
            - name: PENPOT_DATABASE_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $dbSecretName }}
                  key: password
            - name: PENPOT_SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $dbSecretName }}
                  key: secret-key
            - name: PENPOT_DATABASE_URI
              value: "postgresql://postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName }}"
            - name: PENPOT_REDIS_URI
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/0"
            - name: PENPOT_ASSETS_STORAGE_BACKEND
              value: "assets-s3"
            - name: PENPOT_OBJECTS_STORAGE_BACKEND
              value: "s3"
            - name: PENPOT_STORAGE_ASSETS_S3_REGION
              value: "us-east-1"
            - name: PENPOT_STORAGE_ASSETS_S3_BUCKET
              value: "{{ $s3Bucket }}"
            - name: PENPOT_STORAGE_ASSETS_S3_ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: PENPOT_STORAGE_ASSETS_S3_ACCESS_KEY
              value: "{{ $s3AccessKey }}"
            - name: PENPOT_STORAGE_ASSETS_S3_SECRET_KEY
              value: "{{ $s3SecretKey }}"
            - name: PENPOT_STORAGE_ASSETS_S3_USE_PATH_STYLE
              value: "true"
            - name: AWS_ACCESS_KEY_ID
              value: "{{ $s3AccessKey }}"
            - name: AWS_SECRET_ACCESS_KEY
              value: "{{ $s3SecretKey }}"
            - name: AWS_REGION
              value: "us-east-1"
@if($withExporter)
            - name: PENPOT_EXPORTER_URI
              value: "http://{{ $exporterServiceName }}:6061"
@endif
            # SMTP env optional refs
            - name: PENPOT_SMTP_HOST
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: PENPOT_SMTP_HOST
                  optional: true
            - name: PENPOT_SMTP_PORT
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: PENPOT_SMTP_PORT
                  optional: true
            - name: PENPOT_SMTP_DEFAULT_FROM
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: PENPOT_SMTP_DEFAULT_FROM
                  optional: true
            - name: PENPOT_SMTP_USERNAME
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: PENPOT_SMTP_USERNAME
                  optional: true
            - name: PENPOT_SMTP_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: PENPOT_SMTP_PASSWORD
                  optional: true
            - name: PENPOT_SMTP_SSL
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: PENPOT_SMTP_SSL
                  optional: true
            - name: PENPOT_SMTP_TLS
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecretName }}
                  key: PENPOT_SMTP_TLS
                  optional: true
            # OIDC env optional refs
            - name: PENPOT_FLAGS
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: PENPOT_FLAGS
                  optional: true
            - name: PENPOT_OIDC_NAME
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: PENPOT_OIDC_NAME
                  optional: true
            - name: PENPOT_OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: PENPOT_OIDC_CLIENT_ID
                  optional: true
            - name: PENPOT_OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: PENPOT_OIDC_CLIENT_SECRET
                  optional: true
            - name: PENPOT_OIDC_AUTH_URI
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: PENPOT_OIDC_AUTH_URI
                  optional: true
            - name: PENPOT_OIDC_TOKEN_URI
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: PENPOT_OIDC_TOKEN_URI
                  optional: true
            - name: PENPOT_OIDC_USERINFO_URI
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: PENPOT_OIDC_USERINFO_URI
                  optional: true
            - name: PENPOT_OIDC_BASE_URI
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: PENPOT_OIDC_BASE_URI
                  optional: true
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $backendServiceName }}
  namespace: larakube-shared
  labels:
    app: {{ $backendName }}
spec:
  ports:
    - port: 6060
      targetPort: 6060
      name: http
  selector:
    app: {{ $backendName }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $frontendName }}
  namespace: larakube-shared
  labels:
    app: {{ $frontendName }}
    instance: {{ $instance }}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $frontendName }}
  template:
    metadata:
      labels:
        app: {{ $frontendName }}
        instance: {{ $instance }}
    spec:
      containers:
        - name: frontend
          image: penpotapp/frontend:2.17
          ports:
            - containerPort: 8080
              name: http
          env:
            - name: PENPOT_PUBLIC_URI
              value: "https://{{ $host }}"
            - name: PENPOT_BACKEND_URI
              value: "http://{{ $backendServiceName }}:6060"
            - name: PENPOT_EXPORTER_URI
              value: "{{ $withExporter ? 'http://' . $exporterServiceName . ':6061' : 'http://127.0.0.1:6061' }}"
            - name: PENPOT_FLAGS
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: PENPOT_FLAGS
                  optional: true
            - name: PENPOT_OIDC_NAME
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: PENPOT_OIDC_NAME
                  optional: true
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $serviceName }}
  namespace: larakube-shared
  labels:
    app: {{ $frontendName }}
spec:
  ports:
    - port: 80
      targetPort: 8080
      name: http
  selector:
    app: {{ $frontendName }}
@if($withExporter)
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $exporterName }}
  namespace: larakube-shared
  labels:
    app: {{ $exporterName }}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $exporterName }}
  template:
    metadata:
      labels:
        app: {{ $exporterName }}
    spec:
      containers:
        - name: exporter
          image: penpotapp/exporter:2.17
          ports:
            - containerPort: 6061
              name: http
          env:
            - name: PENPOT_PUBLIC_URI
              value: "https://{{ $host }}"
            - name: PENPOT_REDIS_URI
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/0"
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $exporterServiceName }}
  namespace: larakube-shared
  labels:
    app: {{ $exporterName }}
spec:
  ports:
    - port: 6061
      targetPort: 6061
      name: http
  selector:
    app: {{ $exporterName }}
@endif
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $ingressName }}
  namespace: larakube-shared
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@unless($isLocal)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@if($proxied)
    external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"
@endif
@endunless
@if($vpnOnly)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-design-vpn-only@kubernetescrd
@endif
spec:
  rules:
    - host: "{{ $host }}"
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $serviceName }}
                port:
                  number: 80
  tls:
    - hosts:
        - "{{ $host }}"
