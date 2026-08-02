apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getName() }}-axum
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: {{ $config->getName() }}-axum
  template:
    metadata:
      labels:
        app: {{ $config->getName() }}-axum
    spec:
      initContainers:
        - name: sqlx-migrate
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command:
            - sh
            - -c
            - sqlx migrate run || true
          envFrom:
            - configMapRef:
                name: {{ $config->getName() }}-axum-config
            - secretRef:
                name: {{ $config->getName() }}-axum-secrets
      containers:
        - name: axum
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          ports:
            - containerPort: 8080
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
                name: {{ $config->getName() }}-axum-config
            - secretRef:
                name: {{ $config->getName() }}-axum-secrets
          startupProbe:
            httpGet:
              path: /healthz
              port: 8080
            periodSeconds: 10
            timeoutSeconds: 10
            failureThreshold: 30
          livenessProbe:
            httpGet:
              path: /healthz
              port: 8080
            initialDelaySeconds: 30
            periodSeconds: 30
            timeoutSeconds: 10
          readinessProbe:
            httpGet:
              path: /healthz
              port: 8080
            initialDelaySeconds: 15
            periodSeconds: 15
            timeoutSeconds: 10
