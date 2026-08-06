{{-- Folded into every config-checksum annotation below so a template-text-only
     edit (no variable changed) still forces an already-running pod to restart —
     subPath-mounted Secrets/ConfigMaps don't hot-reload, and hashing only the
     input variables misses changes to literal strings in this file. --}}
@php($__tplHash = substr(hash_file('sha256', resource_path('views/k8s/chat/matrix.blade.php')), 0, 12))
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: chat-synapse-data
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 5Gi
@if($noPlex)
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: chat-synapse-db-storage
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 5Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: chat-synapse-db
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: chat-synapse-db
  template:
    metadata:
      labels:
        app: chat-synapse-db
    spec:
      containers:
        - name: postgres
          image: postgres:15-alpine
          env:
            - name: POSTGRES_USER
              value: chat_matrix
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: chat-secrets
                  key: db-password
            - name: POSTGRES_DB
              value: chat_matrix
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
          volumeMounts:
            - name: storage
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: chat-synapse-db-storage
---
apiVersion: v1
kind: Service
metadata:
  name: chat-synapse-db
  namespace: larakube-shared
spec:
  selector:
    app: chat-synapse-db
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
@endif
---
apiVersion: v1
kind: Secret
metadata:
  name: chat-synapse-config
  namespace: larakube-shared
type: Opaque
stringData:
  homeserver.yaml: |
    server_name: "{{ $host }}"
    pid_file: "/data/homeserver.pid"
    public_baseurl: "https://{{ $host }}/"
    serve_client_well_known: true
    # lk-jwt-service validates OpenID tokens via a real Matrix federation
    # call (GET /_matrix/federation/v1/openid/userinfo) which, absent this,
    # defaults to server_name:8448 — a port nothing here exposes. This makes
    # Synapse advertise itself on the same 443/Ingress path everything else
    # already uses, no separate federation port needed.
    serve_server_wellknown: true
    report_stats: false
    media_store_path: "/data/media_store"
    listeners:
      - port: 8008
        tls: false
        type: http
        x_forwarded: true
        resources:
          - names: [client, federation]
            compress: true
    database:
      name: psycopg2
      allow_unsafe_locale: true
      args:
        user: "{{ $dbUser ?? 'chat_matrix' }}"
        password: "{{ $dbPassword }}"
        database: "{{ $dbName ?? 'chat_matrix' }}"
        host: "{{ $dbHost ?? ($noPlex ? 'chat-synapse-db' : 'postgres.'.$plexNamespace.'.svc.cluster.local') }}"
        port: 5432
        cp_min: 5
        cp_max: 10
    enable_registration: false
    registration_shared_secret: "{{ $registrationSecret }}"
@if($turnSecret ?? null)
    turn_shared_secret: "{{ $turnSecret }}"
    turn_uris:
      - "turn:{{ $host }}:3478?transport=udp"
      - "turn:{{ $host }}:3478?transport=tcp"
      - "stun:{{ $host }}:3478"
    turn_user_lifetime: 86400000
    turn_allow_guests: false
@endif
@if($livekitApiKey ?? null)
    experimental_features:
      msc3401_enabled: true
      msc3266_enabled: true
    well_known:
      client:
        "org.matrix.msc4143.rtc_foci":
          - type: livekit
            livekit_service_url: "https://{{ $host }}/livekit/jwt"
@endif
@if($s3Bucket ?? null)
    media_storage_providers:
      - module: s3_storage_provider.S3StorageProviderBackend
        store_local: true
        store_remote: true
        store_synchronous: false
        config:
          bucket: "{{ $s3Bucket }}"
          prefix: "media"
          endpoint_url: "{{ $s3Endpoint }}"
          access_key_id: "{{ $s3AccessKey }}"
          secret_access_key: "{{ $s3SecretKey }}"
@endif
@if($smtp ?? null)
    email:
      enable_notifs: true
      notif_from: "{{ $smtp['from'] }}"
      smtp_host: "{{ $smtp['host'] }}"
      smtp_port: {{ (int) $smtp['port'] }}
      smtp_user: "{{ $smtp['user'] }}"
      smtp_pass: "{{ $smtp['password'] }}"
@endif
@if($oidc ?? null)
    oidc_providers:
      - idp_id: zitadel
        idp_name: "{{ $oidc['name'] ?? 'Zitadel' }}"
        discover: true
        issuer: "{{ $oidc['issuer'] }}"
        client_id: "{{ $oidc['client_id'] }}"
        client_secret: "{{ $oidc['client_secret'] }}"
        scopes: ["openid", "profile", "email"]
        allow_existing_users: true
        user_mapping_provider:
          config:
            localpart_template: "@{{ user.preferred_username }}"
            display_name_template: "@{{ user.name }}"
            email_template: "@{{ user.email }}"
@endif
@if($turnSecret ?? null)
---
apiVersion: v1
kind: Secret
metadata:
  name: chat-coturn-config
  namespace: larakube-shared
type: Opaque
stringData:
  turnserver.conf: |
    listening-port=3478
    tls-listening-port=5349
    realm={{ $host }}
    use-auth-secret
    static-auth-secret={{ $turnSecret }}
    user-quota=12
    total-quota=1200
    min-port=49160
    max-port=49179
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: chat-coturn
  namespace: larakube-shared
  labels:
    app: chat-coturn
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: chat-coturn
  template:
    metadata:
      labels:
        app: chat-coturn
      annotations:
        larakube.io/config-checksum: "{{ substr(hash('sha256', $turnSecret.$host.$__tplHash), 0, 16) }}"
    spec:
      containers:
        - name: coturn
          image: coturn/coturn:4.6.3-alpine
          ports:
            - { containerPort: 3478, protocol: UDP, @if($hostPort ?? true)hostPort: 3478, @endif name: turn-udp }
            - { containerPort: 3478, protocol: TCP, @if($hostPort ?? true)hostPort: 3478, @endif name: turn-tcp }
@for ($p = 49160; $p <= 49179; $p++)
            - { containerPort: {{ $p }}, protocol: UDP, @if($hostPort ?? true)hostPort: {{ $p }}, @endif name: "relay-{{ $p }}" }
@endfor
          volumeMounts:
            - name: config
              mountPath: /etc/coturn/turnserver.conf
              subPath: turnserver.conf
      volumes:
        - name: config
          secret:
            secretName: chat-coturn-config
---
apiVersion: v1
kind: Service
metadata:
  name: chat-coturn
  namespace: larakube-shared
spec:
  selector:
    app: chat-coturn
  ports:
    - name: turn-udp
      protocol: UDP
      port: 3478
      targetPort: 3478
    - name: turn-tcp
      protocol: TCP
      port: 3478
      targetPort: 3478
@for ($p = 49160; $p <= 49179; $p++)
    - name: "relay-{{ $p }}"
      protocol: UDP
      port: {{ $p }}
      targetPort: {{ $p }}
@endfor
  type: {{ ($hostPort ?? true) ? 'ClusterIP' : 'LoadBalancer' }}
@endif
@if($livekitApiKey ?? null)
---
apiVersion: v1
kind: Secret
metadata:
  name: chat-livekit-config
  namespace: larakube-shared
type: Opaque
stringData:
  livekit.yaml: |
    port: 7880
    rtc:
      tcp_port: 7881
      udp_port: 7882
      use_external_ip: true
@if($turnSecret ?? null)
    turn:
      enabled: true
      domain: "{{ $host }}"
      tls_port: 3478
      udp_port: 3478
      secret: "{{ $turnSecret }}"
@endif
    keys:
      "{{ $livekitApiKey }}": "{{ $livekitApiSecret }}"
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: chat-livekit
  namespace: larakube-shared
  labels:
    app: chat-livekit
    app.kubernetes.io/part-of: chat
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: chat-livekit
  template:
    metadata:
      labels:
        app: chat-livekit
      annotations:
        larakube.io/config-checksum: "{{ substr(hash('sha256', $livekitApiKey.$livekitApiSecret.($turnSecret ?? '').$host.$__tplHash), 0, 16) }}"
    spec:
      containers:
        - name: livekit
          image: livekit/livekit-server:v1.8.0
          args: ["--config", "/etc/livekit.yaml"]
          ports:
            - containerPort: 7880
            - { containerPort: 7881, protocol: TCP, @if($hostPort ?? true)hostPort: 7881, @endif name: rtc-tcp }
            - { containerPort: 7882, protocol: UDP, @if($hostPort ?? true)hostPort: 7882, @endif name: rtc-udp }
          volumeMounts:
            - name: config
              mountPath: /etc/livekit.yaml
              subPath: livekit.yaml
      volumes:
        - name: config
          secret:
            secretName: chat-livekit-config
---
# Signaling only (WS/HTTP) — always ClusterIP, fronted by Traefik Ingress.
apiVersion: v1
kind: Service
metadata:
  name: chat-livekit
  namespace: larakube-shared
spec:
  selector:
    app: chat-livekit
  ports:
    - name: http
      port: 7880
      targetPort: 7880
    - name: rtc-tcp
      port: 7881
      targetPort: 7881
---
# RTC media (single-port UDP mux) — same hostPort/LoadBalancer toggle as Coturn.
apiVersion: v1
kind: Service
metadata:
  name: chat-livekit-rtc
  namespace: larakube-shared
spec:
  selector:
    app: chat-livekit
  ports:
    - name: rtc-udp
      protocol: UDP
      port: 7882
      targetPort: 7882
  type: {{ ($hostPort ?? true) ? 'ClusterIP' : 'LoadBalancer' }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: chat-lk-jwt
  namespace: larakube-shared
  labels:
    app: chat-lk-jwt
    app.kubernetes.io/part-of: chat
spec:
  replicas: 1
  selector:
    matchLabels:
      app: chat-lk-jwt
  template:
    metadata:
      labels:
        app: chat-lk-jwt
    spec:
      containers:
        - name: lk-jwt
          image: ghcr.io/element-hq/lk-jwt-service:latest
          env:
            - name: LIVEKIT_URL
              # lk-jwt-service hands this exact value back to the browser as
              # the room connection URL (not just using it for its own
              # server-side room-management calls) — has to be the public,
              # externally-reachable address, not cluster-internal DNS.
              value: "wss://{{ $host }}/livekit"
            - name: LIVEKIT_KEY
              value: "{{ $livekitApiKey }}"
            - name: LIVEKIT_SECRET
              value: "{{ $livekitApiSecret }}"
            - name: LIVEKIT_FULL_ACCESS_HOMESERVERS
              value: "{{ $host }}"
          ports:
            - containerPort: 8080
---
apiVersion: v1
kind: Service
metadata:
  name: chat-lk-jwt
  namespace: larakube-shared
spec:
  selector:
    app: chat-lk-jwt
  ports:
    - name: http
      port: 8080
      targetPort: 8080
@endif
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: chat-synapse
  namespace: larakube-shared
  labels:
    app: chat-synapse
    app.kubernetes.io/part-of: chat
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: chat-synapse
  template:
    metadata:
      labels:
        app: chat-synapse
      annotations:
        larakube.io/config-checksum: "{{ substr(hash('sha256', $dbPassword.$registrationSecret.($smtp['password'] ?? '').($oidc['client_secret'] ?? '').$__tplHash), 0, 16) }}"
    spec:
@if($s3Bucket ?? null)
      initContainers:
        - name: install-s3-provider
          image: matrixdotorg/synapse:v1.120.0
          command: ["sh", "-c", "mkdir -p /data/site-packages && pip install --no-deps --no-cache-dir --target=/data/site-packages synapse-s3-storage-provider boto3 botocore humanize tqdm s3transfer jmespath"]
          volumeMounts:
            - name: data
              mountPath: /data
@endif
      containers:
        - name: synapse
          image: matrixdotorg/synapse:v1.120.0
@if($s3Bucket ?? null)
          env:
            - name: PYTHONPATH
              value: "/data/site-packages"
@endif
          ports:
            - containerPort: 8008
          volumeMounts:
            - name: data
              mountPath: /data
            - name: config
              mountPath: /data/homeserver.yaml
              subPath: homeserver.yaml
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: chat-synapse-data
        - name: config
          secret:
            secretName: chat-synapse-config
---
apiVersion: v1
kind: Service
metadata:
  name: chat-synapse
  namespace: larakube-shared
spec:
  selector:
    app: chat-synapse
  ports:
    - protocol: TCP
      port: 8008
      targetPort: 8008
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: chat-cinny-config
  namespace: larakube-shared
data:
  default.conf: |
    server {
      listen 80;
      listen [::]:80;

      location /.well-known/matrix/client {
        root /usr/share/nginx/html;
        default_type application/json;
        add_header Access-Control-Allow-Origin *;
      }

      location / {
        root /usr/share/nginx/html;
@if(($appName ?? null) && $appName !== 'Chat')
        sub_filter '<title>Cinny</title>' '<title>{{ $appName }}</title>';
        sub_filter_once on;
        sub_filter_types text/html;
@endif

        rewrite ^/\.well-known/matrix/client$ /.well-known/matrix/client break;
        rewrite ^/config.json$ /config.json break;
        rewrite ^/manifest.json$ /manifest.json break;

        rewrite ^/sw.js$ /sw.js break;
        rewrite ^/pdf.worker.min.js$ /pdf.worker.min.js break;

        rewrite ^/public/(.*)$ /public/$1 break;
        rewrite ^/assets/(.*)$ /assets/$1 break;

        rewrite ^(.+)$ /index.html break;
      }
    }
  config.json: |
    {
      "defaultHomeserver": 0,
      "homeserverList": [
        "{{ $host }}"
      ]
    }
@if($livekitApiKey ?? null)
  client: |
    {
      "m.homeserver": {
        "base_url": "https://{{ $host }}/"
      },
      "org.matrix.msc4143.rtc_foci": [
        {
          "type": "livekit",
          "livekit_service_url": "https://{{ $host }}/livekit/jwt"
        }
      ]
    }
@endif
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: chat-cinny
  namespace: larakube-shared
  labels:
    app: chat-cinny
    app.kubernetes.io/part-of: chat
spec:
  replicas: 1
  selector:
    matchLabels:
      app: chat-cinny
  template:
    metadata:
      labels:
        app: chat-cinny
      annotations:
        larakube.io/config-checksum: "{{ substr(hash('sha256', $host.($livekitApiKey ?? '').$__tplHash), 0, 16) }}"
    spec:
      containers:
        - name: cinny
          image: ghcr.io/cinnyapp/cinny:v4.12.3
          ports:
            - containerPort: 80
          volumeMounts:
            - name: cinny-config
              mountPath: /etc/nginx/conf.d/default.conf
              subPath: default.conf
            - name: cinny-config
              mountPath: /app/config.json
              subPath: config.json
@if($livekitApiKey ?? null)
            - name: cinny-config
              mountPath: /app/.well-known/matrix/client
              subPath: client
@endif
      volumes:
        - name: cinny-config
          configMap:
            name: chat-cinny-config
---
apiVersion: v1
kind: Service
metadata:
  name: chat-cinny
  namespace: larakube-shared
spec:
  selector:
    app: chat-cinny
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
@if($livekitApiKey ?? null)
---
# lk-jwt-service only implements POST /sfu/get at its own root — Element
# Call always calls {livekit_service_url}/sfu/get, so whatever path prefix
# routes to chat-lk-jwt has to be stripped before it reaches the pod.
apiVersion: traefik.io/v1alpha1
kind: Middleware
metadata:
  name: chat-lk-jwt-stripprefix
  namespace: larakube-shared
spec:
  stripPrefix:
    prefixes:
      - /livekit/jwt
---
# LiveKit itself expects to be mounted at root (/twirp/... for the REST admin
# API lk-jwt-service uses internally, /rtc for browser WS signaling) — same
# problem as chat-lk-jwt above, just one level up. Chained after the /jwt
# strip so /livekit/jwt/* is untouched here (already stripped, no longer
# matches this prefix) and everything else under /livekit/* gets stripped.
apiVersion: traefik.io/v1alpha1
kind: Middleware
metadata:
  name: chat-livekit-stripprefix
  namespace: larakube-shared
spec:
  stripPrefix:
    prefixes:
      - /livekit
@endif
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: chat-ingress
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
@if(($vpnOnly ?? false) || ($livekitApiKey ?? null))
    traefik.ingress.kubernetes.io/router.middlewares: "{{ collect([
        ($vpnOnly ?? false) ? 'larakube-shared-chat-vpn-only@kubernetescrd' : null,
        ($livekitApiKey ?? null) ? 'larakube-shared-chat-lk-jwt-stripprefix@kubernetescrd' : null,
        ($livekitApiKey ?? null) ? 'larakube-shared-chat-livekit-stripprefix@kubernetescrd' : null,
    ])->filter()->implode(',') }}"
@endif
spec:
  rules:
    - host: {{ $host }}
      http:
        paths:
          - path: /_matrix
            pathType: Prefix
            backend:
              service:
                name: chat-synapse
                port:
                  number: 8008
          - path: /_synapse
            pathType: Prefix
            backend:
              service:
                name: chat-synapse
                port:
                  number: 8008
@if($livekitApiKey ?? null)
          - path: /.well-known/matrix/client
            pathType: Exact
            backend:
              service:
                name: chat-cinny
                port:
                  number: 80
          - path: /livekit/jwt
            pathType: Prefix
            backend:
              service:
                name: chat-lk-jwt
                port:
                  number: 8080
          - path: /livekit
            pathType: Prefix
            backend:
              service:
                name: chat-livekit
                port:
                  number: 7880
@endif
          - path: /.well-known/matrix
            pathType: Prefix
            backend:
              service:
                name: chat-synapse
                port:
                  number: 8008
          - path: /
            pathType: Prefix
            backend:
              service:
                name: chat-cinny
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
