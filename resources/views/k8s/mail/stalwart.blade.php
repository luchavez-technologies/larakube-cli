@php
    // Every name comes from ToolInstance (ADR 0021).
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::MAIL->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) $host));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::MAIL, $instance);
    $deploymentName = $names->deployment();
    $smtpServiceName = $names->name('mail');
    $mailSecretsName = $names->secret();
    $configMapName = $names->configMap('config');
    // The OpenBao-synced store Secret: DB and S3 credentials that rotation rewrites.
    $openBaoSyncedSecretName = $names->secret(\App\Enums\SecretKind::STORE);
    $volume = $names->volume();
    $labels = '';
    foreach ($names->labels() as $key => $value) {
        $labels .= "\n    {$key}: {$value}";
    }
    $podLabels = str_replace("\n    ", "\n        ", $labels);
@endphp
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $volume }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $volumeSize($volume, '5Gi', true) }}
---
@if($storeBootstrap ?? null)
{{-- EXPERIMENTAL, local-only: pre-seeds Stalwart's DataStore config.json so
     it skips bootstrap/wizard mode on first boot — see
     MailInitCommand::bootstrapStalwartStoreForLocal(). authSecret references
     STALWART_STORE_PASSWORD by NAME, never embeds the value itself. --}}
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $configMapName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
data:
  config.json: |
    {
      "@type": "PostgreSql",
      "host": "{{ $storeBootstrap['host'] }}",
      "port": {{ $storeBootstrap['port'] }},
      "database": "{{ $storeBootstrap['database'] }}",
      "authUsername": "{{ $storeBootstrap['username'] }}",
      "authSecret": { "@type": "EnvironmentVariable", "variableName": "STALWART_STORE_PASSWORD" }
    }
---
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deploymentName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
    app: {{ $deploymentName }}
  annotations:
    secret.reloader.stakater.com/reload: "{{ $openBaoSyncedSecretName }},{{ $mailSecretsName }}"
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $deploymentName }}
  template:
    metadata:
      labels:
        app: {{ $deploymentName }}{!! $podLabels !!}
    spec:
      securityContext:
        # The image runs as the unprivileged 'stalwart' user (UID 2000); the
        # mounted data/config volumes must be group-writable by it.
        fsGroup: 2000
      containers:
        - name: stalwart
          # PINNED, not :latest. Stalwart's management surface moves between
          # minor versions — 0.16 removed the POST /api/settings REST endpoint
          # this CLI used to write config, and reshaped directory config into
          # JMAP objects. Floating :latest silently rolled a live cluster onto
          # that and broke SSO wiring with no deploy of ours. Bump deliberately,
          # and re-check app/Traits/InteractsWithStalwartApi.php when you do.
          {{-- The `v` is REQUIRED: stalwartlabs publishes v0.16.14, not 0.16.14.
               Without it the pull 404s, and because this Deployment is a single
               replica the old pod is already gone by then — mail goes down, it
               does not merely fail to update. --}}
          image: stalwartlabs/stalwart:v0.16.14
          env:
            # BREAK-GLASS credential — NOT the daily driver. The CLI authenticates
            # to Stalwart with a least-privilege API key it mints on first use
            # (mail-secrets/api-key, owned by the larakube-automation principal;
            # see InteractsWithStalwartApi::stalwartAuthHeader). This recovery
            # admin is used only to (a) bootstrap that key on first run and
            # (b) rescue it via `larakube mail:recover` if it's lost. Stalwart
            # discourages leaving it set permanently, but we keep it pinned
            # deliberately so the one-command rescue path is always available;
            # it is no longer used for normal operation.
            - name: STALWART_RECOVERY_ADMIN
              valueFrom:
                secretKeyRef:
                  name: {{ $mailSecretsName }}
                  key: recovery-admin
            # Public base URL for JMAP/OAuth discovery — required behind a proxy.
            - name: STALWART_PUBLIC_URL
              value: https://{{ $host }}
            # Allow cross-origin requests from Bulwark webmail.
            - name: STALWART_HTTP_PERMISSIVE_CORS
              value: "true"
            - name: STALWART_SERVER_HTTP_CORS_ALLOW_ORIGIN
              value: "*"
            {{-- Named refs, NOT `envFrom: secretRef`. The synced Secret mirrors
                 every key stored in the cluster backend — SIGN_ENCRYPTION_KEY,
                 RECORD_JWT_SECRET, every other tool's credentials — as env vars
                 inside the mail server. Naming the three Stalwart actually needs
                 keeps the blast radius to those three. --}}
@if($storeBootstrap ?? null)
            - name: STALWART_STORE_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $mailSecretsName }}
                  key: store-password
@else
            - name: STALWART_STORE_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $openBaoSyncedSecretName }}
                  key: STALWART_STORE_PASSWORD
                  optional: true
@endif
@if(($storeBootstrap['blob'] ?? null))
            - name: STALWART_S3_KEY_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $mailSecretsName }}
                  key: s3-access-key
            - name: STALWART_S3_SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $mailSecretsName }}
                  key: s3-secret-key
@else
            - name: STALWART_S3_KEY_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $openBaoSyncedSecretName }}
                  key: STALWART_S3_KEY_ID
                  optional: true
            - name: STALWART_S3_SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $openBaoSyncedSecretName }}
                  key: STALWART_S3_SECRET_KEY
                  optional: true
@endif
@if(($storeBootstrap['search']['type'] ?? null) === 'meilisearch')
            - name: STALWART_SEARCH_MEILI_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $mailSecretsName }}
                  key: search-meili-key
@endif
          ports:
            - { containerPort: 8080, name: http }
            - { containerPort: 25, @if($hostPort)hostPort: 25, @endif name: smtp }
            - { containerPort: 587, @if($hostPort)hostPort: 587, @endif name: submission }
            - { containerPort: 465, @if($hostPort)hostPort: 465, @endif name: submissions }
            - { containerPort: 993, @if($hostPort)hostPort: 993, @endif name: imaps }
            - { containerPort: 4190, @if($hostPort)hostPort: 4190, @endif name: sieve }
          # /healthz/{ready,live} are Stalwart's own purpose-built probes —
          # each returns non-200 if the process is stuck, unresponsive, or
          # deadlocked internally. The plain tcpSocket check this replaces
          # only verified the listener accepted a connection, which stays
          # green even while the app itself is hung — Kubernetes had no way
          # to notice or auto-restart it, so the admin UI/Bulwark silently
          # stopped answering until someone manually ran mail:restart.
          readinessProbe:
            httpGet:
              path: /healthz/ready
              port: 8080
            initialDelaySeconds: 10
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /healthz/live
              port: 8080
            initialDelaySeconds: 20
            periodSeconds: 15
          volumeMounts:
            # Persistent store: RocksDB (or Postgres-backed) config + data on a
            # standalone PVC. One claim serves both Stalwart's writable config
            # dir (/etc/stalwart) and its data dir (/var/lib/stalwart) via
            # subPaths — no Commons, no ConfigMap. config.json here names the
            # database, so the claim must be copied (not recreated) on a rename.
            - name: stalwart-data
              mountPath: /var/lib/stalwart
              subPath: data
            - name: stalwart-data
              mountPath: /etc/stalwart
              subPath: etc
@if($storeBootstrap ?? null)
            {{-- Overlays a single read-only file on top of the writable PVC
                 mount above — a standard, supported k8s pattern. Presence of
                 this file at process start is what skips bootstrap mode; see
                 MailInitCommand::bootstrapStalwartStoreForLocal(). --}}
            - name: stalwart-config
              mountPath: /etc/stalwart/config.json
              subPath: config.json
              readOnly: true
@endif
      volumes:
        - name: stalwart-data
          persistentVolumeClaim:
            claimName: {{ $volume }}
@if($storeBootstrap ?? null)
        - name: stalwart-config
          configMap:
            name: {{ $configMapName }}
@endif
---
# HTTP admin + JMAP, fronted by Traefik (TLS terminated at the ingress).
apiVersion: v1
kind: Service
metadata:
  name: {{ $deploymentName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  selector:
    app: {{ $deploymentName }}
  ports:
    - { protocol: TCP, port: 8080, targetPort: 8080, name: http }
  type: ClusterIP
---
# L4 mail listeners. When hostPort is enabled, the pod binds directly to the
# node's network interface — the Service stays ClusterIP for internal routing.
# Without hostPort, Klipper (k3s) or a cloud LoadBalancer exposes these ports.
apiVersion: v1
kind: Service
metadata:
  name: {{ $smtpServiceName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  selector:
    app: {{ $deploymentName }}
  ports:
    - { protocol: TCP, port: 25, targetPort: 25, name: smtp }
    - { protocol: TCP, port: 587, targetPort: 587, name: submission }
    - { protocol: TCP, port: 465, targetPort: 465, name: submissions }
    - { protocol: TCP, port: 993, targetPort: 993, name: imaps }
    - { protocol: TCP, port: 4190, targetPort: 4190, name: sieve }
  type: {{ $hostPort ? 'ClusterIP' : 'LoadBalancer' }}
---
@include('k8s.mail.ingress')
