{{-- Local HMR. The project source is bind-mounted so edits are instant, but
     node_modules is a PVC mounted OVER it: Vite 8 ships Rolldown, a native
     binary, so the host's darwin-arm64 install cannot execute in a linux
     container. A PVC rather than an emptyDir so the install survives restarts.
     Blade @if directives stay at column 0 — an indented one leaks its own
     leading whitespace onto the next line and corrupts the following key. --}}
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-node-modules
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
  name: web
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: web
  template:
    metadata:
      labels:
        app: web
    spec:
      initContainers:
        - name: install
          image: node:24-alpine
          workingDir: /app
          command: ["sh", "-c", "{{ $config->getPackageManager()->installCommand() }}"]
          volumeMounts:
            - name: source
              mountPath: /app
            - name: node-modules
              mountPath: /app/node_modules
      containers:
        - name: dev
          image: node:24-alpine
          workingDir: /app
          command: ["sh", "-c", "{{ $config->getPackageManager()->devCommand() }}"]
          ports:
            - containerPort: {{ $devPort }}
          readinessProbe:
            tcpSocket:
              port: {{ $devPort }}
            initialDelaySeconds: 5
            periodSeconds: 5
          volumeMounts:
            - name: source
              mountPath: /app
            - name: node-modules
              mountPath: /app/node_modules
      volumes:
        - name: source
          hostPath:
            path: {{ $config->getPath() }}
            type: Directory
        - name: node-modules
          persistentVolumeClaim:
            claimName: {{ $config->getName() }}-node-modules
---
apiVersion: v1
kind: Service
metadata:
  name: web
spec:
  selector:
    app: web
  ports:
    - protocol: TCP
      port: {{ $devPort }}
      targetPort: {{ $devPort }}
  type: ClusterIP
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: web
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
                name: web
                port:
                  number: {{ $devPort }}
  tls:
    - hosts:
        - {{ $host }}
