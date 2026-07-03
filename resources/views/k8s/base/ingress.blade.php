apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $config->getServerVariation()->getPodName($config) }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
    traefik.ingress.kubernetes.io/service.serversscheme: {{ $config->getServerVariation()->traefikScheme() }}
@if($config->getIngress('local') !== \App\Enums\IngressController::TRAEFIK)
    # Local environment overrides Traefik defaults via overlay patches
@endif
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
                name: {{ $config->getServerVariation()->getPodName($config) }}
                port:
                  number: 80
@endforeach
  tls:
    - hosts:
@foreach($config->getWebHosts('local') as $host)
        - {{ $host }}
@endforeach
