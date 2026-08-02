apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getName() }}-wordpress
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $config->getName() }}-wordpress
  template:
    metadata:
      labels:
        app: {{ $config->getName() }}-wordpress
    spec:
      securityContext:
        fsGroup: 33
      initContainers:
        - name: wp-db-update
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command:
            - sh
            - -c
            - |
              wp --allow-root core update-db --no-color 2>/dev/null || true
          envFrom:
            - configMapRef:
                name: {{ $config->getName() }}-wordpress-config
            - secretRef:
                name: {{ $config->getName() }}-wordpress-secrets
      containers:
        - name: wordpress
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          ports:
            - containerPort: 8443
          env:
            - name: DISABLE_WP_CRON
              value: "true"
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
                name: {{ $config->getName() }}-wordpress-config
            - secretRef:
                name: {{ $config->getName() }}-wordpress-secrets
          startupProbe:
            httpGet:
              path: /wp-includes/version.php
              port: 8443
            periodSeconds: 10
            timeoutSeconds: 30
            failureThreshold: 30
          livenessProbe:
            httpGet:
              path: /wp-includes/version.php
              port: 8443
            initialDelaySeconds: 120
            periodSeconds: 60
            timeoutSeconds: 30
          readinessProbe:
            httpGet:
              path: /wp-includes/version.php
              port: 8443
            initialDelaySeconds: 30
            periodSeconds: 20
            timeoutSeconds: 30
          volumeMounts:
            - name: storage
              mountPath: /var/www/html/web/app/uploads
              subPath: uploads
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-wordpress-storage-pvc
