{{-- Reduced copy of chat-ingress used ONLY by the local-TLD `up` reconciler
     (InteractsWithTraefik::applySharedService(), always $isLocal=true, no
     $mas payload threaded through) to re-point the host on a config:tld
     change — NOT kept in lockstep with matrix.blade.php's MAS compat-
     endpoint carve-out. A local chat install with MAS-native auth already
     active would have that carve-out silently dropped on the next `up`
     until `chat:init` re-applies it. Low-stakes in practice — Element
     X/MAS needs a real public TLS domain, so this combination (local dev +
     active MAS auth) is expected to be rare — but a known, undone gap, not
     an oversight. --}}
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: chat-ingress
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
@if($vpnOnly ?? false)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-chat-vpn-only@kubernetescrd
@endif
spec:
  rules:
    - host: {{ $host }}
      http:
        paths:
          - path: /_matrix
            pathType: Prefix
            backend:
              service:
                name: chat-synapse
                port:
                  number: 8008
          - path: /_synapse
            pathType: Prefix
            backend:
              service:
                name: chat-synapse
                port:
                  number: 8008
          - path: /
            pathType: Prefix
            backend:
              service:
                name: chat-web
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
