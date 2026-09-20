@php
    // `up` reconciles this Ingress with only the host; git:init derives the
    // instance from the host the same way.
    $instance ??= \App\Enums\ClusterTool::GIT->instanceSlugFromHost($host);
    $tool = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::GIT, $instance);
    $ingressName = $tool->deployment('server');
    $httpServiceName = $tool->name('http', 'server');
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $ingressName }}
  labels:
@foreach($tool->labels('server') as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
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
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-forgejo-vpn-only-{{ $instance }}@kubernetescrd
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
                name: {{ $httpServiceName }}
                port:
                  number: 3000
  tls:
    - hosts:
        - {{ $host }}
