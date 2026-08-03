apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getServerVariation()->getPodName($config) }}
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $config->getServerVariation()->getPodName($config) }}
  template:
    metadata:
      labels:
        app: {{ $config->getServerVariation()->getPodName($config) }}
    spec:
      # The app runs as www-data; mounted PVCs (storage/, bootstrap/cache) come up
      # root-owned on real block storage (e.g. DOKS do-block-storage), so without
      # this they'd be read-only to the app. fsGroup makes the kubelet chown the
      # volume to this GID and add it to the container's supplementary groups, so
      # www-data can write. Harmless on k3s local-path (VPS), so it's identical
      # across VPS and managed clusters.
      securityContext:
        fsGroup: 33
@if($config->isSystem())
      serviceAccountName: larakube-dashboard
@endif
@if($waitCmd = $config->buildWaitForCommand($config->getCoreDependencies($environment)))
      initContainers:
        - name: wait-for-deps
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command: ["sh", "-c", "{!! $waitCmd !!}"]
@endif
      containers:
        - name: php
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          args: {!! $command !!}
          ports:
            - containerPort: {{ $config->getServerVariation()->containerPort() }}
          env:
            - name: AUTORUN_ENABLED
              value: "true"
            - name: AUTORUN_LARAVEL_MIGRATION
              value: "true"
@if($config->isSystem())
            - name: LARAKUBE_HOST_WORKSPACE
              value: {{ $workspacePath }}
@endif
@php($resources = $config->getResources($environment ?? 'local', 'web'))
@if(!empty($resources['requests']) || !empty($resources['limits']))
          resources:
@if(!empty($resources['requests']))
            requests:
@foreach($resources['requests'] as $dim => $val)
              {{ $dim }}: "{{ $val }}"
@endforeach
@endif
@if(!empty($resources['limits']))
            limits:
@foreach($resources['limits'] as $dim => $val)
              {{ $dim }}: "{{ $val }}"
@endforeach
@endif
@endif
          envFrom:
            - configMapRef:
                name: laravel-config
            - secretRef:
                name: laravel-secrets
          # Gate liveness/readiness behind startup so a slow first boot
          # (composer/artisan migrate before the server binds) can't be
          # SIGKILLed mid-flight. failureThreshold 30 x 10s = up to 5 min.
          startupProbe:
            httpGet:
              path: /up
              port: {{ $config->getServerVariation()->containerPort() }}
            periodSeconds: 10
            timeoutSeconds: 30
            failureThreshold: 30
          livenessProbe:
            httpGet:
              path: /up
              port: {{ $config->getServerVariation()->containerPort() }}
            initialDelaySeconds: 120
            periodSeconds: 60
            timeoutSeconds: 30
          readinessProbe:
            httpGet:
              path: /up
              port: {{ $config->getServerVariation()->containerPort() }}
            initialDelaySeconds: 30
            periodSeconds: 20
            timeoutSeconds: 30
          volumeMounts:
            - name: storage
              mountPath: /var/www/html/storage/logs
              subPath: logs
            - name: storage
              mountPath: /var/www/html/bootstrap/cache
              subPath: bootstrap/cache
            - name: storage
              mountPath: /var/www/html/storage/framework/sessions
              subPath: framework/sessions
            - name: storage
              mountPath: /var/www/html/storage/framework/views
              subPath: framework/views
            - name: storage
              mountPath: /var/www/html/storage/framework/cache
              subPath: framework/cache
            - name: storage
              mountPath: /var/www/html/storage/app/public
              subPath: app/public
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
            - name: data
              mountPath: /var/lib/larakube
@endif
@if($config->isSystem())
            - name: larakube-workspace
              mountPath: /var/lib/larakube-workspace
              readOnly: true
@endif
      volumes:{{-- 'code' is hostPath-mounted only by the LOCAL overlay's deployment-patch; cloud envs use the image. --}}
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-storage-pvc
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
        - name: data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-data-pvc
@endif
@if($config->isSystem())
        - name: larakube-workspace
          hostPath:
            path: {{ $workspacePath }}
            type: Directory
@endif
