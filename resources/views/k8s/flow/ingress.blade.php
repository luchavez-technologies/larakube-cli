@php
    $names ??= \App\Data\ToolInstance::forHost(\App\Enums\ClusterTool::FLOW, $host, $engine ?? 'n8n');
    $servicePort ??= ($names->engine === 'windmill' ? 8000 : 5678);
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $names->deployment() }}
  namespace: {{ $names->namespace() }}
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
    traefik.ingress.kubernetes.io/router.middlewares: {{ $names->namespace() }}-{{ $names->vpnMiddleware()->name }}@kubernetescrd
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
                  number: {{ $servicePort }}
  tls:
    - hosts:
        - {{ $host }}
