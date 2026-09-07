{{-- Rendered per environment (and for the preview overlay). $hosts and
     $resourceName are supplied by generateNextjsManifests; the dev-server
     overlay has its own `web` Ingress and does not use this one. --}}
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $resourceName }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
    traefik.ingress.kubernetes.io/service.serversscheme: http
spec:
  rules:
@foreach($hosts as $host)
    - host: {{ $host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $resourceName }}
                port:
                  number: 3000
@endforeach
  tls:
    - hosts:
@foreach($hosts as $host)
        - {{ $host }}
@endforeach
