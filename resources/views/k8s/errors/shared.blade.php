@php
    // Every name comes from ToolInstance (ADR 0021).
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::ERRORS->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::ERRORS, $instance);
    $dbName ??= $names->database();
    $webName = $names->deployment();
    $workerName = $names->deployment('worker');
    $dbDeployment = $names->deployment('db');
    $cacheName = $names->deployment('cache');
    $secretName = $names->secret();
    $smtpSecret = $names->secret(\App\Enums\SecretKind::SMTP);
    $migrationsName = $names->name('migrations');
    $dbVolume = $names->volume('storage', 'db');
    // Identity labels per component; `pod` is the same set indented for a Pod template.
    $labelsFor = function (?string $component = null) use ($names): string {
        $out = '';
        foreach ($names->labels($component) as $key => $value) {
            $out .= "\n    {$key}: {$value}";
        }

        return $out;
    };
    $podLabelsFor = fn (?string $component = null): string => str_replace("\n    ", "\n        ", $labelsFor($component));
@endphp
apiVersion: v1
kind: Secret
metadata:
  name: {{ $secretName }}
  namespace: larakube-shared
  labels:{!! $labelsFor() !!}
type: Opaque
data:
  password: {{ base64_encode($adminPassword) }}
{{-- Confirmed live 2026-08-24 (same bug, chat/vpn Ingress): an indented @if
     directive leaks its own leading whitespace onto the next line once it
     closes — here it would push `secret-key:` deeper than `password:`,
     an invalid YAML mapping. Keep directive tags at column 0; only literal
     YAML content is indented. --}}
@if ($noPlex)
  database-url: {{ base64_encode("postgres://{$dbName}:{$dbPassword}@{$dbDeployment}:5432/{$dbName}") }}
  redis-url: {{ base64_encode("redis://{$cacheName}:6379/0") }}
@else
  database-url: {{ base64_encode("postgres://{$dbName}:{$dbPassword}@postgres.{$plexNamespace}.svc.cluster.local:5432/{$dbName}") }}
  redis-url: {{ base64_encode("redis://redis.{$plexNamespace}.svc.cluster.local:6379/{$redisIndex}") }}
@endif
  secret-key: {{ base64_encode(\Illuminate\Support\Str::random(50)) }}
---
apiVersion: batch/v1
kind: Job
metadata:
  name: {{ $migrationsName }}
  namespace: larakube-shared
  labels:{!! $labelsFor() !!}
spec:
  template:
    metadata:
      labels:{!! $podLabelsFor() !!}
    spec:
      restartPolicy: OnFailure
      containers:
        - name: migrate
          image: glitchtip/glitchtip:6.2.2
          command: ["./manage.py", "migrate"]
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: database-url
            - name: CELERY_BROKER_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: redis-url
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: secret-key
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $webName }}
  namespace: larakube-shared
  labels:{!! $labelsFor() !!}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $webName }}
  template:
    metadata:
      labels:
        app: {{ $webName }}{!! $podLabelsFor() !!}
    spec:
      containers:
        - name: web
          image: glitchtip/glitchtip:6.2.2
          ports:
            - containerPort: 8000
              name: http
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: database-url
            - name: CELERY_BROKER_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: redis-url
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: secret-key
            - name: GLITCHTIP_DOMAIN
              value: "https://{{ $host }}"
@if($appName ?? null)
            - name: GLITCHTIP_INSTANCE_NAME
              value: "{{ $appName }}"
@endif
            - name: GLITCHTIP_ADMIN_EMAIL
              value: "admin@larakube.local"
            - name: GLITCHTIP_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: password
            # SMTP (mail:wire): GlitchTip reads a single composed
            # django-environ URL plus the from-address — EMAIL_URL is built
            # at wire time (smtp+ssl:// with percent-encoded credentials,
            # see ErrorTool::smtpEnv()).
            - name: EMAIL_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: EMAIL_URL
                  optional: true
            - name: DEFAULT_FROM_EMAIL
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: DEFAULT_FROM_EMAIL
                  optional: true
          readinessProbe:
            httpGet:
              path: /
              port: 8000
            initialDelaySeconds: 15
            periodSeconds: 10
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $webName }}
  namespace: larakube-shared
  labels:{!! $labelsFor() !!}
spec:
  selector:
    app: {{ $webName }}
  ports:
    - protocol: TCP
      port: 8000
      targetPort: 8000
  type: ClusterIP
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $workerName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('worker') !!}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $workerName }}
  template:
    metadata:
      labels:
        app: {{ $workerName }}{!! $podLabelsFor('worker') !!}
    spec:
      containers:
        - name: worker
          image: glitchtip/glitchtip:6.2.2
          command: ["celery", "-A", "glitchtip", "worker", "-B"]
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: database-url
            - name: CELERY_BROKER_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: redis-url
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: secret-key
            - name: GLITCHTIP_DOMAIN
              value: "https://{{ $host }}"
            # SMTP (mail:wire): the celery worker sends the actual alert +
            # notification emails, so it mounts the same optional SMTP
            # secret keys as the web Deployment (see ErrorTool::smtpEnv()).
            - name: EMAIL_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: EMAIL_URL
                  optional: true
            - name: DEFAULT_FROM_EMAIL
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: DEFAULT_FROM_EMAIL
                  optional: true
---
@if ($noPlex)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $dbVolume }}
  namespace: larakube-shared
  labels:{!! $labelsFor('db') !!}
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: {{ $volumeSize($dbVolume, '2Gi', true) }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $dbDeployment }}
  namespace: larakube-shared
  labels:{!! $labelsFor('db') !!}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $dbDeployment }}
  template:
    metadata:
      labels:
        app: {{ $dbDeployment }}{!! $podLabelsFor('db') !!}
    spec:
      containers:
        - name: postgres
          image: postgres:15-alpine
          ports:
            - containerPort: 5432
          env:
            - name: POSTGRES_DB
              value: {{ $dbName }}
            - name: POSTGRES_USER
              value: {{ $dbName }}
            - name: POSTGRES_PASSWORD
              value: "{{ $dbPassword }}"
          volumeMounts:
            - name: storage
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $dbVolume }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $dbDeployment }}
  namespace: larakube-shared
  labels:{!! $labelsFor('db') !!}
spec:
  selector:
    app: {{ $dbDeployment }}
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
  type: ClusterIP
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $cacheName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('cache') !!}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $cacheName }}
  template:
    metadata:
      labels:
        app: {{ $cacheName }}{!! $podLabelsFor('cache') !!}
    spec:
      containers:
        - name: valkey
          image: valkey/valkey:8.0-alpine
          ports:
            - containerPort: 6379
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $cacheName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('cache') !!}
spec:
  selector:
    app: {{ $cacheName }}
  ports:
    - protocol: TCP
      port: 6379
      targetPort: 6379
  type: ClusterIP
---
@endif
@include('k8s.errors.ingress', ['instance' => $instance])
