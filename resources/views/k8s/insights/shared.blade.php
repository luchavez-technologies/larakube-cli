@php
    // Every name comes from ToolInstance (ADR 0021).
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::INSIGHTS->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::INSIGHTS, $instance);
    $deploymentName = $names->deployment();
    $secretName = $names->secret();
    $volume = $names->volume();
    $dbName ??= $names->database();
    $labels = '';
    foreach ($names->labels() as $key => $value) {
        $labels .= "\n    {$key}: {$value}";
    }
    $podLabels = str_replace("\n    ", "\n        ", $labels);
@endphp
@if($noPlex)
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
@endif
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
        - name: metabase
          image: metabase/metabase:v0.63.1
          env:
            - name: MB_ENCRYPTION_SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: encryption-key
@if($appName ?? null)
            - name: MB_SITE_NAME
              value: "{{ $appName }}"
@endif
@if($logoUrl ?? null)
            - name: MB_APPLICATION_LOGO_URL
              value: "{{ $logoUrl }}"
@endif
@if($noPlex)
            - name: MB_DB_FILE
              value: /metabase-data/metabase.db
@else
            - name: MB_DB_TYPE
              value: postgres
            - name: MB_DB_DBNAME
              value: {{ $dbName }}
            - name: MB_DB_PORT
              value: "5432"
            - name: MB_DB_USER
              value: {{ $dbName }}
            - name: MB_DB_HOST
              value: postgres.{{ $plexNamespace }}.svc.cluster.local
            - name: MB_DB_PASS
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
@endif
          ports:
            - containerPort: 3000
              name: http
          startupProbe:
            httpGet:
              path: /api/health
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 60
          readinessProbe:
            httpGet:
              path: /api/health
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 10
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /api/health
              port: 3000
            initialDelaySeconds: 60
            periodSeconds: 15
            failureThreshold: 6
          volumeMounts:
            - name: storage
              mountPath: /metabase-data
      volumes:
        - name: storage
@if($noPlex)
          persistentVolumeClaim:
            claimName: {{ $volume }}
@else
          emptyDir: {}
@endif
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
      port: 3000
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.insights.ingress', ['instance' => $instance])
