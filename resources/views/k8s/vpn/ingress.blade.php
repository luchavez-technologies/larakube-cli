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
