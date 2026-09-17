{{-- No ACME on the local preview: its .test host is served with the LaraKube
     Local CA leaf instead. --}}
@php($port = $config->framework->containerPort())
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $resourceName }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@if($environment !== 'local')
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
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
                  number: {{ $port }}
@endforeach
  tls:
    - hosts:
@foreach($hosts as $host)
        - {{ $host }}
@endforeach
