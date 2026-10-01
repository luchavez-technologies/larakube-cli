@php
    // Every name comes from ToolInstance (ADR 0021).
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::UPTIME->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::UPTIME, $instance);
    $deploymentName = $names->deployment();
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
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: {{ $volumeSize($volume, '2Gi', true) }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deploymentName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
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
        app: {{ $deploymentName }}
    spec:
      containers:
        - name: uptime-kuma
          image: louislam/uptime-kuma:1
          ports:
            - containerPort: 3001
              name: ui
          volumeMounts:
            - name: uptime-kuma-volume
              mountPath: /app/data
          startupProbe:
            httpGet:
              path: /
              port: 3001
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /
              port: 3001
            initialDelaySeconds: 5
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /
              port: 3001
            initialDelaySeconds: 10
            periodSeconds: 10
      volumes:
        - name: uptime-kuma-volume
          persistentVolumeClaim:
            claimName: {{ $volume }}
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
      port: 3001
      targetPort: 3001
  type: ClusterIP
---
@include('k8s.uptime.ingress', ['instance' => $instance])
