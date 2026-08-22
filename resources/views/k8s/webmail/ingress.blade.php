@php($suffix = ($instance ?? '') !== '' ? "-{$instance}" : '')
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: webmail-bulwark{{ $suffix }}
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
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-webmail-vpn-only{{ $suffix }}@kubernetescrd
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
                name: webmail-bulwark{{ $suffix }}
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
