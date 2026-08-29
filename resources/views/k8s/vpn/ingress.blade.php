@php($sfx = ($instance ?? '') !== '' ? '-'.$instance : '')
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: vpn-management{{ $sfx }}
  namespace: larakube-vpn
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@unless($isLocal ?? false)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
{{-- Confirmed live 2026-08-24: an indented @if/@endif nested inside another
     directive leaks stray leading whitespace onto the next line once the
     outer directive closes — here it corrupted `spec:` below into a child of
     `annotations:`, making kubectl reject the whole Ingress (Traefik and
     every other resource in the same multi-doc apply had already succeeded,
     masking this as a single silent failure). Keep directive tags at column
     0; only literal YAML content should carry real indentation. --}}
@if($proxied ?? false)
    external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"
@endif
@endunless
spec:
  rules:
    - host: {{ $host }}
      http:
        paths:
          - path: /signalexchange.SignalExchange/
            pathType: Prefix
            backend:
              service:
                name: vpn-signal{{ $sfx }}
                port:
                  number: 80
          - path: /relay
            pathType: Prefix
            backend:
              service:
                name: vpn-relay{{ $sfx }}
                port:
                  number: 33080
          - path: /ws-proxy/
            pathType: Prefix
            backend:
              service:
                name: vpn-relay{{ $sfx }}
                port:
                  number: 33080
{{-- `/` belongs to the dashboard, so every path the management service owns
     must be listed EXPLICITLY here — before this change they were all covered
     by the catch-all. Missing one silently breaks peer connectivity rather
     than 404ing visibly: the gRPC prefixes below carry the client's control
     channel, and /oauth2 is the embedded IdP's own issuer (see
     management-config.blade.php). This list is NetBird's documented
     external-reverse-proxy contract for a multi-container deployment; do not
     trim it to the paths that happen to be exercised today. --}}
          - path: /management.ManagementService/
            pathType: Prefix
            backend:
              service:
                name: vpn-management{{ $sfx }}
                port:
                  number: 80
          - path: /management.ProxyService/
            pathType: Prefix
            backend:
              service:
                name: vpn-management{{ $sfx }}
                port:
                  number: 80
          - path: /api
            pathType: Prefix
            backend:
              service:
                name: vpn-management{{ $sfx }}
                port:
                  number: 80
          - path: /oauth2
            pathType: Prefix
            backend:
              service:
                name: vpn-management{{ $sfx }}
                port:
                  number: 80
          - path: /
            pathType: Prefix
            backend:
              service:
                name: vpn-dashboard{{ $sfx }}
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
