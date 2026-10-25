# The hot-file URL is sourced from vite.config.js#server.origin (the only lever
# laravel-vite-plugin honors). Do not add VITE_DEV_SERVER_URL here — the plugin
# does not read it. Liveness probes are intentionally omitted: a SIGTERM to the
# dev server triggers the plugin's cleanup handler and deletes public/hot.
#
# node_modules is a PVC mounted OVER the hostPath source, not bind-mounted from
# the host: a native-binary package (e.g. Vite's Rolldown/vite-plus) installed
# on the host for darwin-arm64 cannot execute inside this linux/alpine
# container. A PVC (not emptyDir) so the install survives pod restarts instead
# of re-running on every `up`. Mirrors resources/views/k8s/static/dev-server.blade.php,
# which solved the identical problem for standalone JS frameworks first.
---
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
  name: node
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: node
  template:
    metadata:
      labels:
        app: node
    spec:
      initContainers:
        - name: install
          image: {{ $config->getName() }}:latest
          workingDir: /var/www/html
          command: ["sh", "-c", "{{ $config->getPackageManager()->installCommand() }}"]
          envFrom:
            - configMapRef:
                name: laravel-config
          volumeMounts:
            - name: code
              mountPath: /var/www/html
            - name: node-modules
              mountPath: /var/www/html/node_modules
      containers:
        - name: node
          image: {{ $config->getName() }}:latest
          workingDir: /var/www/html
          command: {!! $config->getPackageManager()->getReadinessProbeCommand() !!}
          ports:
            - containerPort: 5173
          envFrom:
            - configMapRef:
                name: laravel-config
          readinessProbe:
            tcpSocket:
              port: 5173
            initialDelaySeconds: 5
            periodSeconds: 5
          volumeMounts:
            - name: code
              mountPath: /var/www/html
            - name: node-modules
              mountPath: /var/www/html/node_modules
      volumes:
        - name: code
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
  name: node
spec:
  selector:
    app: node
  ports:
    - protocol: TCP
      port: 5173
      targetPort: 5173
  type: ClusterIP
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: node
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: vite.{{ $config->getName() }}.{{ $config->getLocalTld() }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: node
                port:
                  number: 5173
  tls:
    - hosts:
        - vite.{{ $config->getName() }}.{{ $config->getLocalTld() }}
