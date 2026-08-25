apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $name }}-spa-ingress
  namespace: {{ $namespace }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
{{-- Confirmed live 2026-08-24 (same bug, chat/vpn Ingress): an indented
     @if directive leaks its own leading whitespace onto the next line once
     it closes — here it would corrupt `spec:` below into a child of
     `annotations:`. Keep directive tags at column 0; only literal YAML
     content is indented. --}}
@if(!$isLocal)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
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
                name: {{ $s3ServiceName }}
                port:
                  number: {{ $s3Port }}
  tls:
    - hosts:
        - {{ $host }}
