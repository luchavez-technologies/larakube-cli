{{-- Next.js standalone server. Rendered for each cloud environment and for the
     local `preview` overlay; $resourceName distinguishes them ({name}-nextjs vs
     web-preview). The image tag is rewritten per overlay by kustomize.

     Phase 1: a plain `node server.js` workload — no envFrom config/secret and no
     Prisma init container yet (those arrive with the Redis cache and Prisma
     phases). NODE_ENV/PORT/HOSTNAME are set inline so the server binds correctly
     even before any ConfigMap exists. --}}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $resourceName }}
spec:
  replicas: {{ $config->getReplicas($environment ?? 'local', 'web') }}
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
      initContainers:
        # Apply Prisma migrations before the server starts. Reads DATABASE_URL
        # from the Secret; the pinned prisma@6 CLI is fetched at run time and the
        # schema ships in the image (COPY prisma in Dockerfile.nextjs).
        - name: prisma-migrate
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          command: ["sh", "-c", "npx --yes prisma@6 migrate deploy"]
          envFrom:
            - secretRef:
                name: {{ $config->getName() }}-nextjs-secrets
      containers:
        - name: nextjs
          image: {{ $config->getName() }}:latest
          imagePullPolicy: IfNotPresent
          ports:
            - containerPort: 3000
          env:
            - name: NODE_ENV
              value: "production"
            - name: PORT
              value: "3000"
            - name: HOSTNAME
              value: "0.0.0.0"
          envFrom:
            # DATABASE_URL + REDIS_URL (+ DB_* on the self-hosted path).
            - secretRef:
                name: {{ $config->getName() }}-nextjs-secrets
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
