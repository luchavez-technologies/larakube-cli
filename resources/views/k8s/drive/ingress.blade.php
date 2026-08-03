apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: drive-{{ $engine }}
  namespace: larakube-shared
  labels:
    app: drive-{{ $engine }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
    # LaraKube's managed clusters serve TLS via Traefik's own letsencrypt
    # certresolver — cert-manager is NOT installed, so the old
    # `cert-manager.io/cluster-issuer` annotation was silently ignored and
    # Traefik fell back to its default *.dev.test certificate. Mirror the
    # pattern every other tool's ingress uses.
@unless($isLocal ?? false)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@if($proxied ?? false)
    external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"
@endif
@endunless
@if ($vpnOnly ?? false)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-drive-vpn-only@kubernetescrd
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
                name: drive-{{ $engine }}
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
