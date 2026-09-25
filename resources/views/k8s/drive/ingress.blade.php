@php
    // Shared by oCIS and by the Collabora editor, which serve different hosts
    // from different components of the same instance — hence $component. The
    // instance is derived when the shared reconcile path passes only a host,
    // and that derivation uses the TOOL's host, never the office one.
    $component = $component ?? null;
    $names ??= \App\Data\ToolInstance::forInstance(
        \App\Enums\ClusterTool::DRIVE,
        \App\Enums\ClusterTool::DRIVE->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) $host)),
    );
    $labels = $names->labels($component);
    $backend = $names->deployment($component);
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $backend }}
  namespace: {{ $names->namespace() }}
  labels:
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
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
    traefik.ingress.kubernetes.io/router.middlewares: {{ $names->vpnMiddleware()->traefikMiddleware() }}
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
                name: {{ $backend }}
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
