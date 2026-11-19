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
@if(! $isLocal && ! $proxied)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@endif
@if($vpnOnly)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-{{ $vpnMiddleware }}@kubernetescrd
@endif
spec:
  ingressClassName: traefik
@if(! $proxied)
  tls:
    - hosts:
        - {{ $host }}
@endif
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
