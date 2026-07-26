apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $driver->getPodName($config) }}
spec:
  replicas: 1
  strategy:
    type: Recreate
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
            - containerPort: 7700
          env:
            - name: MEILI_MASTER_KEY
              valueFrom:
                secretKeyRef:
                  name: laravel-secrets
                  key: MEILISEARCH_KEY
            - name: MEILI_ENV
              value: "production"
            - name: MEILI_DB_PATH
              value: "/meili_data/data.ms"
          readinessProbe:
            httpGet:
              path: /health
              port: 7700
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: /health
              port: 7700
            initialDelaySeconds: 15
            periodSeconds: 20
          volumeMounts:
            - name: meilisearch-data
              mountPath: /meili_data
      volumes:
        - name: meilisearch-data
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-meilisearch-pvc
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
      port: 7700
      targetPort: 7700
  type: ClusterIP
