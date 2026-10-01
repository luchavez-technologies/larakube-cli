@php
    // Rendered on its own by the shared-service reconcile (host only) as well
    // as included from shared.blade.php, so derive every name here.
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::CRM->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) $host));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::CRM, $instance);
    $serviceName = $ingressName = $names->deployment('server');
    $ingressLabels = '';
    foreach ($names->labels('server') as $key => $value) {
        $ingressLabels .= "\n    {$key}: {$value}";
    }
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $ingressName }}
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
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
