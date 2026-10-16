---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $driver->getPodName($config) }}-exporter
  namespace: {{ $namespace }}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $driver->getPodName($config) }}-exporter
  template:
    metadata:
      labels:
        app: {{ $driver->getPodName($config) }}-exporter
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "{{ $driver->exporterPort() }}"
    spec:
      containers:
        - name: exporter
          image: {{ $driver->exporterImage() }}
          ports:
            - containerPort: {{ $driver->exporterPort() }}
@if($driver === \App\Enums\DatabaseDriver::POSTGRESQL)
          env:
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: laravel-secrets
                  key: DB_PASSWORD
            - name: DATA_SOURCE_NAME
              value: "postgresql://{{ $driver->dbUsername() }}:$(DB_PASSWORD)@{{ $driver->getPodName($config) }}:{{ $driver->dbPort() }}/laravel?sslmode=disable"
@else
          args:
            - "--mysqld.username=root"
            - "--mysqld.address={{ $driver->getPodName($config) }}:{{ $driver->dbPort() }}"
            - "--config.my-cnf="
          env:
            - name: MYSQLD_EXPORTER_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: laravel-secrets
                  key: DB_PASSWORD
@endif
          readinessProbe:
            httpGet:
              path: /
              port: {{ $driver->exporterPort() }}
            initialDelaySeconds: 5
            periodSeconds: 10
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $driver->getPodName($config) }}-exporter
  namespace: {{ $namespace }}
spec:
  selector:
    app: {{ $driver->getPodName($config) }}-exporter
  ports:
    - protocol: TCP
      port: {{ $driver->exporterPort() }}
      targetPort: {{ $driver->exporterPort() }}
  type: ClusterIP
