---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: mailpit
  namespace: larakube-shared
spec:
  replicas: 1
  selector:
    matchLabels:
      app: mailpit
  template:
    metadata:
      labels:
        app: mailpit
    spec:
      containers:
        - name: mailpit
          image: axllent/mailpit:v1.30.5
          ports:
            - containerPort: 8025
              name: ui
            - containerPort: 1025
              name: smtp
          env:
            - name: MP_SMTP_BIND_ADDR
              value: "0.0.0.0:1025"
            - name: MP_UI_BIND_ADDR
              value: "0.0.0.0:8025"
          readinessProbe:
            httpGet:
              path: /
              port: 8025
            initialDelaySeconds: 2
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /
              port: 8025
            initialDelaySeconds: 5
            periodSeconds: 10
---
apiVersion: v1
kind: Service
metadata:
  name: mailpit
  namespace: larakube-shared
spec:
  selector:
    app: mailpit
  ports:
    - name: ui
      protocol: TCP
      port: 8025
      targetPort: 8025
    - name: smtp
      protocol: TCP
      port: 1025
      targetPort: 1025
  type: ClusterIP
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: mailpit
  namespace: larakube-shared
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: {{ $host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: mailpit
                port:
                  number: 8025
  tls:
    - hosts:
        - {{ $host }}
