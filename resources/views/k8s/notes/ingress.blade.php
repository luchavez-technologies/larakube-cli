@php
    // Rendered both by notes:init (which passes these) and by the shared
    // ingress path (which passes only the host), so derive what is missing.
    $instance = $instance ?? (isset($host) && $host ? \App\Enums\ClusterTool::NOTES->instanceSlugFromHost($host) : null);
    $names = $instance ? \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::NOTES, $instance) : null;
    $serviceName = $serviceName ?? ($names?->deployment() ?? 'notes');
    $labels = $labels ?? ($names?->labels() ?? []);
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $serviceName }}
  labels:
@foreach($labels ?? [] as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
  namespace: larakube-shared
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
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-notes-vpn-only@kubernetescrd
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
                name: {{ $serviceName ?? 'notes' }}
                port:
                  number: 80
@foreach($aliasHosts ?? [] as $aliasHost)
    - host: {{ $aliasHost }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $serviceName ?? 'notes' }}
                port:
                  number: 80
@endforeach
  tls:
    - hosts:
        - {{ $host }}
@foreach($aliasHosts ?? [] as $aliasHost)
        - {{ $aliasHost }}
@endforeach
