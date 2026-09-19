@php
    $names ??= \App\Data\ToolInstance::forHost(\App\Enums\ClusterTool::FLOW, $host, 'windmill');
    $tool = \App\Enums\ClusterTool::FLOW->vendor('windmill');
    $dbName ??= $names->database();
    $deployment = $names->deployment();
    $bundledDb = $names->name('db');
    $bundledDbVolume = $names->volume('db-storage');
@endphp
@if($noPlex)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $bundledDbVolume }}
  namespace: {{ $names->namespace() }}
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $volumeSize($bundledDbVolume, '5Gi', true) }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $bundledDb }}
  namespace: {{ $names->namespace() }}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $bundledDb }}
  template:
    metadata:
      labels:
        app: {{ $bundledDb }}
    spec:
      containers:
        - name: postgres
          image: {{ $tool->image('postgres') }}
          env:
            - name: POSTGRES_USER
              value: windmill
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $names->secret() }}
                  key: db-password
            - name: POSTGRES_DB
              value: windmill
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
          volumeMounts:
            - name: storage
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: {{ $bundledDbVolume }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $bundledDb }}
  namespace: {{ $names->namespace() }}
spec:
  selector:
    app: {{ $bundledDb }}
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
---
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deployment }}
  namespace: {{ $names->namespace() }}
  labels:
    app: {{ $deployment }}
    larakube-tool: flow
    larakube-engine: windmill
@if($noPlex)
    larakube-storage: bundled
@endif
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
    spec:
      containers:
        - name: windmill-server
          image: {{ $tool->image('windmill') }}
          env:
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $names->secret() }}
                  key: db-password
@if($noPlex)
            - name: DATABASE_URL
              value: postgres://windmill:$(DB_PASSWORD)@{{ $bundledDb }}:5432/windmill
@else
            - name: DATABASE_URL
              value: postgres://{{ $dbName }}:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName }}
@endif
            - name: MODE
              value: server
            - name: BASE_URL
              value: https://{{ $host }}
          ports:
            - containerPort: 8000
              name: http
          readinessProbe:
            httpGet:
              path: /api/health/status
              port: 8000
            initialDelaySeconds: 15
            periodSeconds: 10
        - name: windmill-worker
          image: {{ $tool->image('windmill') }}
          env:
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $names->secret() }}
                  key: db-password
@if($noPlex)
            - name: DATABASE_URL
              value: postgres://windmill:$(DB_PASSWORD)@{{ $bundledDb }}:5432/windmill
@else
            - name: DATABASE_URL
              value: postgres://{{ $dbName }}:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName }}
@endif
            - name: MODE
              value: worker
            - name: WORKER_GROUP
              value: default
        - name: windmill-lsp
          image: {{ $tool->image('lsp') }}
          ports:
            - containerPort: 3001
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $deployment }}
  namespace: {{ $names->namespace() }}
spec:
  selector:
    app: {{ $deployment }}
  ports:
    - protocol: TCP
      port: 8000
      targetPort: 8000
      name: http
    - protocol: TCP
      port: 3001
      targetPort: 3001
      name: lsp
  type: ClusterIP
---
@include('k8s.flow.ingress', ['names' => $names, 'servicePort' => 8000])
