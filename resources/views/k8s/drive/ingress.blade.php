apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: drive-{{ $engine }}
  namespace: larakube-shared
  labels:
    app: drive-{{ $engine }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
    cert-manager.io/cluster-issuer: "letsencrypt"
@if ($vpnOnly ?? false)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-drive-vpn-only@kubernetescrd
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
                name: drive-{{ $engine }}
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
      secretName: drive-{{ $engine }}-tls
