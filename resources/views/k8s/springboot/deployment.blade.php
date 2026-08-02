apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $config->getName() }}-springboot
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: {{ $config->getName() }}-springboot
  template:
    metadata:
      labels:
        app: {{ $config->getName() }}-springboot
    spec:
      initContainers:
        - name: flyway-migrate
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command:
            - sh
            - -c
            - java -jar app.jar --flyway.enabled=true || true
          envFrom:
            - configMapRef:
                name: {{ $config->getName() }}-springboot-config
            - secretRef:
                name: {{ $config->getName() }}-springboot-secrets
      containers:
        - name: springboot
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command:
            - java
            - -jar
            - app.jar
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
                name: {{ $config->getName() }}-springboot-config
            - secretRef:
                name: {{ $config->getName() }}-springboot-secrets
          startupProbe:
            httpGet:
              path: /actuator/health
              port: 8080
            periodSeconds: 10
            timeoutSeconds: 10
            failureThreshold: 30
          livenessProbe:
            httpGet:
              path: /actuator/health
              port: 8080
            initialDelaySeconds: 30
            periodSeconds: 30
            timeoutSeconds: 10
          readinessProbe:
            httpGet:
              path: /actuator/health
              port: 8080
            initialDelaySeconds: 15
            periodSeconds: 15
            timeoutSeconds: 10
