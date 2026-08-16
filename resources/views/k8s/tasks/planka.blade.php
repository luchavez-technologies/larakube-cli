apiVersion: apps/v1
kind: Deployment
metadata:
  name: tasks-planka
  namespace: larakube-shared
  labels:
    app: tasks-planka
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: tasks-planka
  template:
    metadata:
      labels:
        app: tasks-planka
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
                  name: tasks-planka-secrets
                  key: secret-key
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: tasks-planka-secrets
                  key: db-password
            - name: DATABASE_URL
              value: "postgres://tasks_planka:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/tasks_planka"
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
  name: tasks
  namespace: larakube-shared
spec:
  selector:
    app: tasks-planka
  ports:
    - protocol: TCP
      port: 80
      targetPort: 1337
  type: ClusterIP
