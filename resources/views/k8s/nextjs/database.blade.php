{{-- Self-hosted database for the --no-plex path (Plex projects use the Commons
     engine instead, so this is not rendered for them). Credentials come from the
     {name}-nextjs-secrets Secret; a PVC keeps data across restarts. --}}
@php($name = $config->getName())
@php($pod = $name.'-'.$driver->value)
@php($isPostgres = $driver === App\Enums\DatabaseDriver::POSTGRESQL)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $pod }}-pvc
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 2Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $pod }}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $pod }}
  template:
    metadata:
      labels:
        app: {{ $pod }}
    spec:
      containers:
        - name: {{ $pod }}
          image: {{ $driver->getDockerImage($config) }}
          ports:
            - containerPort: {{ $driver->dbPort() }}
          env:
@if($isPostgres)
            - name: POSTGRES_DB
              valueFrom: { secretKeyRef: { name: {{ $name }}-nextjs-secrets, key: DB_DATABASE } }
            - name: POSTGRES_USER
              valueFrom: { secretKeyRef: { name: {{ $name }}-nextjs-secrets, key: DB_USERNAME } }
            - name: POSTGRES_PASSWORD
              valueFrom: { secretKeyRef: { name: {{ $name }}-nextjs-secrets, key: DB_PASSWORD } }
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
@else
            - name: MYSQL_DATABASE
              valueFrom: { secretKeyRef: { name: {{ $name }}-nextjs-secrets, key: DB_DATABASE } }
            - name: MYSQL_USER
              valueFrom: { secretKeyRef: { name: {{ $name }}-nextjs-secrets, key: DB_USERNAME } }
            - name: MYSQL_PASSWORD
              valueFrom: { secretKeyRef: { name: {{ $name }}-nextjs-secrets, key: DB_PASSWORD } }
            - name: MYSQL_ROOT_PASSWORD
              valueFrom: { secretKeyRef: { name: {{ $name }}-nextjs-secrets, key: DB_PASSWORD } }
@endif
          readinessProbe:
            tcpSocket:
              port: {{ $driver->dbPort() }}
            initialDelaySeconds: 5
            periodSeconds: 10
          volumeMounts:
            - name: db-data
              mountPath: /var/lib/{{ $isPostgres ? 'postgresql' : 'mysql' }}
      volumes:
        - name: db-data
          persistentVolumeClaim:
            claimName: {{ $pod }}-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $pod }}
spec:
  selector:
    app: {{ $pod }}
  ports:
    - protocol: TCP
      port: {{ $driver->dbPort() }}
      targetPort: {{ $driver->dbPort() }}
  type: ClusterIP
