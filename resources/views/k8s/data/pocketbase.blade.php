apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $deployName }}-hooks
  namespace: {{ $namespace }}
data:
  onBootstrap.pb.js: |
    onBootstrap((e) => {
      e.next();
      const settings = $app.settings();

      if ($os.getenv("POCKETBASE_SMTP_ENABLED") === "true") {
        settings.smtp.enabled = true;
        settings.smtp.host = $os.getenv("POCKETBASE_SMTP_HOST");
        settings.smtp.port = parseInt($os.getenv("POCKETBASE_SMTP_PORT") || "587", 10);
        settings.smtp.username = $os.getenv("POCKETBASE_SMTP_USER");
        settings.smtp.password = $os.getenv("POCKETBASE_SMTP_PASS");
      }

      const smtpFrom = $os.getenv("POCKETBASE_SMTP_FROM");
      if (smtpFrom) {
        settings.meta.senderAddress = smtpFrom;
      }

      const oidcClientId = $os.getenv("POCKETBASE_OIDC_CLIENT_ID");
      if (oidcClientId) {
        settings.oauth2.enabled = true;
        const issuer = $os.getenv("POCKETBASE_OIDC_ISSUER");
        const providers = settings.oauth2.providers.filter((p) => p.name !== "oidc");
        providers.push({
          name: "oidc",
          clientId: oidcClientId,
          clientSecret: $os.getenv("POCKETBASE_OIDC_CLIENT_SECRET"),
          authURL: issuer + "/oauth/v2/authorize",
          tokenURL: issuer + "/oauth/v2/token",
          userInfoURL: issuer + "/oidc/v1/userinfo",
          displayName: "Zitadel",
        });
        settings.oauth2.providers = providers;
      }

      $app.save(settings);
    })
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deployName }}
  namespace: {{ $namespace }}
  labels:
    app.kubernetes.io/name: {{ $deployName }}
    app.kubernetes.io/instance: {{ $instance }}
    app.kubernetes.io/component: data
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app.kubernetes.io/name: {{ $deployName }}
  template:
    metadata:
      labels:
        app.kubernetes.io/name: {{ $deployName }}
        app.kubernetes.io/instance: {{ $instance }}
    spec:
      containers:
        - name: pocketbase
          # PocketBase has no official image — this is the community-maintained one.
          image: ghcr.io/muchobien/pocketbase:0.39.10
          imagePullPolicy: IfNotPresent
          ports:
            - containerPort: 8090
              name: http
          env:
            - name: PB_ADMIN_EMAIL
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: admin-email
            - name: PB_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: admin-password
            - name: AWS_ACCESS_KEY_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-key
            - name: AWS_SECRET_ACCESS_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-secret
          volumeMounts:
            - name: pb-data
              mountPath: /pb_data
            - name: pb-hooks
              mountPath: /pb_hooks
          resources:
            requests:
              cpu: 50m
              memory: 32Mi
            limits:
              cpu: 500m
              memory: 256Mi
          livenessProbe:
            httpGet:
              path: /api/health
              port: 8090
            initialDelaySeconds: 5
            periodSeconds: 10
          readinessProbe:
            httpGet:
              path: /api/health
              port: 8090
            initialDelaySeconds: 5
            periodSeconds: 10
      volumes:
        - name: pb-data
          persistentVolumeClaim:
            claimName: {{ $pvcName }}
        - name: pb-hooks
          configMap:
            name: {{ $deployName }}-hooks
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $deployName }}
  namespace: {{ $namespace }}
  labels:
    app.kubernetes.io/name: {{ $deployName }}
spec:
  type: ClusterIP
  ports:
    - port: 8090
      targetPort: 8090
      name: http
  selector:
    app.kubernetes.io/name: {{ $deployName }}
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $deployName }}-ingress
  namespace: {{ $namespace }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@if(!$isLocal)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@endif
@if($vpnOnly)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-vpn-only@kubernetescrd
@endif
spec:
  rules:
    - host: {{ $host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $deployName }}
                port:
                  number: 8090
@foreach($aliasHosts ?? [] as $aliasHost)
    - host: {{ $aliasHost }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $deployName }}
                port:
                  number: 8090
@endforeach
  tls:
    - hosts:
        - {{ $host }}
@foreach($aliasHosts ?? [] as $aliasHost)
        - {{ $aliasHost }}
@endforeach
