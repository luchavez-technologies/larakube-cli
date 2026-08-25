{{--
    Matrix Authentication Service (MAS) — Element X requires MSC3861/MAS-
    native OIDC login; it does not speak Synapse's classic oidc_providers:
    flow (matrix.blade.php's own $oidc block, which Cinny-era/legacy SSO
    clients used). Rendered and applied by ChatInitCommand::deployMas(), a
    dedicated step within chat:init itself (not chat:init's own main
    matrix.blade.php render) — it's deployed unconditionally once Zitadel
    is available, same tier as Coturn/the web client, but keeps its own
    Postgres/Zitadel/config-generation logic separate since chat:init's
    main render has no business generating MAS's DB password/encryption
    keys.

    Stateless: state lives entirely in MAS's own Postgres tenant, so no PVC
    here. chat-mas-config's content (config.yaml, including real encryption/
    signing keys from `mas-cli config generate`) is generated and written by
    deployMas() directly via kubectl, same posture as chat-synapse-config —
    this file only declares the Deployment/Service that mount it by name.
--}}
@php($__tplHash = substr(hash_file('sha256', resource_path('views/k8s/chat/mas.blade.php')), 0, 12))
{{-- Brand new component, no existing live data — fully instance-suffixed
     from birth, unlike chat-synapse/chat-synapse-db (see matrix.blade.php's
     own comment on why those stay unsuffixed). --}}
@php($__instanceSuffix = ($instance ?? null) ? "-{$instance}" : '')
@php($masName = 'chat-mas'.$__instanceSuffix)
@php($masDbDeploymentName = 'chat-mas-db'.$__instanceSuffix)
@php($masDbStorageName = 'chat-mas-db-storage'.$__instanceSuffix)
@php($masSecretsName = 'chat-mas-secrets'.$__instanceSuffix)
@php($masConfigSecretName = 'chat-mas-config'.$__instanceSuffix)
@php($masIngressName = 'chat-mas-ingress'.$__instanceSuffix)
@if($noPlex ?? false)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $masDbStorageName }}
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 2Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $masDbDeploymentName }}
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $masDbDeploymentName }}
  template:
    metadata:
      labels:
        app: {{ $masDbDeploymentName }}
    spec:
      containers:
        - name: postgres
          image: postgres:15-alpine
          env:
            - name: POSTGRES_USER
              value: chat_mas
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $masSecretsName }}
                  key: db-password
            - name: POSTGRES_DB
              value: chat_mas
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
          volumeMounts:
            - name: storage
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $masDbStorageName }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $masDbDeploymentName }}
  namespace: larakube-shared
spec:
  selector:
    app: {{ $masDbDeploymentName }}
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
---
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $masName }}
  namespace: larakube-shared
  labels:
    app: {{ $masName }}
    app.kubernetes.io/part-of: chat
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $masName }}
  template:
    metadata:
      labels:
        app: {{ $masName }}
      annotations:
        {{-- Only the checksum of the config itself matters here — the
             content already folds in every credential (DB password,
             Zitadel client secret, Synapse trust secret); a config-only
             checksum still forces a restart on rotation without needing
             chat-mas's own Deployment to know those values individually. --}}
        larakube.io/config-checksum: "{{ substr(hash('sha256', $masConfigHash.$__tplHash), 0, 16) }}"
    spec:
      containers:
        - name: mas
          image: {{ $masImage }}
          {{-- `mas-cli server` is the documented subcommand family alongside
               `mas-cli config generate`/`mas-cli syn2mas` — verify this
               exact invocation against the pinned image's --help before
               relying on it; explicit here rather than trusting an
               undocumented container default CMD. --}}
          command: ["mas-cli", "server", "--config", "/config/config.yaml"]
          resources:
            requests:
              memory: 64Mi
              cpu: 50m
            limits:
              memory: 256Mi
              cpu: 500m
          ports:
            - containerPort: 8080
          volumeMounts:
            - name: config
              mountPath: /config/config.yaml
              subPath: config.yaml
      volumes:
        - name: config
          secret:
            secretName: {{ $masConfigSecretName }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $masName }}
  namespace: larakube-shared
spec:
  selector:
    app: {{ $masName }}
  ports:
    - protocol: TCP
      port: 8080
      targetPort: 8080
---
{{--  MAS's own full site (OAuth authorize/token, discovery, account-
      management UI, GraphQL, assets) lives on its own subdomain rather than
      fighting Cinny for "/" on the main chat host — MAS's docs document
      this "basic configuration" (MAS owns the domain root) as the
      alternative to the compat-endpoint carve-out matrix.blade.php's
      chat-ingress adds once cutover is active. --}}
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $masIngressName }}
  namespace: larakube-shared
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@unless($isLocal ?? false)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@if($proxied ?? false)
    external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"
@endif
@endunless
spec:
  rules:
    - host: {{ $masHost }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $masName }}
                port:
                  number: 8080
  tls:
    - hosts:
        - {{ $masHost }}
