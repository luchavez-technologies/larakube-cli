apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getName() }}-fastapi
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: {{ $config->getName() }}-fastapi
  template:
    metadata:
      labels:
        app: {{ $config->getName() }}-fastapi
    spec:
      initContainers:
        - name: alembic-migrate
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command:
            - sh
            - -c
            - alembic upgrade head || true
          envFrom:
            - configMapRef:
                name: {{ $config->getName() }}-fastapi-config
            - secretRef:
                name: {{ $config->getName() }}-fastapi-secrets
      containers:
        - name: fastapi
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command:
            - uvicorn
            - main:app
            - --host
            - 0.0.0.0
            - --port
            - "8000"
          ports:
            - containerPort: 8000
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
                name: {{ $config->getName() }}-fastapi-config
            - secretRef:
                name: {{ $config->getName() }}-fastapi-secrets
          startupProbe:
            httpGet:
              path: /healthz
              port: 8000
            periodSeconds: 10
            timeoutSeconds: 10
            failureThreshold: 30
          livenessProbe:
            httpGet:
              path: /healthz
              port: 8000
            initialDelaySeconds: 30
            periodSeconds: 30
            timeoutSeconds: 10
          readinessProbe:
            httpGet:
              path: /healthz
              port: 8000
            initialDelaySeconds: 15
            periodSeconds: 15
            timeoutSeconds: 10
