@php
    // Middlewares compose — vpn-only and SSO each used to write this annotation
    // outright, so enabling both would silently drop one.
    $middlewares = [];
    if ($vpnOnly ?? false) {
        $middlewares[] = 'larakube-shared-record-vpn-only@kubernetescrd';
    }
    if ($ssoWired ?? false) {
        $middlewares[] = 'larakube-shared-sso-forwardauth@kubernetescrd';
    }
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: record
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
                name: record
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
