apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $config->getName() }}-statamic
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
    traefik.ingress.kubernetes.io/service.serversscheme: https
spec:
  rules:
@foreach($config->getWebHosts('local') as $host)
    - host: {{ $host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $config->getName() }}-statamic
                port:
                  number: 80
@endforeach
  tls:
    - hosts:
@foreach($config->getWebHosts('local') as $host)
        - {{ $host }}
@endforeach
