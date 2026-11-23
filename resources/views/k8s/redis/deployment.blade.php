apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $driver->getPodName($config) }}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $driver->getPodName($config) }}
  template:
    metadata:
      labels:
        app: {{ $driver->getPodName($config) }}
    spec:
      containers:
        - name: {{ $driver->getPodName($config) }}
          image: {{ $driver->getDockerImage($config) }}
          ports:
            - containerPort: {{ $driver->dbPort() }}
          # A TCP check, not `redis-cli ping`: an exec probe starts a process on
          # every check, and on a busy node it can miss the timeout and get a
          # healthy Redis restarted. Nothing is persisted, so there is no
          # loading phase for a TCP check to miss.
          readinessProbe:
            tcpSocket:
              port: {{ $driver->dbPort() }}
            initialDelaySeconds: 2
            periodSeconds: 5
            timeoutSeconds: 3
          livenessProbe:
            tcpSocket:
              port: {{ $driver->dbPort() }}
            initialDelaySeconds: 10
            periodSeconds: 20
            timeoutSeconds: 3
            failureThreshold: 6
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $driver->getPodName($config) }}
spec:
  selector:
    app: {{ $driver->getPodName($config) }}
  ports:
    - protocol: TCP
      port: {{ $driver->dbPort() }}
      targetPort: {{ $driver->dbPort() }}
  type: ClusterIP
