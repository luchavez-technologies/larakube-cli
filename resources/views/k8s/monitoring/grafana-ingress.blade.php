@php
    $instance = $instance ?? (isset($host) && $host ? \App\Enums\ClusterTool::MONITOR->instanceSlugFromHost($host) : 'monitor');
    $ingressName = "grafana-{$instance}";
    $serviceName = "grafana-{$instance}";
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::MONITOR, $instance);
    $labels = $names->labels('grafana');
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $ingressName }}
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
                name: {{ $serviceName }}
                port:
                  number: 3000
  tls:
    - hosts:
        - {{ $host }}
