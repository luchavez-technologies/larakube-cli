apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: uptime-kuma-storage
  namespace: larakube-shared
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 2Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: uptime-kuma
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: uptime-kuma
  template:
    metadata:
      labels:
        app: uptime-kuma
    spec:
      containers:
        - name: uptime-kuma
          image: louislam/uptime-kuma:1
          ports:
            - containerPort: 3001
              name: ui
          volumeMounts:
            - name: uptime-kuma-volume
              mountPath: /app/data
          readinessProbe:
            httpGet:
              path: /
              port: 3001
            initialDelaySeconds: 5
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /
              port: 3001
            initialDelaySeconds: 10
            periodSeconds: 10
      volumes:
        - name: uptime-kuma-volume
          persistentVolumeClaim:
            claimName: uptime-kuma-storage
---
apiVersion: v1
kind: Service
metadata:
  name: uptime-kuma
  namespace: larakube-shared
spec:
  selector:
    app: uptime-kuma
  ports:
    - protocol: TCP
      port: 3001
      targetPort: 3001
  type: ClusterIP
---
@include('k8s.uptime.ingress')
