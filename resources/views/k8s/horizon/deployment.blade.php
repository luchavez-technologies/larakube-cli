apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $feature->getPodName($config) }}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $feature->getPodName($config) }}
  template:
    metadata:
      labels:
        app: {{ $feature->getPodName($config) }}
    spec:
@if($config->isSystem())
      serviceAccountName: larakube-dashboard
@endif
@if($waitCmd = $config->buildWaitForCommand($feature->getDependencies($config), waitForWeb: true))
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
          args: {!! $feature->getK8sDeploymentArgs() !!}
          envFrom:
            - configMapRef:
                name: laravel-config
            - secretRef:
                name: laravel-secrets
          env:
            - name: AUTORUN_ENABLED
              value: "false"
            - name: AUTORUN_LARAVEL_MIGRATION
              value: "false"
@if($config->isSystem())
            - name: LARAKUBE_HOST_WORKSPACE
              value: {{ $workspacePath }}
@endif
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
@if($config->isSystem())
            - name: larakube-config
              mountPath: /var/lib/larakube
            - name: larakube-workspace
              mountPath: /var/lib/larakube-workspace
              readOnly: true
@endif
          livenessProbe:
            exec:
              command: ["healthcheck-horizon"]
            initialDelaySeconds: 30
            periodSeconds: 30
            timeoutSeconds: 10
          readinessProbe:
            exec:
              command: ["healthcheck-horizon"]
            initialDelaySeconds: 10
            periodSeconds: 10
            timeoutSeconds: 10
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-laravel-storage-pvc
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
