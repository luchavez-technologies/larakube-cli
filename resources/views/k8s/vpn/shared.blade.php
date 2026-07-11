apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: netbird-management-storage
  namespace: larakube-vpn
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 2Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: netbird-management
  namespace: larakube-vpn
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: netbird-management
  template:
    metadata:
      labels:
        app: netbird-management
    spec:
      containers:
        - name: management
          image: netbirdio/management:latest
          ports:
            - containerPort: 80
              name: http
          volumeMounts:
            - name: storage
              mountPath: /var/lib/netbird
          readinessProbe:
            httpGet:
              path: /
              port: 80
            initialDelaySeconds: 10
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /
              port: 80
            initialDelaySeconds: 15
            periodSeconds: 10
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: netbird-management-storage
---
apiVersion: v1
kind: Service
metadata:
  name: netbird-management
  namespace: larakube-vpn
spec:
  selector:
    app: netbird-management
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: netbird-signal
  namespace: larakube-vpn
spec:
  replicas: 1
  selector:
    matchLabels:
      app: netbird-signal
  template:
    metadata:
      labels:
        app: netbird-signal
    spec:
      containers:
        - name: signal
          image: netbirdio/signal:latest
          ports:
            - containerPort: 80
              name: grpc
          readinessProbe:
            tcpSocket:
              port: 80
            initialDelaySeconds: 5
            periodSeconds: 5
---
apiVersion: v1
kind: Service
metadata:
  name: netbird-signal
  namespace: larakube-vpn
spec:
  selector:
    app: netbird-signal
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: netbird-relay
  namespace: larakube-vpn
spec:
  replicas: 1
  selector:
    matchLabels:
      app: netbird-relay
  template:
    metadata:
      labels:
        app: netbird-relay
    spec:
      containers:
        - name: relay
          image: netbirdio/coturn:latest
          ports:
            - containerPort: 3478
              name: turn
          readinessProbe:
            tcpSocket:
              port: 3478
            initialDelaySeconds: 5
            periodSeconds: 5
---
apiVersion: v1
kind: Service
metadata:
  name: netbird-relay
  namespace: larakube-vpn
spec:
  selector:
    app: netbird-relay
  ports:
    - protocol: UDP
      port: 3478
      targetPort: 3478
      name: turn-udp
    - protocol: TCP
      port: 3478
      targetPort: 3478
      name: turn-tcp
  type: ClusterIP
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: netbird-client
  namespace: larakube-vpn
spec:
  replicas: 1
  selector:
    matchLabels:
      app: netbird-client
  template:
    metadata:
      labels:
        app: netbird-client
    spec:
      containers:
        - name: client
          image: netbirdio/client:latest
          securityContext:
            capabilities:
              add: ["NET_ADMIN"]
          env:
            - name: NB_MANAGEMENT_URL
              value: "http://netbird-management:80"
---
@include('k8s.vpn.ingress')
