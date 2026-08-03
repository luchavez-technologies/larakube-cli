{{-- The shared "Commons" services for Plex. Applied with `-n larakube-plex`.
     The plex-commons ConfigMap embeds the spec (self-describing — see plex:export).
     The admin Secret (plex-admin) and the plex-registry ConfigMap are managed by
     the CLI separately so re-running plex:init never rotates the password nor
     wipes tenant allocations. --}}
apiVersion: v1
kind: ConfigMap
metadata:
  name: plex-commons
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
data:
  commons.json: |
{!! $specJsonIndented !!}
@if(($spec['services']['postgres']['enabled'] ?? false))
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: postgres-data
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $spec['services']['postgres']['storage'] }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: postgres
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: postgres
  template:
    metadata:
      labels:
        app: postgres
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "9187"
    spec:
      containers:
        - name: postgres
          image: {{ $spec['services']['postgres']['image'] }}
          ports:
            - containerPort: {{ $spec['services']['postgres']['port'] }}
          env:
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: POSTGRES_PASSWORD
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
          resources:
            requests:
              memory: "128Mi"
              cpu: "100m"
            limits:
              # Shared DB ceiling — raise via the spec's postgres.memory if it OOMs
              # (a Commons serving many tenant databases needs headroom).
              memory: "{{ $spec['services']['postgres']['memory'] }}"
              cpu: "500m"
          readinessProbe:
            tcpSocket:
              port: {{ $spec['services']['postgres']['port'] }}
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            tcpSocket:
              port: {{ $spec['services']['postgres']['port'] }}
            initialDelaySeconds: 15
            periodSeconds: 20
          volumeMounts:
            - name: data
              mountPath: /var/lib/postgresql/data
        - name: postgres-exporter
          image: {{ \App\Enums\DatabaseDriver::POSTGRESQL->exporterImage() }}
          ports:
            - containerPort: 9187
          env:
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: POSTGRES_PASSWORD
            - name: DATA_SOURCE_NAME
              value: "postgresql://postgres:$(POSTGRES_PASSWORD)@127.0.0.1:5432/postgres?sslmode=disable"
          resources:
            requests:
              memory: "32Mi"
              cpu: "25m"
            limits:
              memory: "64Mi"
              cpu: "100m"
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: postgres-data
---
apiVersion: v1
kind: Service
metadata:
  name: postgres
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: postgres
  ports:
    - protocol: TCP
      port: {{ $spec['services']['postgres']['port'] }}
      targetPort: {{ $spec['services']['postgres']['port'] }}
  type: ClusterIP
@endif
@foreach(['mysql', 'mariadb'] as $engine)
@if(($spec['services'][$engine]['enabled'] ?? false))
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $engine }}-data
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $spec['services'][$engine]['storage'] }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $engine }}
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $engine }}
  template:
    metadata:
      labels:
        app: {{ $engine }}
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "9104"
    spec:
      containers:
        - name: {{ $engine }}
          image: {{ $spec['services'][$engine]['image'] }}
          ports:
            - containerPort: {{ $spec['services'][$engine]['port'] }}
          env:
            # Root creds the CLI uses to provision tenant DBs (commonsAdminClient).
            # MariaDB 11+ uses the `mariadb` binary; MySQL uses `mysql`. Both
            # engines honour MYSQL_ROOT_PASSWORD as the root password env var.
            - name: MYSQL_ROOT_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: MYSQL_ROOT_PASSWORD
          resources:
            requests:
              memory: "256Mi"
              cpu: "100m"
            limits:
              # Shared DB ceiling — raise via the spec's {{ $engine }}.memory if it OOMs.
              memory: "{{ $spec['services'][$engine]['memory'] }}"
              cpu: "500m"
          readinessProbe:
            tcpSocket:
              port: {{ $spec['services'][$engine]['port'] }}
            initialDelaySeconds: 10
            periodSeconds: 10
          livenessProbe:
            tcpSocket:
              port: {{ $spec['services'][$engine]['port'] }}
            initialDelaySeconds: 30
            periodSeconds: 20
          volumeMounts:
            - name: data
              mountPath: /var/lib/mysql
        - name: mysqld-exporter
          image: {{ \App\Enums\DatabaseDriver::MYSQL->exporterImage() }}
          ports:
            - containerPort: 9104
          env:
            - name: MYSQL_ROOT_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: MYSQL_ROOT_PASSWORD
            - name: DATA_SOURCE_NAME
              value: "root:$(MYSQL_ROOT_PASSWORD)@(127.0.0.1:3306)/"
          resources:
            requests:
              memory: "32Mi"
              cpu: "25m"
            limits:
              memory: "64Mi"
              cpu: "100m"
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: {{ $engine }}-data
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $engine }}
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: {{ $engine }}
  ports:
    - protocol: TCP
      port: {{ $spec['services'][$engine]['port'] }}
      targetPort: {{ $spec['services'][$engine]['port'] }}
  type: ClusterIP
@endif
@endforeach
@if(($spec['services']['redis']['enabled'] ?? false))
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: redis
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  selector:
    matchLabels:
      app: redis
  template:
    metadata:
      labels:
        app: redis
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "9121"
    spec:
      containers:
        - name: redis
          image: {{ $spec['services']['redis']['image'] }}
          ports:
            - containerPort: {{ $spec['services']['redis']['port'] }}
          resources:
            requests:
              memory: "32Mi"
              cpu: "50m"
            limits:
              memory: "{{ $spec['services']['redis']['memory'] }}"
              cpu: "250m"
          readinessProbe:
            exec:
              command: ["redis-cli", "ping"]
            initialDelaySeconds: 2
            periodSeconds: 5
          livenessProbe:
            exec:
              command: ["redis-cli", "ping"]
            initialDelaySeconds: 5
            periodSeconds: 10
        - name: redis-exporter
          image: {{ \App\Enums\CacheDriver::REDIS->exporterImage() }}
          ports:
            - containerPort: 9121
          env:
            - name: REDIS_ADDR
              value: "redis://127.0.0.1:6379"
          resources:
            requests:
              memory: "16Mi"
              cpu: "25m"
            limits:
              memory: "32Mi"
              cpu: "50m"
---
apiVersion: v1
kind: Service
metadata:
  name: redis
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: redis
  ports:
    - protocol: TCP
      port: {{ $spec['services']['redis']['port'] }}
      targetPort: {{ $spec['services']['redis']['port'] }}
  type: ClusterIP
@endif
@if(($spec['services']['meilisearch']['enabled'] ?? false))
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: meilisearch-data
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $spec['services']['meilisearch']['storage'] }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: meilisearch
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: meilisearch
  template:
    metadata:
      labels:
        app: meilisearch
    spec:
      containers:
        - name: meilisearch
          image: {{ $spec['services']['meilisearch']['image'] }}
          ports:
            - containerPort: {{ $spec['services']['meilisearch']['port'] }}
          env:
            - name: MEILI_ENV
              value: production
            - name: MEILI_NO_ANALYTICS
              value: "true"
            - name: MEILI_MASTER_KEY
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: MEILI_MASTER_KEY
          resources:
            requests:
              memory: "256Mi"
              cpu: "100m"
            limits:
              memory: "{{ $spec['services']['meilisearch']['memory'] }}"
              cpu: "500m"
          volumeMounts:
            - name: data
              mountPath: /meili_data
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: meilisearch-data
---
apiVersion: v1
kind: Service
metadata:
  name: meilisearch
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: meilisearch
  ports:
    - protocol: TCP
      port: {{ $spec['services']['meilisearch']['port'] }}
      targetPort: {{ $spec['services']['meilisearch']['port'] }}
  type: ClusterIP
@endif
@if(($spec['services']['seaweedfs']['enabled'] ?? false))
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: seaweedfs-data
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $spec['services']['seaweedfs']['storage'] }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: seaweedfs
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: seaweedfs
  template:
    metadata:
      labels:
        app: seaweedfs
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "9333"
    spec:
      initContainers:
        # Without an -s3.config, SeaweedFS has NO S3 identities: it allows
        # anonymous access but REJECTS every SigV4-signed request with
        # "Signed request requires setting up SeaweedFS S3 authentication".
        # Since every S3 SDK signs once it is given credentials, that silently
        # broke uploads for every tool leasing a Commons bucket.
        # Built here from the plex-admin Secret so the keys never land in a
        # ConfigMap.
        - name: s3-identities
          image: {{ $spec['services']['seaweedfs']['image'] }}
          command: ["sh", "-c"]
          args:
            - |
              set -e
              cat > /s3/config.json <<JSON
              {
                "identities": [
                  {
                    "name": "larakube",
                    "credentials": [
                      { "accessKey": "${S3_ACCESS_KEY}", "secretKey": "${S3_SECRET_KEY}" }
                    ],
                    "actions": ["Admin", "Read", "Write", "List", "Tagging"]
                  }
                ]
              }
              JSON
          env:
            - name: S3_ACCESS_KEY
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: S3_ACCESS_KEY
            - name: S3_SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: S3_SECRET_KEY
          volumeMounts:
            - name: s3-config
              mountPath: /s3
      containers:
        - name: seaweedfs
          image: {{ $spec['services']['seaweedfs']['image'] }}
          # All-in-one server with the S3 gateway enabled. S3 is in-cluster only
          # (ClusterIP) by default; tenants are isolated by their own bucket.
          # The master's own admin UI (9333) always binds too — only exposed
          # via Service/Ingress when admin_host is set (see below).
          args: ["server", "-dir=/data", "-s3", "-s3.config=/s3/config.json"]
          ports:
            - containerPort: {{ $spec['services']['seaweedfs']['port'] }}
            - containerPort: 9333
          resources:
            requests:
              memory: "256Mi"
              cpu: "100m"
            limits:
              memory: "{{ $spec['services']['seaweedfs']['memory'] }}"
              cpu: "500m"
          volumeMounts:
            - name: data
              mountPath: /data
            - name: s3-config
              mountPath: /s3
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: seaweedfs-data
        - name: s3-config
          emptyDir: {}
---
apiVersion: v1
kind: Service
metadata:
  name: seaweedfs
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: seaweedfs
  ports:
    - name: s3
      protocol: TCP
      port: {{ $spec['services']['seaweedfs']['port'] }}
      targetPort: {{ $spec['services']['seaweedfs']['port'] }}
    - name: master
      protocol: TCP
      port: 9333
      targetPort: 9333
  type: ClusterIP
@if(! empty($spec['services']['seaweedfs']['host']))
---
# Public S3 endpoint (so tenants can generate public file URLs via AWS_URL).
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: seaweedfs-s3
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@unless($isLocal ?? false)
    # Without this, Traefik serves its fallback self-signed cert on the public
    # S3 host — which browsers reject, breaking every presigned attachment URL
    # a tool hands out (locally the LaraKube Local CA covers it instead).
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@endunless
spec:
  rules:
    - host: {{ $spec['services']['seaweedfs']['host'] }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: seaweedfs
                port:
                  number: {{ $spec['services']['seaweedfs']['port'] }}
  tls:
    - hosts:
        - {{ $spec['services']['seaweedfs']['host'] }}
@endif
@if(! empty($spec['services']['seaweedfs']['admin_host']))
---
# Master admin UI (port 9333) — separate from the S3 API host above. Only
# ever set for a LOCAL dev cluster (see ensurePublicHosts()): SeaweedFS's
# master UI has NO built-in authentication at all, and shows the whole
# cluster (all tenants' volumes), so exposing it on a real/shared Commons
# would let anyone who reaches the host browse everything with zero login.
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: seaweedfs-admin
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: {{ $spec['services']['seaweedfs']['admin_host'] }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: seaweedfs
                port:
                  number: 9333
  tls:
    - hosts:
        - {{ $spec['services']['seaweedfs']['admin_host'] }}
@endif
@endif
@if(($spec['services']['minio']['enabled'] ?? false))
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: minio-data
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $spec['services']['minio']['storage'] }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: minio
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: minio
  template:
    metadata:
      labels:
        app: minio
    spec:
      containers:
        - name: minio
          image: {{ $spec['services']['minio']['image'] }}
          # S3 API on 9000, web console on 9001. Tenants are isolated by bucket.
          args: ["server", "/data", "--console-address", ":9001"]
          ports:
            - containerPort: {{ $spec['services']['minio']['port'] }}
            - containerPort: 9001
          env:
            # MinIO's root creds ARE the shared S3 key the CLI provisions buckets
            # with (commonsBucketCreateCommand) and tenants authenticate against.
            - name: MINIO_ROOT_USER
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: S3_ACCESS_KEY
            - name: MINIO_ROOT_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: plex-admin
                  key: S3_SECRET_KEY
          resources:
            requests:
              memory: "256Mi"
              cpu: "100m"
            limits:
              memory: "{{ $spec['services']['minio']['memory'] }}"
              cpu: "500m"
          readinessProbe:
            httpGet:
              path: /minio/health/ready
              port: {{ $spec['services']['minio']['port'] }}
            initialDelaySeconds: 5
            periodSeconds: 5
          volumeMounts:
            - name: data
              mountPath: /data
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: minio-data
---
apiVersion: v1
kind: Service
metadata:
  name: minio
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: minio
  ports:
    - name: s3
      protocol: TCP
      port: {{ $spec['services']['minio']['port'] }}
      targetPort: {{ $spec['services']['minio']['port'] }}
    - name: console
      protocol: TCP
      port: 9001
      targetPort: 9001
  type: ClusterIP
@if(! empty($spec['services']['minio']['host']))
---
# Public S3 endpoint (so tenants can generate public file URLs via AWS_URL).
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: minio-s3
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: {{ $spec['services']['minio']['host'] }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: minio
                port:
                  number: {{ $spec['services']['minio']['port'] }}
  tls:
    - hosts:
        - {{ $spec['services']['minio']['host'] }}
@endif
@if(! empty($spec['services']['minio']['console_host']))
---
# Admin console (port 9001) — separate from the S3 API host above. Only ever
# set for a LOCAL dev cluster (see ensurePublicHosts()): MinIO's root creds
# here ARE the shared cross-tenant admin key (see the Deployment above), so
# exposing this console on a real/shared Commons would let anyone who reaches
# the host browse every tenant's buckets. Not offered/prompted off-local.
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: minio-console
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: {{ $spec['services']['minio']['console_host'] }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: minio
                port:
                  number: 9001
  tls:
    - hosts:
        - {{ $spec['services']['minio']['console_host'] }}
@endif
@endif
@if(($spec['services']['garage']['enabled'] ?? false))
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: garage-data
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $spec['services']['garage']['storage'] }}
---
# Single Commons-wide instance shared by every tenant (bucket-per-tenant
# isolation, like MinIO/SeaweedFS) — single node, so replication_factor=1.
# No root_domain under [s3_api] (unlike the per-project version): the Commons
# is consumed path-style only (AWS_USE_PATH_STYLE_ENDPOINT=true, same as
# every other self-hosted S3 backend), and [s3_web] is omitted entirely since
# static-site bucket hosting isn't wired up for Commons tenants yet.
apiVersion: v1
kind: ConfigMap
metadata:
  name: garage-config
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
data:
  garage.toml: |
    metadata_dir = "/data/meta"
    data_dir = "/data/data"
    rpc_bind_addr = "[::]:3901"
    rpc_secret = "3e8d49cdaecefd63e56dc6d2f791cb60f856cd7471555b038bde1ac0751682a8"
    replication_factor = 1

    [s3_api]
    s3_region = "us-east-1"
    api_bind_addr = "[::]:3900"

    [admin]
    api_bind_addr = "0.0.0.0:3903"
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: garage
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: garage
  template:
    metadata:
      labels:
        app: garage
    spec:
      containers:
        - name: garage
          image: {{ $spec['services']['garage']['image'] }}
          args: ["/garage", "server"]
          ports:
            - name: s3
              containerPort: {{ $spec['services']['garage']['port'] }}
            - name: admin
              containerPort: 3903
          resources:
            requests:
              memory: "256Mi"
              cpu: "100m"
            limits:
              memory: "{{ $spec['services']['garage']['memory'] }}"
              cpu: "500m"
          volumeMounts:
            - name: config
              mountPath: /etc/garage.toml
              subPath: garage.toml
            - name: data
              mountPath: /data
      volumes:
        - name: config
          configMap:
            name: garage-config
        - name: data
          persistentVolumeClaim:
            claimName: garage-data
---
apiVersion: v1
kind: Service
metadata:
  name: garage
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: garage
  ports:
    - name: s3
      protocol: TCP
      port: {{ $spec['services']['garage']['port'] }}
      targetPort: {{ $spec['services']['garage']['port'] }}
    - name: admin
      protocol: TCP
      port: 3903
      targetPort: 3903
  type: ClusterIP
@if(! empty($spec['services']['garage']['host']))
---
# Public S3 endpoint (so tenants can generate public file URLs via AWS_URL).
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: garage-s3
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: {{ $spec['services']['garage']['host'] }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: garage
                port:
                  number: {{ $spec['services']['garage']['port'] }}
  tls:
    - hosts:
        - {{ $spec['services']['garage']['host'] }}
@endif
@endif
