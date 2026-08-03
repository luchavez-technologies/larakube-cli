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
                name: chat-cinny
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
