@php
    // Middlewares compose — vpn-only used to write this annotation outright, so
    // enabling it alongside anything else would silently drop one.
    $middlewares = [];
    if ($vpnOnly ?? false) {
        $middlewares[] = 'larakube-shared-meet-vpn-only@kubernetescrd';
    }
    // Traefik applies an Ingress annotation's middlewares to every router the
    // Ingress generates, so this also lands on the "/" (LiveKit) router — where
    // it is a no-op, because stripPrefix only fires on a matching prefix.
    if ($jwtWired ?? false) {
        $middlewares[] = 'larakube-shared-meet-jwt-stripprefix@kubernetescrd';
    }
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: meet
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
@if($middlewares !== [])
    traefik.ingress.kubernetes.io/router.middlewares: {{ implode(',', $middlewares) }}
@endif
spec:
  rules:
    - host: {{ $host }}
      http:
        paths:
@if($jwtWired ?? false)
{{-- More specific first: Traefik sorts rules by path length, but declaring the
     bridge ahead of the catch-all keeps the intent readable. The stripprefix
     middleware is owned by the lk-jwt manifest that meet:wire applies. --}}
          - path: /jwt
            pathType: Prefix
            backend:
              service:
                name: meet-lk-jwt
                port:
                  number: 8080
@endif
{{-- LiveKit expects to be mounted at root: /rtc for the browser WebSocket and
     /twirp/... for the server-side room admin API its SDKs call. --}}
          - path: /
            pathType: Prefix
            backend:
              service:
                name: meet-livekit
                port:
                  number: 7880
  tls:
    - hosts:
        - {{ $host }}
