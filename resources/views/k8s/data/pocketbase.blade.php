apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deployName }}
  namespace: {{ $namespace }}
  labels:
    app.kubernetes.io/name: {{ $deployName }}
    app.kubernetes.io/instance: {{ $instance }}
    app.kubernetes.io/component: data
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app.kubernetes.io/name: {{ $deployName }}
  template:
    metadata:
      labels:
        app.kubernetes.io/name: {{ $deployName }}
        app.kubernetes.io/instance: {{ $instance }}
    spec:
      containers:
        - name: pocketbase
          image: ghcr.io/pocketbase/pocketbase:0.23.1
          imagePullPolicy: IfNotPresent
          command:
            - /bin/sh
            - -c
            - |
              if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASSWORD" ]; then
                /pocketbase superuser upsert "$ADMIN_EMAIL" "$ADMIN_PASSWORD" --dir=/pb_data || true
              fi
              exec /pocketbase serve --http=0.0.0.0:8090 --dir=/pb_data
          ports:
            - containerPort: 8090
              name: http
          env:
            - name: ADMIN_EMAIL
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: admin-email
            - name: ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: admin-password
            - name: AWS_ACCESS_KEY_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-key
            - name: AWS_SECRET_ACCESS_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-secret
          volumeMounts:
            - name: pb-data
              mountPath: /pb_data
          resources:
            requests:
              cpu: 50m
              memory: 32Mi
            limits:
              cpu: 500m
              memory: 256Mi
          livenessProbe:
            httpGet:
              path: /api/health
              port: 8090
            initialDelaySeconds: 5
            periodSeconds: 10
          readinessProbe:
            httpGet:
              path: /api/health
              port: 8090
            initialDelaySeconds: 5
            periodSeconds: 10
      volumes:
        - name: pb-data
          persistentVolumeClaim:
            claimName: {{ $pvcName }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $deployName }}
  namespace: {{ $namespace }}
  labels:
    app.kubernetes.io/name: {{ $deployName }}
spec:
  type: ClusterIP
  ports:
    - port: 8090
      targetPort: 8090
      name: http
  selector:
    app.kubernetes.io/name: {{ $deployName }}
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $deployName }}-ingress
  namespace: {{ $namespace }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
    @if(!$isLocal)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
    @endif
    @if($vpnOnly)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-vpn-only@kubernetescrd
    @endif
spec:
  rules:
    - host: {{ $host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $deployName }}
                port:
                  number: 8090
  tls:
    - hosts:
        - {{ $host }}
