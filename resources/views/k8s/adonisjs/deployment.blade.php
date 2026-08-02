apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getName() }}-adonisjs
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: {{ $config->getName() }}-adonisjs
  template:
    metadata:
      labels:
        app: {{ $config->getName() }}-adonisjs
    spec:
      initContainers:
        - name: lucid-migrate
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command:
            - sh
            - -c
            - node ace migration:run --force || true
          envFrom:
            - configMapRef:
                name: {{ $config->getName() }}-adonisjs-config
            - secretRef:
                name: {{ $config->getName() }}-adonisjs-secrets
      containers:
        - name: adonisjs
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          env:
            - name: PORT
              value: "3333"
            - name: HOST
              value: "0.0.0.0"
            - name: NODE_ENV
              value: "production"
          ports:
            - containerPort: 3333
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
                name: {{ $config->getName() }}-adonisjs-config
            - secretRef:
                name: {{ $config->getName() }}-adonisjs-secrets
          startupProbe:
            httpGet:
              path: /healthz
              port: 3333
            periodSeconds: 10
            timeoutSeconds: 10
            failureThreshold: 30
          livenessProbe:
            httpGet:
              path: /healthz
              port: 3333
            initialDelaySeconds: 30
            periodSeconds: 30
            timeoutSeconds: 10
          readinessProbe:
            httpGet:
              path: /healthz
              port: 3333
            initialDelaySeconds: 15
            periodSeconds: 15
            timeoutSeconds: 10
