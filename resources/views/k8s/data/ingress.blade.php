apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $ingressName ?? 'data' }}
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
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-data-vpn-only@kubernetescrd
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
                name: {{ $serviceName ?? 'data' }}
                port:
                  number: 80
@foreach($aliasHosts ?? [] as $aliasHost)
    - host: {{ $aliasHost }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $serviceName ?? 'data' }}
                port:
                  number: 80
@endforeach
  tls:
    - hosts:
        - {{ $host }}
@foreach($aliasHosts ?? [] as $aliasHost)
        - {{ $aliasHost }}
@endforeach
