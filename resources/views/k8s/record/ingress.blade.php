@php
    // Every name comes from ToolInstance (ADR 0021).
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::RECORD->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::RECORD, $instance);
    $deploymentName = $names->deployment();
    $ingressLabels = '';
    foreach ($names->labels() as $key => $value) {
        $ingressLabels .= "\n    {$key}: {$value}";
    }
@endphp
@php
    // Middlewares compose — vpn-only and SSO each used to write this annotation
    // outright, so enabling both would silently drop one.
    $middlewares = [];
    if (($vpnOnly ?? false) && $names->vpnMiddleware() !== null) {
        $middlewares[] = $names->vpnMiddleware()->traefikMiddleware();
    }
    if ($ssoWired ?? false) {
        $middlewares[] = 'larakube-shared-sso-forwardauth@kubernetescrd';
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
@if($middlewares !== [])
    traefik.ingress.kubernetes.io/router.middlewares: {{ implode(',', $middlewares) }}
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
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
