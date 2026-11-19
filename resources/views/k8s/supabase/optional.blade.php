@php
    $tool = \App\Enums\ClusterTool::SUPABASE;
    $names = \App\Data\ToolInstance::forInstance($tool, $instance);
    $secretName = $names->secret();
    $dbHost = $names->name('db');
    $withRealtime ??= true;
    $withFunctions ??= true;
    $withImgproxy ??= true;
    $withPooler ??= true;
    $noPlex ??= false;
    $instance ??= 'supabase';
    $storagePvc = $names->volume('storage');
    $functionsPvc = $names->volume('functions');
    $stubLines = fn (string $file): array => explode("\n", (string) file_get_contents(resource_path('stubs/supabase/'.$file)));
    $labelsFor = function (string $component) use ($names): string {
        $out = '';
        foreach ($names->labels($component) as $k => $v) {
            $out .= "\n    {$k}: {$v}";
        }

        return $out;
    };
    $podLabelsFor = fn (string $component): string => str_replace("\n    ", "\n        ", $labelsFor($component));
@endphp
@if($withRealtime)
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $names->name('realtime') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('realtime') !!}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $names->name('realtime') }}
  template:
    metadata:
      labels:
        app: {{ $names->name('realtime') }}{!! $podLabelsFor('realtime') !!}
    spec:
@include('k8s.supabase.partials.wait-for-db', ['dbHost' => $dbHost])
      containers:
        - name: supabase-realtime
          image: {{ $tool->vendor()->image('realtime') }}
          ports:
            - containerPort: 4000
              name: http
          env:
            - name: PORT
              value: "4000"
            - name: DB_HOST
              value: {{ $dbHost }}
            - name: DB_PORT
              value: "5432"
            - name: DB_USER
              value: supabase_admin
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: DB_NAME
              value: postgres
            - name: DB_AFTER_CONNECT_QUERY
              value: "SET search_path TO _realtime"
            - name: DB_ENC_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: realtime-db-enc-key
            - name: API_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: SECRET_KEY_BASE
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: secret-key-base
            - name: METRICS_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            # The Erlang cookie the image needs; upstream generates one per start, this one persists.
            - name: RELEASE_COOKIE
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: realtime-release-cookie
            - name: ERL_AFLAGS
              value: "-proto_dist inet_tcp"
            # Single node: no cluster peers to reach or discover.
            - name: GEN_RPC_SOCKET_IP
              value: 127.0.0.1
            - name: CLUSTER_STRATEGIES
              value: ""
            - name: RLIMIT_NOFILE
              value: "10000"
            - name: APP_NAME
              value: realtime
            - name: SEED_SELF_HOST
              value: "true"
            - name: RUN_JANITOR
              value: "true"
            - name: DISABLE_HEALTHCHECK_LOGGING
              value: "true"
          resources:
            requests:
              cpu: 100m
              memory: 256Mi
            limits:
              cpu: 500m
              memory: 512Mi
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $names->name('realtime') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('realtime') !!}
spec:
  selector:
    app: {{ $names->name('realtime') }}
  ports:
    - port: 4000
      targetPort: 4000
      name: http
---
@endif
@if($withFunctions)
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $names->name('functions-main') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('functions') !!}
data:
  index.ts: |
@foreach($stubLines('functions/main/index.ts') as $line)
    {!! $line !!}
@endforeach
  memory-pressure.ts: |
@foreach($stubLines('functions/main/memory-pressure.ts') as $line)
    {!! $line !!}
@endforeach
  deno.jsonc: |
@foreach($stubLines('functions/deno.jsonc') as $line)
    {!! $line !!}
@endforeach
---
# Your functions live here, one folder each, beside upstream's main router.
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $functionsPvc }}
  namespace: larakube-shared
  labels:{!! $labelsFor('functions') !!}
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 1Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $names->name('functions') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('functions') !!}
spec:
  replicas: 1
  # Its volume attaches to one node: stop the old pod before starting the new one.
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $names->name('functions') }}
  template:
    metadata:
      labels:
        app: {{ $names->name('functions') }}{!! $podLabelsFor('functions') !!}
    spec:
      containers:
        - name: supabase-edge-functions
          image: {{ $tool->vendor()->image('functions') }}
          args: ["start", "--user-worker-request-idle-timeout", "150000", "--main-service", "/home/deno/functions/main"]
          ports:
            - containerPort: 9000
              name: http
          env:
            - name: JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: SUPABASE_URL
              value: "http://{{ $names->deployment() }}:8000"
            - name: SUPABASE_ANON_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: anon-key
            - name: SUPABASE_SERVICE_ROLE_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: service-role-key
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: SUPABASE_DB_URL
              value: "postgres://postgres:$(DB_PASSWORD){{ "@".$dbHost }}:5432/postgres"
            - name: SUPABASE_PUBLIC_URL
              value: "https://{{ $host ?? '' }}"
            - name: VERIFY_JWT
              value: "false"
          resources:
            requests:
              cpu: 100m
              memory: 256Mi
            limits:
              cpu: 500m
              memory: 512Mi
          volumeMounts:
            # Your functions, one folder each; upstream's main router is mounted over its own folder,
            # so a re-init updates it and never touches yours.
            - name: functions
              mountPath: /home/deno/functions
            # One subPath per file, never the ConfigMap as a folder: a folder mount is symlinks into a hidden
            # ..data/ dir, and Edge Runtime copies main/ to /var/tmp/sb-compile-edge-runtime without them
            # ("Module not found: …/main/index.ts"). subPath mounts are plain files.
            - name: functions-main
              mountPath: /home/deno/functions/main/index.ts
              subPath: index.ts
            - name: functions-main
              mountPath: /home/deno/functions/main/memory-pressure.ts
              subPath: memory-pressure.ts
            - name: functions-import-map
              mountPath: /home/deno/functions/deno.jsonc
              subPath: deno.jsonc
            - name: deno-cache
              mountPath: /root/.cache/deno
      volumes:
        - name: functions
          persistentVolumeClaim:
            claimName: {{ $functionsPvc }}
        - name: functions-main
          configMap:
            name: {{ $names->name('functions-main') }}
        - name: functions-import-map
          configMap:
            name: {{ $names->name('functions-main') }}
        - name: deno-cache
          emptyDir: {}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $names->name('functions') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('functions') !!}
spec:
  selector:
    app: {{ $names->name('functions') }}
  ports:
    - port: 9000
      targetPort: 9000
      name: http
---
@endif
@if($withImgproxy)
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $names->name('imgproxy') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('imgproxy') !!}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $names->name('imgproxy') }}
  template:
    metadata:
      labels:
        app: {{ $names->name('imgproxy') }}{!! $podLabelsFor('imgproxy') !!}
    spec:
      containers:
        - name: supabase-imgproxy
          image: {{ $tool->vendor()->image('imgproxy') }}
          ports:
            - containerPort: 5001
              name: http
          env:
            - name: IMGPROXY_BIND
              value: ":5001"
            - name: IMGPROXY_LOCAL_FILESYSTEM_ROOT
              value: /
            - name: IMGPROXY_USE_ETAG
              value: "true"
            - name: IMGPROXY_AUTO_WEBP
              value: "true"
            - name: IMGPROXY_MAX_SRC_RESOLUTION
              value: "16.8"
          resources:
            requests:
              cpu: 50m
              memory: 128Mi
            limits:
              cpu: 500m
              memory: 256Mi
@if($noPlex)
          # Storage keeps files on its own volume here, and imgproxy reads them from disk (upstream mounts the same folder).
          volumeMounts:
            - name: storage-data
              mountPath: /var/lib/storage
              readOnly: true
      volumes:
        - name: storage-data
          persistentVolumeClaim:
            claimName: {{ $storagePvc }}
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $names->name('imgproxy') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('imgproxy') !!}
spec:
  selector:
    app: {{ $names->name('imgproxy') }}
  ports:
    - port: 5001
      targetPort: 5001
      name: http
---
@endif
@if($withPooler)
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $names->name('pooler-config') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('supavisor') !!}
data:
  pooler.exs: |
@foreach($stubLines('pooler/pooler.exs') as $line)
    {!! $line !!}
@endforeach
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $names->name('supavisor') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('supavisor') !!}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $names->name('supavisor') }}
  template:
    metadata:
      labels:
        app: {{ $names->name('supavisor') }}{!! $podLabelsFor('supavisor') !!}
    spec:
@include('k8s.supabase.partials.wait-for-db', ['dbHost' => $dbHost])
      containers:
        - name: supabase-pooler
          image: {{ $tool->vendor()->image('supavisor') }}
          # Upstream's command: migrate, create the pooler tenant (pooler.exs, idempotent), then serve.
          command: ["/bin/sh", "-c", "/app/bin/migrate && /app/bin/supavisor eval \"$(cat /etc/pooler/pooler.exs)\" && /app/bin/server"]
          ports:
            - containerPort: 5432
              name: session
            - containerPort: 6543
              name: transaction
          env:
            - name: PORT
              value: "4000"
            - name: PROXY_PORT_SESSION
              value: "5432"
            - name: PROXY_PORT_TRANSACTION
              value: "6543"
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: DATABASE_URL
              value: "ecto://supabase_admin:$(DB_PASSWORD){{ "@".$dbHost }}:5432/_supabase"
            - name: POSTGRES_HOST
              value: {{ $dbHost }}
            - name: POSTGRES_PORT
              value: "5432"
            - name: POSTGRES_DB
              value: postgres
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: CLUSTER_POSTGRES
              value: "true"
            - name: REGION
              value: local
            - name: ERL_AFLAGS
              value: "-proto_dist inet_tcp"
            # Clients connect as postgres.<tenant>; the instance is the tenant.
            - name: POOLER_TENANT_ID
              value: "{{ $instance }}"
            - name: POOLER_DEFAULT_POOL_SIZE
              value: "20"
            - name: POOLER_MAX_CLIENT_CONN
              value: "100"
            - name: POOLER_POOL_MODE
              value: transaction
            - name: DB_POOL_SIZE
              value: "5"
            - name: SECRET_KEY_BASE
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: secret-key-base
            - name: VAULT_ENC_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: vault-enc-key
            - name: API_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: METRICS_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
          resources:
            requests:
              cpu: 100m
              memory: 256Mi
            limits:
              cpu: 500m
              memory: 512Mi
          volumeMounts:
            - name: pooler-config
              mountPath: /etc/pooler
              readOnly: true
      volumes:
        - name: pooler-config
          configMap:
            name: {{ $names->name('pooler-config') }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $names->name('supavisor') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('supavisor') !!}
spec:
  selector:
    app: {{ $names->name('supavisor') }}
  ports:
    - port: 5432
      targetPort: 5432
      name: session
    - port: 6543
      targetPort: 6543
      name: transaction
@endif
