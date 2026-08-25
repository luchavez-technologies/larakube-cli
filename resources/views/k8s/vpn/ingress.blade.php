apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: netbird-management
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
                name: netbird-signal
                port:
                  number: 80
          - path: /relay
            pathType: Prefix
            backend:
              service:
                name: netbird-relay
                port:
                  number: 33080
          - path: /ws-proxy/
            pathType: Prefix
            backend:
              service:
                name: netbird-relay
                port:
                  number: 33080
          - path: /
            pathType: Prefix
            backend:
              service:
                name: netbird-management
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
