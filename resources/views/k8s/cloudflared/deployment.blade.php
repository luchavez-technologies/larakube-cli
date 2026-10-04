@if(!empty($token))
apiVersion: v1
kind: Secret
metadata:
  name: {{ $name }}-token
  namespace: {{ $namespace }}
  labels:
    app.kubernetes.io/managed-by: larakube
    larakube.dev/role: share
type: Opaque
stringData:
  TUNNEL_TOKEN: {!! json_encode($token) !!}
---
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $name }}
  namespace: {{ $namespace }}
  labels:
    app.kubernetes.io/managed-by: larakube
    larakube.dev/role: share
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $name }}
  template:
    metadata:
      labels:
        app: {{ $name }}
        larakube.dev/role: share
    spec:
      containers:
        - name: cloudflared
          image: cloudflare/cloudflared:2026.7.3
@if(!empty($token))
          args:
            - tunnel
            - --no-autoupdate
            - run
          env:
            - name: TUNNEL_TOKEN
              valueFrom:
                secretKeyRef:
                  name: {{ $name }}-token
                  key: TUNNEL_TOKEN
@else
          args:
            - tunnel
            - --url
            - {{ $targetUrl }}
            - --no-autoupdate
@endif
          resources:
            requests:
              cpu: 10m
              memory: 32Mi
            limits:
              cpu: 100m
              memory: 64Mi
      restartPolicy: Always
