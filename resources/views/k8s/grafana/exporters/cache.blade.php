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
          env:
            - name: REDIS_ADDR
              value: "redis://{{ $driver->getPodName($config) }}:{{ $driver->dbPort() }}"
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
