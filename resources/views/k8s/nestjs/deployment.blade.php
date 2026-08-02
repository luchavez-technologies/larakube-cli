apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getName() }}-nestjs
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: {{ $config->getName() }}-nestjs
  template:
    metadata:
      labels:
        app: {{ $config->getName() }}-nestjs
    spec:
      initContainers:
        - name: prisma-migrate
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command:
            - sh
            - -c
            - npx prisma migrate deploy || true
          envFrom:
            - configMapRef:
                name: {{ $config->getName() }}-nestjs-config
            - secretRef:
                name: {{ $config->getName() }}-nestjs-secrets
      containers:
        - name: nestjs
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          env:
            - name: PORT
              value: "3000"
            - name: NODE_ENV
              value: "production"
          ports:
            - containerPort: 3000
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
                name: {{ $config->getName() }}-nestjs-config
            - secretRef:
                name: {{ $config->getName() }}-nestjs-secrets
          startupProbe:
            httpGet:
              path: /healthz
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 10
            failureThreshold: 30
          livenessProbe:
            httpGet:
              path: /healthz
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 30
            timeoutSeconds: 10
          readinessProbe:
            httpGet:
              path: /healthz
              port: 3000
            initialDelaySeconds: 15
            periodSeconds: 15
            timeoutSeconds: 10
