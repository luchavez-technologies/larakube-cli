apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: infisical
  namespace: larakube-secrets
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
                name: infisical-backend
                port:
                  number: 8080
  tls:
    - hosts:
        - {{ $host }}
