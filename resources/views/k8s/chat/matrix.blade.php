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
@if($meetJwtUrl ?? null)
    experimental_features:
      msc3401_enabled: true
      msc3266_enabled: true
      # Element Call registers a delayed "leave" event on join and keeps
      # restarting it; without MSC4140 its membership manager cannot hold
      # m.call.member alive and every client re-joins on a ~15s loop.
      msc4140_enabled: true
    # Required alongside msc4140_enabled — Synapse rejects every delayed event
    # unless a max delay is configured, which reads to the client as "delayed
    # events unsupported".
    max_event_delay_duration: 24h
    # A call generates far more state/delayed-event traffic than a chat message;
    # at Synapse's defaults the membership refreshes get rate-limited and the
    # call drops. Sized per Element Call's self-hosting guidance.
    rc_message:
      per_second: 0.5
      burst_count: 30
    rc_delayed_event_mgmt:
      per_second: 1
      burst_count: 20
    # Points at the shared Meet tool's Matrix bridge, not at anything chat owns.
    # Written by `meet:wire --tool=chat` and read back on re-run from the
    # chat-meet Secret, same discipline as the SMTP/OIDC wiring below.
    #
    # The key is `extra_well_known_client_content` — NOT a `well_known: client:`
    # block. Synapse silently ignores unknown top-level keys, so the wrong
    # spelling serves a well-known with no focus and Element Call reports
    # "Your homeserver does not support calling" with nothing in any log.
    extra_well_known_client_content:
      "org.matrix.msc4143.rtc_foci":
        - type: livekit
          livekit_service_url: "{{ $meetJwtUrl }}"
@endif
@if($s3Bucket ?? null)
    media_storage_providers:
      - module: s3_storage_provider.S3StorageProviderBackend
        store_local: true
        store_remote: true
        store_synchronous: false
        config:
          bucket: "{{ $s3Bucket }}"
          {{-- Trailing slash is load-bearing: s3_storage_provider composes keys
               as `prefix + path` with no separator, so "media" yields
               medialocal_content/… rather than a media/ folder. Changing this
               orphans every existing object — it needs a re-upload under the
               new prefix and a delete of the old keys, never an edit alone. --}}
          prefix: "media/"
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
@if($externalIp ?? null)
    external-ip={{ $externalIp }}
    relay-ip={{ $externalIp }}
@endif
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
          {{-- No CPU limit: throttling a media relay shows up as call stutter.
               Request reserves scheduling weight; memory stays bounded. --}}
          resources:
            requests:
              memory: 32Mi
              cpu: 50m
            limits:
              memory: 128Mi
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
        {{-- $s3SecretKey is in the hash because homeserver.yaml bakes the Commons
             S3 credentials in: without it a `plex:rotate` would write a new Secret
             that this pod never picks up, and media offload would fail silently. --}}
        larakube.io/config-checksum: "{{ substr(hash('sha256', $dbPassword.$registrationSecret.($smtp['password'] ?? '').($oidc['client_secret'] ?? '').($s3SecretKey ?? '').$__tplHash), 0, 16) }}"
    spec:
@if($s3Bucket ?? null)
      initContainers:
        - name: install-s3-provider
          image: matrixdotorg/synapse:v1.158.0
          command: ["sh", "-c", "mkdir -p /data/site-packages && pip install --no-deps --no-cache-dir --target=/data/site-packages synapse-s3-storage-provider boto3 botocore humanize tqdm s3transfer jmespath"]
          volumeMounts:
            - name: data
              mountPath: /data
@endif
      containers:
        - name: synapse
          image: matrixdotorg/synapse:v1.158.0
@if($s3Bucket ?? null)
          env:
            - name: PYTHONPATH
              value: "/data/site-packages"
@endif
          resources:
            requests:
              memory: 256Mi
              cpu: 100m
            limits:
              memory: 768Mi
              cpu: 1000m
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
        larakube.io/config-checksum: "{{ substr(hash('sha256', $host.$__tplHash), 0, 16) }}"
    spec:
      containers:
        - name: cinny
          image: ghcr.io/cinnyapp/cinny:v4.12.3
          resources:
            requests:
              memory: 32Mi
              cpu: 10m
            limits:
              memory: 128Mi
              cpu: 200m
          ports:
            - containerPort: 80
          volumeMounts:
            - name: cinny-config
              mountPath: /etc/nginx/conf.d/default.conf
              subPath: default.conf
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
    traefik.ingress.kubernetes.io/router.middlewares: "larakube-shared-chat-vpn-only@kubernetescrd"
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
@if($s3Bucket ?? null)
---
# Media pruning. Without this the S3 offload COSTS storage instead of saving it:
# Synapse always writes media to its own PVC first, and the storage provider
# adds a second copy in SeaweedFS. Both PVCs are local-path directories on the
# same block device, so every file is stored twice on one disk with no
# durability gained. This job deletes the local copy once the file is safely in
# S3 and has not been accessed for {{ $mediaRetention ?? '30d' }} — recent media
# stays local and fast, everything colder lives once, in SeaweedFS.
#
# That is also what keeps chat-synapse-data (5Gi) from filling: total media can
# exceed the PVC because only the working set is held locally.
apiVersion: batch/v1
kind: CronJob
metadata:
  name: chat-media-prune
  namespace: larakube-shared
  labels:
    app: chat-media-prune
    app.kubernetes.io/part-of: chat
spec:
  schedule: "{{ $mediaPruneSchedule ?? '17 3 * * *' }}"
  # Never overlap: two prunes sharing one sqlite cache corrupt each other's view
  # of what has been uploaded.
  concurrencyPolicy: Forbid
  successfulJobsHistoryLimit: 1
  failedJobsHistoryLimit: 3
  jobTemplate:
    spec:
      backoffLimit: 2
      template:
        spec:
          restartPolicy: OnFailure
          containers:
            - name: prune
              image: matrixdotorg/synapse:v1.158.0
              # Credentials come from homeserver.yaml, which already carries the
              # Commons S3 keys — no second Secret to keep in sync or rotate.
              command:
                - sh
                - -c
                - |
                  set -e
                  mkdir -p /data/.media-prune && cd /data/.media-prune
                  eval "$(PYTHONPATH=/data/site-packages python - <<'PY'
                  import yaml, shlex
                  c = yaml.safe_load(open("/data/homeserver.yaml"))["media_storage_providers"][0]["config"]
                  print("export AWS_ACCESS_KEY_ID=%s" % shlex.quote(c["access_key_id"]))
                  print("export AWS_SECRET_ACCESS_KEY=%s" % shlex.quote(c["secret_access_key"]))
                  print("export AWS_DEFAULT_REGION=us-east-1")
                  print("export LK_BUCKET=%s" % shlex.quote(c["bucket"]))
                  print("export LK_ENDPOINT=%s" % shlex.quote(c["endpoint_url"]))
                  print("export LK_PREFIX=%s" % shlex.quote(c["prefix"]))
                  PY
                  )"
                  export PYTHONPATH=/data/site-packages
                  B=/data/site-packages/bin/s3_media_upload
                  python "$B" update-db {{ $mediaRetention ?? '30d' }} --homeserver-config-path /data/homeserver.yaml
                  # --delete only removes a local file after its upload is
                  # confirmed, so a failed upload can never lose the only copy.
                  python "$B" --no-progress upload /data/media_store "$LK_BUCKET" \
                    --prefix "$LK_PREFIX" --endpoint-url "$LK_ENDPOINT" --delete
              resources:
                requests:
                  memory: 64Mi
                  cpu: 50m
                limits:
                  memory: 256Mi
                  cpu: 500m
              volumeMounts:
                - name: data
                  mountPath: /data
                - name: config
                  mountPath: /data/homeserver.yaml
                  subPath: homeserver.yaml
          volumes:
            # ReadWriteOnce, shared with chat-synapse. Fine while both land on
            # one node; on a multi-node cluster this job must be pinned to
            # Synapse's node or given its own ReadWriteMany volume.
            - name: data
              persistentVolumeClaim:
                claimName: chat-synapse-data
            - name: config
              secret:
                secretName: chat-synapse-config
@endif
