@php
    $names ??= \App\Data\ToolInstance::forHost(\App\Enums\ClusterTool::ANALYTICS, $host);
    $deployment = $names->deployment();
    $service = $names->deployment();
    $secret = $names->secret();
    $dbName = $names->database();
    $labels = $names->labels();
@endphp
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deployment }}
  namespace: {{ $names->namespace() }}
  labels:
    app: {{ $deployment }}
    larakube-tool: analytics
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $deployment }}
  template:
    metadata:
      labels:
        app: {{ $deployment }}
        larakube-tool: analytics
@foreach($labels as $key => $value)
        {{ $key }}: {{ $value }}
@endforeach
    spec:
      containers:
        - name: umami
          image: ghcr.io/umami-software/umami:postgresql-latest
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: APP_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secret }}
                  key: app-secret
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secret }}
                  key: db-password
            - name: DATABASE_URL
              value: "postgres://{{ $dbName }}:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName }}"
          startupProbe:
            httpGet:
              path: /api/heartbeat
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /api/heartbeat
              port: 3000
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /api/heartbeat
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 15
            timeoutSeconds: 5
          resources:
            requests:
              memory: 128Mi
              cpu: 50m
            limits:
              memory: 256Mi
              cpu: 100m
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $service }}
  namespace: {{ $names->namespace() }}
  labels:
    app: {{ $deployment }}
    larakube-tool: analytics
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  selector:
    app: {{ $deployment }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.analytics.ingress')
