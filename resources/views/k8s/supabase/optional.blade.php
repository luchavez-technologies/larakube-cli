@php
    $tool = \App\Enums\ClusterTool::SUPABASE;
    $names = \App\Data\ToolInstance::forInstance($tool, $instance);
    $secretName = $names->secret();
    $dbHost = $names->name('db');
    $withRealtime ??= true;
    $withFunctions ??= true;
    $withImgproxy ??= true;
    $withPooler ??= true;
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
            - name: SEED_SELF_HOST
              value: "true"
            - name: RUN_JANITOR
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
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $names->name('functions') }}
  namespace: larakube-shared
  labels:{!! $labelsFor('functions') !!}
spec:
  replicas: 1
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
          command: ["edge-runtime", "start", "--main-service", "/home/deno/functions/main"]
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
              value: "postgres://postgres:$(DB_PASSWORD)@{{ $dbHost }}:5432/postgres"
            - name: VERIFY_JWT
              value: "false"
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
            - name: IMGPROXY_ENABLE_WEBP_DETECTION
              value: "true"
          resources:
            requests:
              cpu: 50m
              memory: 128Mi
            limits:
              cpu: 500m
              memory: 256Mi
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
      containers:
        - name: supabase-pooler
          image: {{ $tool->vendor()->image('supavisor') }}
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
              value: "ecto://supabase_admin:$(DB_PASSWORD)@{{ $dbHost }}:5432/_supabase"
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
