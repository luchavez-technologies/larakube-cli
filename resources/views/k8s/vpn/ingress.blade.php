apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: netbird-management
  namespace: larakube-vpn
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: {{ $host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: netbird-management
                port:
                  number: 80
  tls:
    - hosts:
        - {{ $host }}
