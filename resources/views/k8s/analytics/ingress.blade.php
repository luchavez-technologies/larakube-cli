@php
    $names ??= \App\Data\ToolInstance::forHost(\App\Enums\ClusterTool::ANALYTICS, $host);
    $labels ??= $names->labels();
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $names->deployment() }}
  namespace: {{ $names->namespace() }}
  labels:
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@unless($isLocal ?? false)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@if($proxied ?? false)
    external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"
@endif
@endunless
@if($vpnOnly ?? false)
    traefik.ingress.kubernetes.io/router.middlewares: {{ $names->namespace() }}-{{ $names->name('vpn-only') }}@kubernetescrd
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
                name: {{ $names->deployment() }}
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
