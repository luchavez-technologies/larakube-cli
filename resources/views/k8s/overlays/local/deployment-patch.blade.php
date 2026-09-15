@foreach(['web', 'horizon', 'queues', 'reverb', 'node'] as $name)
@php($feature = \App\Enums\LaravelFeature::fromPodName($name))
@if($name === 'web' || ($feature && $config->hasFeature($feature)) || ($name === 'node' && $config->getFrontend()?->requiresNodePod()))
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $name }}
spec:
@if($name === 'node')
  strategy:
    type: Recreate
@endif
  template:
    spec:
      containers:
        - name: {{ $name === 'node' ? 'node' : 'php' }}
          imagePullPolicy: IfNotPresent
@if($name !== 'node')
          # Local dev: service connection vars (DB_*, REDIS_*, etc.) come from
          # ConfigMap/Secret via envFrom; everything else reads from .env
          # mounted on the hostPath. .env edits are live without larakube up.
          # Explicit env entries override Kubernetes service discovery auto-injection.
          envFrom:
            - configMapRef:
                name: laravel-config
            - secretRef:
                name: laravel-secrets
          env:
            # Override Kubernetes auto-injected service discovery vars with ConfigMap values.
            # (Explicit env takes precedence over envFrom, which takes precedence over service links)
            - name: REDIS_PORT
              valueFrom:
                configMapKeyRef:
                  name: laravel-config
                  key: REDIS_PORT
                  optional: true
            - name: DB_PORT
              valueFrom:
                configMapKeyRef:
                  name: laravel-config
                  key: DB_PORT
                  optional: true
            # Skip all SSU on-startup caching in local dev. Caching freezes
            # pod env vars (DB_CONNECTION=pgsql, etc.) into bootstrap/cache/*.php
            # files that win over process env, silently breaking test-time
            # overrides and hiding .env edits until pod restart. We disable:
            #   - the umbrella `optimize` command (older SSU images)
            #   - the granular individual cache commands (newer SSU images
            #     split optimize into separate steps; we cover both shapes)
            # Production keeps the defaults (true) for performance.
            - name: AUTORUN_LARAVEL_OPTIMIZE
              value: "false"
            - name: AUTORUN_LARAVEL_CONFIG_CACHE
              value: "false"
            - name: AUTORUN_LARAVEL_ROUTE_CACHE
              value: "false"
            - name: AUTORUN_LARAVEL_VIEW_CACHE
              value: "false"
            - name: AUTORUN_LARAVEL_EVENT_CACHE
              value: "false"
@endif
          volumeMounts:
            - name: code
              mountPath: /var/www/html
@if($name !== 'node' && $config->framework === \App\Enums\AppFramework::WORDPRESS)
            - name: storage
              mountPath: /var/www/html/web/app/uploads
              subPath: uploads
@elseif($name !== 'node')
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
@endif
      volumes:
        - name: code
          hostPath:
            path: "{{ $config->getPath() }}"
            type: Directory
@if($name !== 'node')
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-storage-pvc
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
        - name: data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-data-pvc
@endif
@endif
@endif
@endforeach
@if($config->hasFeature(\App\Enums\LaravelFeature::TASK_SCHEDULING))
---
apiVersion: batch/v1
kind: CronJob
metadata:
  name: scheduler
spec:
  jobTemplate:
    spec:
      template:
        spec:
          containers:
            - name: php
              imagePullPolicy: IfNotPresent
              envFrom:
                - configMapRef:
                    name: laravel-config
                - secretRef:
                    name: laravel-secrets
              env:
                # Override Kubernetes service discovery auto-injection.
                - name: REDIS_PORT
                  valueFrom:
                    configMapKeyRef:
                      name: laravel-config
                      key: REDIS_PORT
                      optional: true
                - name: DB_PORT
                  valueFrom:
                    configMapKeyRef:
                      name: laravel-config
                      key: DB_PORT
                      optional: true
              volumeMounts:
                - name: code
                  mountPath: /var/www/html
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
          volumes:
            - name: code
              hostPath:
                path: "{{ $config->getPath() }}"
                type: Directory
            - name: storage
              persistentVolumeClaim:
                claimName: {{ $config->getName() }}-laravel-storage-pvc
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
            - name: data
              persistentVolumeClaim:
                claimName: {{ $config->getName() }}-laravel-data-pvc
@endif
@endif
