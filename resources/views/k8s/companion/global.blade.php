apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $companion->value }}
  namespace: larakube-companions
  labels:
    app.kubernetes.io/managed-by: larakube
    app.kubernetes.io/component: companion
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $companion->value }}
  template:
    metadata:
      labels:
        app: {{ $companion->value }}
    spec:
      containers:
        - name: {{ $companion->value }}
          image: {{ $companion->getImage() }}
          ports:
            - containerPort: {{ $companion->getPort() }}
              name: http
@foreach($companion->getAdditionalPorts() as $port)
            - containerPort: {{ $port['containerPort'] }}
              name: {{ $port['name'] }}
@endforeach
@if($companion->getEnv())
          env:
@foreach($companion->getEnv() as $key => $value)
            - name: {{ $key }}
              value: "{{ $value }}"
@endforeach
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $companion->value }}
  namespace: larakube-companions
  labels:
    app.kubernetes.io/managed-by: larakube
    app.kubernetes.io/component: companion
spec:
  selector:
    app: {{ $companion->value }}
  ports:
    - name: http
      port: 80
      targetPort: {{ $companion->getPort() }}
@foreach($companion->getAdditionalPorts() as $port)
    - name: {{ $port['name'] }}
      port: {{ $port['containerPort'] }}
      targetPort: {{ $port['containerPort'] }}
@endforeach
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $companion->value }}
  namespace: larakube-companions
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: {{ $companion->value }}.{{ $localTld }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $companion->value }}
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $companion->value }}.{{ $localTld }}
