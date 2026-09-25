@php
    // Rendered both by dashboard:init (which passes these) and by the shared
    // ingress path (which passes only the host), so derive what is missing.
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::DASHBOARD->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names ??= \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::DASHBOARD, $instance);
    $labels ??= $names->labels();
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $names->deployment() }}
  namespace: larakube-shared
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
    traefik.ingress.kubernetes.io/router.middlewares: {{ $names->vpnMiddleware()->traefikMiddleware() }}
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
                  number: 4466
  tls:
    - hosts:
        - {{ $host }}
