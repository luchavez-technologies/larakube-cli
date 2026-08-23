@php
    // Self-contained rather than trusting inherited scope: this partial is
    // also rendered standalone (SharedClusterService::MAIL's local-dev
    // re-point path via applySharedService(), which doesn't know about
    // $instance/$deploymentName at all) as well as @include'd from
    // stalwart.blade.php (which already computed these). Fall back to no
    // suffix — correct for the standalone caller today, since that path is
    // local-only and local installs aren't threaded through the instance
    // rename in this pass.
    $deploymentName ??= 'mail-stalwart'.((($instance ?? '') !== '') ? "-{$instance}" : '');
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $deploymentName }}
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
                name: {{ $deploymentName }}
                port:
                  number: 8080
@foreach($aliasHosts ?? [] as $aliasHost)
    - host: {{ $aliasHost }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $deploymentName }}
                port:
                  number: 8080
@endforeach
  tls:
    - hosts:
        - {{ $host }}
@foreach($aliasHosts ?? [] as $aliasHost)
        - {{ $aliasHost }}
@endforeach
