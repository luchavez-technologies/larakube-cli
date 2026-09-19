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
{{-- Certificates, same rules as the Laravel ingress: Traefik's ACME resolver on
     a single-node cluster that can reach Let's Encrypt, cert-manager when the
     environment names an issuer. Preview renders as local (LaraKube Local CA). --}}
@if($environment !== 'local')
@if($config->getStrategy($environment) === \App\Enums\DeploymentStrategy::SINGLE_NODE && !($config->environments[$environment]?->offline ?? false))
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@endif
@if($config->environments[$environment]?->certManagerIssuer)
    cert-manager.io/cluster-issuer: {{ $config->environments[$environment]->certManagerIssuer }}
@endif
@endif
@if($environment !== 'local' && ($extraAnnotations = $config->getIngressAnnotations($environment)))
@foreach($extraAnnotations as $key => $value)
    {{ $key }}: {!! json_encode($value) !!}
@endforeach
@endif
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
