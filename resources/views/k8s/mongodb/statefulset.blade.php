apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: {{ $driver->getPodName($config) }}
spec:
  serviceName: {{ $driver->getPodName($config) }}
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
          env:
            - name: MONGO_INITDB_ROOT_USERNAME
              value: "{{ $driver->dbUsername() }}"
            - name: MONGO_INITDB_ROOT_PASSWORD
              value: "larakubesecretpassword"
          ports:
            - containerPort: {{ $driver->dbPort() }}
          volumeMounts:
            - name: db-data
              mountPath: /data/db
  volumeClaimTemplates:
    - metadata:
        name: db-data
      spec:
        accessModes: ["ReadWriteOnce"]
        resources:
          requests:
            storage: 5Gi
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
