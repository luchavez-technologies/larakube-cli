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
          image: netbirdio/management:0.74.4
          env:
            - name: NB_SETUP_PAT_ENABLED
              value: "true"
          ports:
            - containerPort: 80
              name: http
          volumeMounts:
            - name: storage
              mountPath: /var/lib/netbird
            - name: config
              mountPath: /etc/netbird/management.json
              subPath: management.json
          readinessProbe:
            tcpSocket:
              port: 80
            initialDelaySeconds: 10
            periodSeconds: 5
          livenessProbe:
            tcpSocket:
              port: 80
            initialDelaySeconds: 15
            periodSeconds: 10
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: netbird-management-storage
        - name: config
          secret:
            secretName: netbird-relay-secret
---
apiVersion: v1
kind: Service
metadata:
  name: netbird-management
  namespace: larakube-vpn
  annotations:
    traefik.ingress.kubernetes.io/service.serversscheme: h2c
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
          image: netbirdio/signal:0.74.4
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
  annotations:
    traefik.ingress.kubernetes.io/service.serversscheme: h2c
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
          image: netbirdio/relay:0.74.4
          env:
            - name: NB_LOG_LEVEL
              value: "info"
            - name: NB_LISTEN_ADDRESS
              value: ":33080"
            - name: NB_EXPOSED_ADDRESS
              value: "rels://{{ $host }}:443/relay"
            - name: NB_AUTH_SECRET
              valueFrom:
                secretKeyRef:
                  name: netbird-relay-secret
                  key: relay-secret
          ports:
            - containerPort: 33080
              name: http
          readinessProbe:
            tcpSocket:
              port: 33080
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
    - protocol: TCP
      port: 33080
      targetPort: 33080
  type: ClusterIP
---
@include('k8s.vpn.ingress')
