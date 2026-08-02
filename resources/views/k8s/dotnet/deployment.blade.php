apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getName() }}-dotnet
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: {{ $config->getName() }}-dotnet
  template:
    metadata:
      labels:
        app: {{ $config->getName() }}-dotnet
    spec:
      initContainers:
        - name: ef-migrate
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command:
            - sh
            - -c
            - dotnet ef database update || true
          envFrom:
            - configMapRef:
                name: {{ $config->getName() }}-dotnet-config
            - secretRef:
                name: {{ $config->getName() }}-dotnet-secrets
      containers:
        - name: dotnet
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          env:
            - name: ASPNETCORE_HTTP_PORTS
              value: "8080"
            - name: ASPNETCORE_ENVIRONMENT
              value: "Production"
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
                name: {{ $config->getName() }}-dotnet-config
            - secretRef:
                name: {{ $config->getName() }}-dotnet-secrets
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
