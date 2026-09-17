{{-- A server-app framework's production workload (NestJS, and the frameworks
     that follow it onto this engine). Rendered for each cloud environment and
     for the local `preview` overlay; $resourceName tells them apart
     ({name}-{framework} vs web-preview).

     Runtime env: the preview overlay carries its own Secret ($envSecret), built
     from the local .env into the gitignored local overlay. Cloud overlays never
     commit credentials: they read laravel-config/laravel-secrets, which
     `larakube dotenv:push` writes straight to the cluster.

     Blade @if directives stay at column 0; an indented one leaks whitespace
     into the following key. --}}
@php($framework = $config->framework)
@php($port = $framework->containerPort())
@php($probe = $framework->healthProbePath())
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $resourceName }}
spec:
  replicas: {{ $config->getReplicas($environment, 'web') }}
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: {{ $resourceName }}
  template:
    metadata:
      labels:
        app: {{ $resourceName }}
    spec:
@if($environment !== 'local' && $config->getRegistry($environment) && ($pullSecret = $config->getImagePullSecret($environment)))
      imagePullSecrets:
        - name: {{ $pullSecret }}
@endif
@if($migrate)
      initContainers:
        - name: migrate
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command: ["sh", "-c", {!! json_encode($migrate) !!}]
          envFrom:
@if($envSecret)
            - secretRef:
                name: {{ $envSecret }}
@else
            - configMapRef:
                name: laravel-config
                optional: true
            - secretRef:
                name: laravel-secrets
@endif
@endif
      containers:
        - name: {{ $framework->value }}
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          ports:
            - containerPort: {{ $port }}
          env:
            - name: PORT
              value: "{{ $port }}"
          envFrom:
@if($envSecret)
            - secretRef:
                name: {{ $envSecret }}
@else
            - configMapRef:
                name: laravel-config
                optional: true
            - secretRef:
                name: laravel-secrets
@endif
@php($resources = $config->getResources($environment, 'web'))
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
          startupProbe:
            httpGet:
              path: {{ $probe }}
              port: {{ $port }}
            periodSeconds: 10
            timeoutSeconds: 10
            failureThreshold: 30
          livenessProbe:
            httpGet:
              path: {{ $probe }}
              port: {{ $port }}
            initialDelaySeconds: 30
            periodSeconds: 30
            timeoutSeconds: 10
          readinessProbe:
            httpGet:
              path: {{ $probe }}
              port: {{ $port }}
            initialDelaySeconds: 5
            periodSeconds: 10
            timeoutSeconds: 10
