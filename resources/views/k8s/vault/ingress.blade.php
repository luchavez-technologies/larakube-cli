@php
    $instance = $instance ?? (isset($host) && $host ? \App\Enums\ClusterTool::PASSWORDS->instanceSlugFromHost($host) : 'vault');
    $ingressName = "passwords-vaultwarden-{$instance}";
    $serviceName = "passwords-vaultwarden-{$instance}";
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $ingressName }}
  namespace: larakube-vault
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
    traefik.ingress.kubernetes.io/router.middlewares: larakube-vault-vault-vpn-only@kubernetescrd
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
                name: {{ $serviceName }}
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
