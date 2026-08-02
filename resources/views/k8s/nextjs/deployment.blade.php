apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getName() }}-nextjs
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: {{ $config->getName() }}-nextjs
  template:
    metadata:
      labels:
        app: {{ $config->getName() }}-nextjs
    spec:
      initContainers:
        - name: prisma-migrate
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command:
            - sh
            - -c
            - npx prisma migrate deploy
          envFrom:
            - configMapRef:
                name: {{ $config->getName() }}-nextjs-config
            - secretRef:
                name: {{ $config->getName() }}-nextjs-secrets
      containers:
        - name: nextjs
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          ports:
            - containerPort: 3000
          env:
            - name: HOSTNAME
              value: "0.0.0.0"
            - name: PORT
              value: "3000"
            - name: NODE_ENV
              value: "production"
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
                name: {{ $config->getName() }}-nextjs-config
            - secretRef:
                name: {{ $config->getName() }}-nextjs-secrets
          startupProbe:
            httpGet:
              path: /api/health
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 10
            failureThreshold: 30
          livenessProbe:
            httpGet:
              path: /api/health
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 30
            timeoutSeconds: 10
          readinessProbe:
            httpGet:
              path: /api/health
              port: 3000
            initialDelaySeconds: 15
            periodSeconds: 15
            timeoutSeconds: 10
