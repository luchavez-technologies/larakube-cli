apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: stalwart
  namespace: larakube-shared
  labels:
    app: stalwart
spec:
  serviceName: stalwart
  replicas: 1
  selector:
    matchLabels:
      app: stalwart
  template:
    metadata:
      labels:
        app: stalwart
    spec:
      securityContext:
        # The image runs as the unprivileged 'stalwart' user (UID 2000); the
        # mounted data/config volumes must be group-writable by it.
        fsGroup: 2000
      containers:
        - name: stalwart
          image: stalwartlabs/stalwart:latest
          env:
            # Fixed recovery/admin credential (username:password) so /admin has a
            # known login instead of the random one printed to the pod logs on
            # first boot. Honored during normal operation too.
            - name: STALWART_RECOVERY_ADMIN
              valueFrom:
                secretKeyRef:
                  name: mail-secrets
                  key: recovery-admin
            # Public base URL for JMAP/OAuth discovery — required behind a proxy.
            - name: STALWART_PUBLIC_URL
              value: https://{{ $host }}
          ports:
            - { containerPort: 8080, name: http }
            - { containerPort: 25, name: smtp }
            - { containerPort: 587, name: submission }
            - { containerPort: 465, name: submissions }
            - { containerPort: 993, name: imaps }
            - { containerPort: 4190, name: sieve }
          readinessProbe:
            tcpSocket:
              port: 8080
            initialDelaySeconds: 10
            periodSeconds: 5
          livenessProbe:
            tcpSocket:
              port: 8080
            initialDelaySeconds: 20
            periodSeconds: 15
          volumeMounts:
            # Self-contained: embedded RocksDB store on the PVC. One claim serves
            # both Stalwart's writable config dir (/etc/stalwart) and its data
            # dir (/var/lib/stalwart) via subPaths — no Commons, no ConfigMap.
            - name: stalwart-data
              mountPath: /var/lib/stalwart
              subPath: data
            - name: stalwart-data
              mountPath: /etc/stalwart
              subPath: etc
  volumeClaimTemplates:
    - metadata:
        name: stalwart-data
      spec:
        accessModes:
          - ReadWriteOnce
        resources:
          requests:
            storage: 5Gi
---
# HTTP admin + JMAP, fronted by Traefik (TLS terminated at the ingress).
apiVersion: v1
kind: Service
metadata:
  name: stalwart
  namespace: larakube-shared
spec:
  selector:
    app: stalwart
  ports:
    - { protocol: TCP, port: 8080, targetPort: 8080, name: http }
  type: ClusterIP
---
# L4 mail listeners. On single-node k3s the built-in ServiceLB (klipper) binds
# these straight to the node's IP — no paid cloud load balancer. These ports do
# not collide with Traefik's 80/443.
apiVersion: v1
kind: Service
metadata:
  name: stalwart-mail
  namespace: larakube-shared
spec:
  selector:
    app: stalwart
  ports:
    - { protocol: TCP, port: 25, targetPort: 25, name: smtp }
    - { protocol: TCP, port: 587, targetPort: 587, name: submission }
    - { protocol: TCP, port: 465, targetPort: 465, name: submissions }
    - { protocol: TCP, port: 993, targetPort: 993, name: imaps }
    - { protocol: TCP, port: 4190, targetPort: 4190, name: sieve }
  type: LoadBalancer
---
@include('k8s.mail.ingress')
