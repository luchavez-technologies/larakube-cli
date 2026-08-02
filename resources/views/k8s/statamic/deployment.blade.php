apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getName() }}-statamic
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $config->getName() }}-statamic
  template:
    metadata:
      labels:
        app: {{ $config->getName() }}-statamic
    spec:
      securityContext:
        fsGroup: 33
      containers:
        - name: statamic
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          ports:
            - containerPort: 8443
          env:
            - name: AUTORUN_ENABLED
              value: "true"
            - name: AUTORUN_LARAVEL_MIGRATION
              value: "true"
            - name: PHP_EXTENSIONS_ADD
              value: "gd exif"
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
                name: {{ $config->getName() }}-statamic-config
            - secretRef:
                name: {{ $config->getName() }}-statamic-secrets
          startupProbe:
            httpGet:
              path: /up
              port: 8443
            periodSeconds: 10
            timeoutSeconds: 30
            failureThreshold: 30
          livenessProbe:
            httpGet:
              path: /up
              port: 8443
            initialDelaySeconds: 120
            periodSeconds: 60
            timeoutSeconds: 30
          readinessProbe:
            httpGet:
              path: /up
              port: 8443
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
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-statamic-storage-pvc
