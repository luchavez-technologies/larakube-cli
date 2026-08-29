@php
    $instance = $instance ?? (isset($host) && $host ? \App\Enums\ClusterTool::LINK->instanceSlugFromHost($host) : 'link');
    $linkIngressName = "link-{$instance}";
    $linkServiceName = "link-kutt-{$instance}";
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $linkIngressName }}
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
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-link-vpn-only@kubernetescrd
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
                name: {{ $linkServiceName }}
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
