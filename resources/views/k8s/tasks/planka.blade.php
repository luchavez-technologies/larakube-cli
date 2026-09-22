@php
    $names ??= \App\Data\ToolInstance::forHost(\App\Enums\ClusterTool::TASKS, $host, 'planka');
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
    larakube-tool: tasks
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
        larakube-tool: tasks
@foreach($labels as $key => $value)
        {{ $key }}: {{ $value }}
@endforeach
    spec:
      containers:
        - name: planka
          image: ghcr.io/plankanban/planka:2.2.1
          ports:
            - containerPort: 1337
              name: http
          env:
            - name: BASE_URL
              value: "https://{{ $host }}"
            - name: TRUST_PROXY
              value: "1"
            - name: SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secret }}
                  key: secret-key
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secret }}
                  key: db-password
            - name: DATABASE_URL
              value: "postgres://{{ $dbName }}:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName }}"
          startupProbe:
            httpGet:
              path: /
              port: 1337
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /
              port: 1337
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /
              port: 1337
            initialDelaySeconds: 30
            periodSeconds: 15
            timeoutSeconds: 5
          resources:
            requests:
              memory: 256Mi
              cpu: 50m
            limits:
              memory: 512Mi
              cpu: 200m
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $service }}
  namespace: {{ $names->namespace() }}
  labels:
    app: {{ $deployment }}
    larakube-tool: tasks
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  selector:
    app: {{ $deployment }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 1337
  type: ClusterIP
