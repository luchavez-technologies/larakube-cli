@php
    // Every name comes from ToolInstance (ADR 0021).
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::PASTE->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::PASTE, $instance);
    $deploymentName = $names->deployment();
    $secretName = $names->secret();
    $labels = '';
    foreach ($names->labels() as $key => $value) {
        $labels .= "\n    {$key}: {$value}";
    }
    $podLabels = str_replace("\n    ", "\n        ", $labels);
@endphp
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deploymentName }}
  namespace: larakube-shared
  labels:
    app: {{ $deploymentName }}{!! $labels !!}
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
      containers:
        - name: yopass
          {{-- Confirmed live via api.github.com/repos/jhaals/yopass/tags
               (2026-08-20) — 14.8.0 is the latest tagged release. Never
               :latest. --}}
          image: jhaals/yopass:14.8.0
          args:
            - --database=redis
            - --redis=redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}
            - --default-expiry=1d
            - --max-length=10000
@if($fileStorage ?? null)
            - --file-store=s3
            - --file-store-s3-bucket={{ $fileStorage['bucket'] }}
            - --file-store-s3-endpoint={{ $fileStorage['endpoint'] }}
            - --file-store-s3-region={{ $fileStorage['region'] }}
@endif
          ports:
            - containerPort: 1337
              name: http
@if($fileStorage ?? null)
          env:
            {{-- Yopass's S3 file-storage reads credentials from the
                 standard AWS env vars, not a flag. --}}
            - name: AWS_ACCESS_KEY_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-access-key
            - name: AWS_SECRET_ACCESS_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-secret-key
@endif
          startupProbe:
            httpGet:
              path: /
              port: 1337
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /
              port: 1337
            initialDelaySeconds: 5
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /
              port: 1337
            initialDelaySeconds: 10
            periodSeconds: 10
---
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
    - protocol: TCP
      port: 1337
      targetPort: 1337
  type: ClusterIP
---
@include('k8s.paste.ingress', ['instance' => $instance])
