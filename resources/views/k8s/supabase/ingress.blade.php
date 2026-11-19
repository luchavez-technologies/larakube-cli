@php
    $tool = \App\Enums\ClusterTool::SUPABASE;
    $names = \App\Data\ToolInstance::forInstance($tool, $instance);
    $gatewayName = $names->deployment();
    $ingressName = $names->deployment();
    $vpnMiddleware = $names->name('vpn-only');
    $labels = '';
    foreach ($names->labels() as $k => $v) {
        $labels .= "\n    {$k}: {$v}";
    }
    $isLocal ??= false;
    $vpnOnly ??= false;
    $proxied ??= false;
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $ingressName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
{{-- Like every other Cluster Tool: a certificate either way, and --proxied only turns on Cloudflare's
     proxy for the record. Cloudflare in front still connects to this origin over HTTPS. --}}
@unless($isLocal)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@if($proxied)
    external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"
@endif
@endunless
@if($vpnOnly)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-{{ $vpnMiddleware }}@kubernetescrd
@endif
# No ingressClassName: LaraKube's Traefik serves class-less Ingresses and the cluster has no
# IngressClass, so naming one makes Traefik skip it. No route, no status address, no DNS record.
spec:
  tls:
    - hosts:
        - {{ $host }}
  rules:
    - host: {{ $host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $gatewayName }}
                port:
                  number: 8000
@if($vpnOnly)
---
apiVersion: traefik.io/v1alpha1
kind: Middleware
metadata:
  name: {{ $vpnMiddleware }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  ipAllowList:
    sourceRange:
      - 100.64.0.0/10
      - 127.0.0.1/32
@endif
