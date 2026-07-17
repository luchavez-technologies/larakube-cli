@if($noPlex)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: flow-storage
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 5Gi
---
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: flow-n8n
  namespace: larakube-shared
  labels:
    app: flow-n8n
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: flow-n8n
  template:
    metadata:
      labels:
        app: flow-n8n
    spec:
      containers:
        - name: n8n
          image: docker.n8n.io/n8nio/n8n:2.29.8
          env:
            - name: N8N_ENCRYPTION_KEY
              valueFrom:
                secretKeyRef:
                  name: flow-secrets
                  key: encryption-key
            # Public URL, so generated webhook/chat/editor links use the real
            # host behind Traefik instead of localhost:5678.
            - name: N8N_HOST
              value: {{ $host }}
            - name: N8N_PROTOCOL
              value: https
            - name: WEBHOOK_URL
              value: https://{{ $host }}/
            - name: N8N_EDITOR_BASE_URL
              value: https://{{ $host }}/
@if($noPlex)
            - name: DB_TYPE
              value: sqlite
            - name: DB_SQLITE_DATABASE
              value: /home/node/.n8n/n8n.db
@else
            - name: DB_TYPE
              value: postgresdb
            - name: DB_POSTGRESDB_HOST
              value: postgres.{{ $plexNamespace }}.svc.cluster.local
            - name: DB_POSTGRESDB_PORT
              value: "5432"
            - name: DB_POSTGRESDB_DATABASE
              value: n8n
            - name: DB_POSTGRESDB_USER
              value: n8n
            - name: DB_POSTGRESDB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: flow-secrets
                  key: db-password
@endif
          ports:
            - containerPort: 5678
              name: http
          readinessProbe:
            tcpSocket:
              port: 5678
            initialDelaySeconds: 10
            periodSeconds: 5
          livenessProbe:
            tcpSocket:
              port: 5678
            initialDelaySeconds: 15
            periodSeconds: 10
          volumeMounts:
            - name: storage
              mountPath: /home/node/.n8n
      volumes:
        - name: storage
@if($noPlex)
          persistentVolumeClaim:
            claimName: flow-storage
@else
          emptyDir: {}
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: flow-n8n
  namespace: larakube-shared
spec:
  selector:
    app: flow-n8n
  ports:
    - protocol: TCP
      port: 5678
      targetPort: 5678
  type: ClusterIP
---
@include('k8s.flow.ingress')
