@php
    // Every name comes from ToolInstance (ADR 0021).
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::UPTIME->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::UPTIME, $instance);
    $deploymentName = $names->deployment();
    $ingressLabels = '';
    foreach ($names->labels() as $key => $value) {
        $ingressLabels .= "\n    {$key}: {$value}";
    }
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $deploymentName }}
  namespace: larakube-shared
  labels:{!! $ingressLabels !!}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@unless($isLocal ?? false)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@if($proxied ?? false)
    external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"
@endif
@endunless
@if(($vpnOnly ?? false) && $names->vpnMiddleware() !== null)
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
                name: {{ $deploymentName }}
                port:
                  number: 3001
  tls:
    - hosts:
        - {{ $host }}
