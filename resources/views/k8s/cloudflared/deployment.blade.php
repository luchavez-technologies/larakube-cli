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
            - --token
            - {{ $token }}
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
