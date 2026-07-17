apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: stalwart
  namespace: larakube-shared
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@unless($isLocal ?? false)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@endunless
@if($vpnOnly ?? false)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-mail-vpn-only@kubernetescrd
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
                name: stalwart
                port:
                  number: 8080
  tls:
    - hosts:
        - {{ $host }}
