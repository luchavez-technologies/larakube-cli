apiVersion: apps/v1
kind: Deployment
metadata:
  name: larakube-tunnel
  namespace: {{ $namespace }}
  labels:
    app.kubernetes.io/managed-by: larakube
    {{ \App\Support\Labels::ROLE }}: tunnel
    {{ \App\Support\Labels::PROVIDER }}: {{ $provider->value }}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: larakube-tunnel
  template:
    metadata:
      labels:
        app: larakube-tunnel
        {{ \App\Support\Labels::ROLE }}: tunnel
    spec:
      containers:
        - name: tunnel
          image: {{ $provider->getImage() }}
@if($provider === \App\Enums\TunnelProvider::CLOUDFLARE)
          args:
@foreach($provider->getArgs() as $arg)
            - {{ $arg }}
@endforeach
          livenessProbe:
            httpGet:
              path: /ready
              port: 2000
            initialDelaySeconds: 15
            periodSeconds: 30
            failureThreshold: 3
@else
          command:
@foreach($provider->getArgs() as $arg)
            - {{ $arg }}
@endforeach
@endif
          env:
            - name: TUNNEL_TOKEN
              valueFrom:
                secretKeyRef:
                  name: larakube-tunnel-secret
                  key: TOKEN
          resources:
            requests:
              cpu: 10m
              memory: 32Mi
            limits:
              cpu: 100m
              memory: 64Mi
      restartPolicy: Always
