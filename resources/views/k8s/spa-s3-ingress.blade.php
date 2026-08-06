apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $name }}-spa-ingress
  namespace: {{ $namespace }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
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
