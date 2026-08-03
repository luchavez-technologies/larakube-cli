---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: netbird-client-data
  namespace: larakube-vpn
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 128Mi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: netbird-client
  namespace: larakube-vpn
spec:
  replicas: 1
  strategy:
    type: Recreate
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
          image: netbirdio/netbird:0.74.4
          securityContext:
            capabilities:
              add: ["NET_ADMIN"]
          env:
            - name: NB_MANAGEMENT_URL
              value: "http://netbird-management:80"
            - name: NB_SETUP_KEY
              valueFrom:
                secretKeyRef:
                  name: netbird-admin
                  key: setup-key
          volumeMounts:
            - name: data
              mountPath: /etc/netbird
        - name: ingress-proxy
          image: alpine/socat:1.8.1.3
          command: ["/bin/sh", "-c"]
          args:
            - |
              HOST="traefik.traefik.svc.cluster.local"
              nslookup $HOST > /dev/null 2>&1 || HOST="traefik.kube-system.svc.cluster.local"
              socat TCP-LISTEN:80,fork,reuseaddr TCP:$HOST:80 &
              socat TCP-LISTEN:443,fork,reuseaddr TCP:$HOST:443 &
              wait
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: netbird-client-data
