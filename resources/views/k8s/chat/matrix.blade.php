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
  selector:
    matchLabels:
      app: chat-coturn
  template:
    metadata:
      labels:
        app: chat-coturn
    spec:
      containers:
        - name: coturn
          image: coturn/coturn:4.6.3-alpine
          ports:
            - containerPort: 3478
              protocol: UDP
              hostPort: 3478
            - containerPort: 3478
              protocol: TCP
              hostPort: 3478
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
        larakube.io/config-checksum: "{{ substr(hash('sha256', $dbPassword.$registrationSecret.($smtp['password'] ?? '').($oidc['client_secret'] ?? '')), 0, 16) }}"
    spec:
      containers:
        - name: synapse
          image: matrixdotorg/synapse:v1.120.0
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
  config.json: |
    {
      "defaultHomeserver": 0,
      "homeserverList": [
        "{{ $host }}"
      ]
    }
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
    spec:
      containers:
        - name: cinny
          image: ghcr.io/cinnyapp/cinny:v4.12.3
          ports:
            - containerPort: 80
          volumeMounts:
            - name: cinny-config
              mountPath: /app/config.json
              subPath: config.json
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
@if($vpnOnly ?? false)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-chat-vpn-only@kubernetescrd
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
