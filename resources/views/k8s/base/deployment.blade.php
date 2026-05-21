apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getServerVariation()->getPodName($config) }}
spec:
  replicas: 1
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
@if($config->isSystem())
      serviceAccountName: larakube-dashboard
@endif
@if($waitCmd = $config->buildWaitForCommand($config->getCoreDependencies()))
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
          envFrom:
            - configMapRef:
                name: laravel-config
            - secretRef:
                name: laravel-secrets
          livenessProbe:
            httpGet:
              path: /up
              port: {{ $config->getServerVariation()->containerPort() }}
            initialDelaySeconds: 60
            periodSeconds: 30
            timeoutSeconds: 5
          readinessProbe:
            httpGet:
              path: /up
              port: {{ $config->getServerVariation()->containerPort() }}
            initialDelaySeconds: 10
            periodSeconds: 10
            timeoutSeconds: 5
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
            - name: larakube-config
              mountPath: /var/www/html/.larakube.json
              subPath: .larakube.json
            - name: larakube-workspace
              mountPath: /var/lib/larakube-workspace
              readOnly: true
@endif
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-storage-pvc
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
        - name: data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-data-pvc
@endif
@if($config->isSystem())
        - name: larakube-config
          hostPath:
            path: {{ $_SERVER['HOME'] }}/.larakube
            type: DirectoryOrCreate
        - name: larakube-workspace
          hostPath:
            path: {{ $workspacePath }}
            type: Directory
@endif
