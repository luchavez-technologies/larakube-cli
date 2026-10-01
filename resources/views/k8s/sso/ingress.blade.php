@php
    // Rendered by zitadel.blade.php and on its own by the local-dev re-point
    // path (SharedClusterService::SSO), so derive every name here.
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::SSO->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) $host));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::SSO, $instance);
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
  namespace: larakube-sso
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
                  number: 8080
  tls:
    - hosts:
        - {{ $host }}
