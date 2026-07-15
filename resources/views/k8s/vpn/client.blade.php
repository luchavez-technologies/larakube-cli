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
