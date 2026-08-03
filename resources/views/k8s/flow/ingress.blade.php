@php
    $svcName = ($engine ?? 'n8n') === 'windmill' ? 'flow-windmill' : 'flow-n8n';
    $svcPort = ($engine ?? 'n8n') === 'windmill' ? 8000 : 5678;
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $svcName }}
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
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-flow-vpn-only@kubernetescrd
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
                name: {{ $svcName }}
                port:
                  number: {{ $svcPort }}
  tls:
    - hosts:
        - {{ $host }}
